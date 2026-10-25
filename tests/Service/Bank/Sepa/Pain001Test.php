<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank\Sepa;

use App\Service\Bank\Sepa\Pain001;
use App\Service\Bank\Sepa\Pain001Auftrag;
use App\Service\Bank\Sepa\Pain001Ueberweisung;
use App\Service\Bank\Sepa\SepaText;
use PHPUnit\Framework\TestCase;

/**
 * The pain.001.001.09 file for a payment run (issue #85, E-42): valid
 * against the official ISO 20022 schema (tests/fixtures/sepa/), plus the
 * rules of the German banking industry the schema does not carry
 * (character set, lengths, EUR, SLEV, service level SEPA).
 */
final class Pain001Test extends TestCase
{
    private const string VEREIN = 'DE89370400440532013000';

    private const string ANNA = 'DE02120300000000202051';

    private const string BERT = 'DE12500105170648489890';

    public function testTheFileIsValidAgainstTheOfficialSchema(): void
    {
        $xml = Pain001::xml($this->auftrag());

        self::assertTrue(self::gueltig($xml), implode("\n", self::fehler($xml)));
    }

    public function testTheFileWithoutBicIsValidToo(): void
    {
        $xml = Pain001::xml($this->auftrag(bic: ''));

        self::assertTrue(self::gueltig($xml), implode("\n", self::fehler($xml)));
        self::assertStringContainsString('<Othr><Id>NOTPROVIDED</Id></Othr>', (string) preg_replace('/>\s+</', '><', $xml));
    }

    public function testTheFileCarriesSumsCountAndTheGermanRules(): void
    {
        $doc = new \DOMDocument();
        $doc->loadXML(Pain001::xml($this->auftrag()));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('p', Pain001::NAMESPACE);
        $wert = static fn(string $pfad): string => (string) $xpath->evaluate('string(' . $pfad . ')');

        self::assertSame('2', $wert('//p:GrpHdr/p:NbOfTxs'));
        self::assertSame('1234.57', $wert('//p:GrpHdr/p:CtrlSum'), '1234.56 + 0.01');
        self::assertSame('1234.57', $wert('//p:PmtInf/p:CtrlSum'));
        self::assertSame('2026-10-24T10:15:00', $wert('//p:GrpHdr/p:CreDtTm'));
        self::assertSame('2026-10-26', $wert('//p:ReqdExctnDt/p:Dt'));
        self::assertSame('TRF', $wert('//p:PmtMtd'));
        self::assertSame('false', $wert('//p:BtchBookg'), 'every transfer booked on its own, so the matching finds it');
        self::assertSame('SEPA', $wert('//p:PmtTpInf/p:SvcLvl/p:Cd'));
        self::assertSame('SLEV', $wert('//p:PmtInf/p:ChrgBr'));
        self::assertSame(self::VEREIN, $wert('//p:DbtrAcct/p:Id/p:IBAN'));
        self::assertSame('COBADEFFXXX', $wert('//p:DbtrAgt/p:FinInstnId/p:BICFI'));
        self::assertSame('1234.56', $wert('//p:CdtTrfTxInf[1]/p:Amt/p:InstdAmt'));
        self::assertSame('EUR', $wert('//p:CdtTrfTxInf[1]/p:Amt/p:InstdAmt/@Ccy'));
        self::assertSame('0.01', $wert('//p:CdtTrfTxInf[2]/p:Amt/p:InstdAmt'));
        self::assertSame('Anna Mueller', $wert('//p:CdtTrfTxInf[1]/p:Cdtr/p:Nm'));
        self::assertSame(self::ANNA, $wert('//p:CdtTrfTxInf[1]/p:CdtrAcct/p:Id/p:IBAN'));
        self::assertSame('VB-Z7-1', $wert('//p:CdtTrfTxInf[1]/p:PmtId/p:EndToEndId'));
        self::assertSame('Auslagenerstattung Belege 12, 15', $wert('//p:CdtTrfTxInf[1]/p:RmtInf/p:Ustrd'));
    }

    public function testAmountsAreIntegerCentsAsDecimal(): void
    {
        self::assertSame('0.05', Pain001::betrag(5));
        self::assertSame('12.30', Pain001::betrag(1230));
        self::assertSame('999999999.99', Pain001::betrag(Pain001::MAX_CENT));
    }

    public function testTextIsTransliteratedToTheSepaCharacterSet(): void
    {
        self::assertSame('Juergen Mueller-Oesterle', SepaText::bereinigen('Jürgen Müller-Österle', 70));
        self::assertSame('Strasse', SepaText::bereinigen('Straße', 70));
        self::assertSame('Francois Lefevre', SepaText::bereinigen('François Lefèvre', 70));
        self::assertSame('TSV + Co. Beleg 12', SepaText::bereinigen("TSV & Co.\tBeleg #12", 70));
        self::assertSame('Lukasz Zolnierz', SepaText::bereinigen('Łukasz Żołnierz', 70));
        self::assertSame('', SepaText::bereinigen('日本', 70), 'nothing usable left');
        self::assertSame('Anna', SepaText::bereinigen('Anna Beispielname', 5), 'cut to the length, no trailing space');
        self::assertTrue(SepaText::istZulaessig(SepaText::bereinigen('€ 5 – „gut“; ok!', 140)));
    }

    public function testReferencesFollowTheSlashRules(): void
    {
        self::assertTrue(SepaText::istReferenz('VB-Z7-1'));
        self::assertFalse(SepaText::istReferenz('/VB'));
        self::assertFalse(SepaText::istReferenz('VB/'));
        self::assertFalse(SepaText::istReferenz('V//B'));
        self::assertFalse(SepaText::istReferenz(str_repeat('A', 36)));
        self::assertFalse(SepaText::istReferenz(''));
    }

    /**
     * @return iterable<string, array{\Closure(self): Pain001Auftrag, string}>
     */
    public static function ungueltigeAuftraege(): iterable
    {
        yield 'no transfer' => [static fn(self $t): Pain001Auftrag => $t->auftrag(ueberweisungen: []), 'keine Überweisung'];
        yield 'payer IBAN' => [static fn(self $t): Pain001Auftrag => $t->auftrag(iban: 'DE00370400440532013000'), 'Auftraggeberkontos ist ungültig'];
        yield 'BIC' => [static fn(self $t): Pain001Auftrag => $t->auftrag(bic: 'COBA'), 'BIC'];
        yield 'payer name too long' => [static fn(self $t): Pain001Auftrag => $t->auftrag(auftraggeber: str_repeat('A', 71)), 'Auftraggebers'];
        yield 'umlaut left' => [static fn(self $t): Pain001Auftrag => $t->auftrag(auftraggeber: 'TSV Müller'), 'Auftraggebers'];
        yield 'own IBAN as payee' => [static fn(self $t): Pain001Auftrag => $t->auftrag(ueberweisungen: [new Pain001Ueberweisung('E1', 'Anna', self::VEREIN, 100, '')]), 'IBAN des Auftraggeberkontos'];
        yield 'payee IBAN' => [static fn(self $t): Pain001Auftrag => $t->auftrag(ueberweisungen: [new Pain001Ueberweisung('E1', 'Anna', 'DE02120300000000202052', 100, '')]), 'Empfängers ist ungültig'];
        yield 'zero' => [static fn(self $t): Pain001Auftrag => $t->auftrag(ueberweisungen: [new Pain001Ueberweisung('E1', 'Anna', self::ANNA, 0, '')]), 'SEPA-Grenzen'];
        yield 'too much' => [static fn(self $t): Pain001Auftrag => $t->auftrag(ueberweisungen: [new Pain001Ueberweisung('E1', 'Anna', self::ANNA, Pain001::MAX_CENT + 1, '')]), 'SEPA-Grenzen'];
        yield 'remittance too long' => [static fn(self $t): Pain001Auftrag => $t->auftrag(ueberweisungen: [new Pain001Ueberweisung('E1', 'Anna', self::ANNA, 100, str_repeat('x', 141))]), 'Verwendungszweck'];
        yield 'same reference twice' => [static fn(self $t): Pain001Auftrag => $t->auftrag(ueberweisungen: [
            new Pain001Ueberweisung('E1', 'Anna', self::ANNA, 100, ''),
            new Pain001Ueberweisung('E1', 'Bert', self::BERT, 100, ''),
        ]), 'doppelt'];
        yield 'message id' => [static fn(self $t): Pain001Auftrag => $t->auftrag(msgId: 'A//B'), 'MsgId'];
    }

    /**
     * @param \Closure(self): Pain001Auftrag $auftrag
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeAuftraege')]
    public function testABrokenOrderIsRefusedWithoutValuesInTheMessage(\Closure $auftrag, string $meldung): void
    {
        try {
            Pain001::xml($auftrag($this));
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($meldung, $e->getMessage());
            foreach ([self::ANNA, self::VEREIN, 'Anna', '1.00'] as $wert) {
                self::assertStringNotContainsString($wert, $e->getMessage());
            }
        }
    }

    /**
     * @param list<Pain001Ueberweisung>|null $ueberweisungen
     */
    public function auftrag(
        string $iban = self::VEREIN,
        string $bic = 'COBADEFFXXX',
        string $auftraggeber = 'TSV Beispiel e.V.',
        ?array $ueberweisungen = null,
        string $msgId = 'VB-Z7-20261024101500',
    ): Pain001Auftrag {
        return new Pain001Auftrag(
            $msgId,
            'VB-Z7',
            new \DateTimeImmutable('2026-10-24 10:15:00'),
            $auftraggeber,
            $iban,
            $bic,
            new \DateTimeImmutable('2026-10-26'),
            $ueberweisungen ?? [
                new Pain001Ueberweisung('VB-Z7-1', 'Anna Mueller', self::ANNA, 123456, 'Auslagenerstattung Belege 12, 15'),
                new Pain001Ueberweisung('VB-Z7-2', 'Bert Beispiel', self::BERT, 1, 'Auslagenerstattung Beleg 20'),
            ],
        );
    }

    public static function gueltig(string $xml): bool
    {
        return self::fehler($xml) === [];
    }

    /**
     * @return list<string>
     */
    public static function fehler(string $xml): array
    {
        $vorher = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $doc->loadXML($xml);
            $ok = $doc->schemaValidate(dirname(__DIR__, 3) . '/fixtures/sepa/pain.001.001.09.xsd');
            $fehler = array_map(static fn(\LibXMLError $e): string => trim($e->message), libxml_get_errors());
            libxml_clear_errors();

            return $ok ? [] : ($fehler === [] ? ['invalid'] : $fehler);
        } finally {
            libxml_use_internal_errors($vorher);
        }
    }
}
