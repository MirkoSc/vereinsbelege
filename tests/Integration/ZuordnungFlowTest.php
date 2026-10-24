<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\BankAccountKind;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSetBy;
use App\Domain\BankTransactionSource;
use App\Domain\Differenzgrund;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InvoiceData;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
use App\Domain\PaymentStatus;
use App\Domain\Zahlungsweg;
use App\Domain\Zugriffsbereich;
use App\Domain\ZuordnungMethode;
use App\Repository\AllocationRepository;
use App\Repository\AssignmentRuleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\BlobRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\SettingRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Buchungsregeln;
use App\Service\Bank\Zuordnung;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\Texterkennung;
use App\Service\Export\ExportFilter;
use App\Service\Export\ZipExport;
use App\Service\Inbox\Posteingang;
use App\Service\Invoice\InvoiceRuleViolation;
use App\Service\Invoice\Pruefung;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\KiAuslesungFabrik;
use App\Tests\Support\ZuordnungFabrik;

/**
 * The allocation model and the statuses derived from it (M10-1, issue #65,
 * docs/spec/04-bank-und-abgleich.md section 5 "Modell"): the real
 * services, repositories, schema and vault - without a page, the matching
 * has none yet (M10-4).
 *
 * Mandatory tests of the spec for this scope: Sammelzahlung, Ratenzahlung,
 * Skonto, abgeleitete Status - and that the ZIP export (issue #76) reads
 * "bezahlt am", "Konto", "Referenz" and `{kasse}` from the allocations.
 */
final class ZuordnungFlowTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.10';

    private Vault $tresor;
    private AuditLog $audit;
    private ServerCrypto $crypto;
    private int $userId;
    private int $laufnummer = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $this->crypto);
        $this->userId = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt('finanzen@example.org'),
            random_bytes(32),
            $this->crypto->encrypt('Fritz Finanzen'),
            'hash',
            mfaRequired: false,
        );
    }

    // ---------------------------------------------------------- the model

    public function testOneBookingSettlesOneReceiptAndBothStatusesFollow(): void
    {
        $konto = $this->bankkonto('Girokonto');
        $beleg = $this->beleg(10000);
        $buchung = $this->importierteBuchung($konto, '2026-03-20', -10000, 'RE-1 Rasendünger', 'Bauhaus');
        self::assertSame('offen', $this->rechnung($beleg)['payment_status']);
        self::assertSame('fehlt', $this->buchung($buchung)['doc_status']);

        $id = $this->zuordnung()->zuordnen($this->tresor, $beleg, $buchung, 10000, ZuordnungMethode::Manuell, null, null, $this->userId, self::IP, new \DateTimeImmutable());

        self::assertSame('bezahlt', $this->rechnung($beleg)['payment_status']);
        self::assertNull($this->rechnung($beleg)['difference_reason']);
        self::assertSame('zugeordnet', $this->buchung($buchung)['doc_status']);
        self::assertSame('standard', $this->buchung($buchung)['doc_source'], 'what the booking needs is untouched');

        $zuordnungen = $this->zuordnung()->fuerBeleg($this->tresor, $beleg);
        self::assertCount(1, $zuordnungen);
        self::assertSame($id, $zuordnungen[0]->id);
        self::assertSame(10000, $zuordnungen[0]->amount);
        self::assertSame(ZuordnungMethode::Manuell, $zuordnungen[0]->method);
        self::assertSame($this->userId, $zuordnungen[0]->createdBy);
        self::assertSame([$id], array_map(static fn($z): int => $z->id, $this->zuordnung()->fuerBuchung($this->tresor, $buchung)));

        $stand = $this->zuordnung()->zahlungsstand($this->tresor, new InvoiceRepository($this->pdo())->find($beleg) ?? self::fail());
        self::assertSame(10000, $stand->zugeordnet);
        self::assertSame(0, $stand->offen());
        self::assertSame(PaymentStatus::Bezahlt, $stand->status());
    }

    public function testTheAmountIsCiphertextAndTheLogNamesOnlyIds(): void
    {
        $beleg = $this->beleg(123456);
        $buchung = $this->importierteBuchung($this->bankkonto('Girokonto'), '2026-03-20', -123456, 'RE-1', 'Bauhaus');

        $id = $this->zuordnung()->zuordnen($this->tresor, $beleg, $buchung, 123456, ZuordnungMethode::Auto, 97, ['betrag_exakt' => 45, 'rechnungsnummer' => 40, 'iban' => 12], $this->userId, self::IP, new \DateTimeImmutable());

        $zeile = $this->pdo()->query('SELECT * FROM allocation')->fetch();
        self::assertIsArray($zeile);
        self::assertSame($beleg, (int) $zeile['invoice_id']);
        self::assertSame($buchung, (int) $zeile['transaction_id']);
        self::assertSame('auto', $zeile['method']);
        self::assertSame(97, (int) $zeile['score']);
        self::assertSame(['betrag_exakt' => 45, 'rechnungsnummer' => 40, 'iban' => 12], json_decode((string) $zeile['rule_trace'], true));
        self::assertNotEmpty($zeile['dek_sealed']);
        foreach (['allocation', 'audit_log'] as $tabelle) {
            self::assertStringNotContainsString('123456', $this->rohTabelle($tabelle), $tabelle . ' holds the amount in plaintext');
        }

        $protokoll = $this->protokoll('allocation', $id);
        self::assertSame([AuditAction::ZuordnungAngelegt->value], array_map(static fn(AuditEntry $e): string => $e->action, $protokoll));
        self::assertSame(
            ['invoice_id' => $beleg, 'transaction_id' => $buchung, 'method' => 'auto', 'score' => 97],
            $this->audit->details($protokoll[0], $this->tresor),
        );
    }

    public function testACollectiveTransferPaysSeveralReceipts(): void
    {
        $konto = $this->bankkonto('Girokonto');
        $erste = $this->beleg(10000);
        $zweite = $this->beleg(5000);
        $dritte = $this->beleg(100);
        $buchung = $this->importierteBuchung($konto, '2026-03-20', -15000, 'RE-1 RE-2', 'Bauhaus');

        $this->zuordnen($erste, $buchung, 10000);
        self::assertSame('bezahlt', $this->rechnung($erste)['payment_status']);
        self::assertSame('teilweise', $this->buchung($buchung)['doc_status'], 'one invoice is still missing');
        self::assertSame(5000, $this->zuordnung()->belegstand($this->tresor, new BankTransactionRepository($this->pdo())->find($buchung) ?? self::fail())->offen());

        $this->zuordnen($zweite, $buchung, 5000);
        self::assertSame('bezahlt', $this->rechnung($zweite)['payment_status']);
        self::assertSame('zugeordnet', $this->buchung($buchung)['doc_status']);

        $this->expectException(BankRuleViolation::class);
        $this->expectExceptionMessage('übersteigt den noch nicht zugeordneten Betrag der Buchung');
        $this->zuordnen($dritte, $buchung, 100);
    }

    public function testInstalmentsSettleOneReceipt(): void
    {
        $konto = $this->bankkonto('Girokonto');
        $beleg = $this->beleg(10000);
        $erste = $this->importierteBuchung($konto, '2026-03-01', -4000, 'Rate 1', 'Bauhaus');
        $zweite = $this->importierteBuchung($konto, '2026-04-01', -6000, 'Rate 2', 'Bauhaus');
        $dritte = $this->importierteBuchung($konto, '2026-05-01', -1, 'Rate 3', 'Bauhaus');

        $this->zuordnen($beleg, $erste, 4000);
        self::assertSame('teilbezahlt', $this->rechnung($beleg)['payment_status']);
        self::assertSame('zugeordnet', $this->buchung($erste)['doc_status'], 'the instalment itself is fully explained');

        $this->zuordnen($beleg, $zweite, 6000);
        self::assertSame('bezahlt', $this->rechnung($beleg)['payment_status']);

        $this->expectException(BankRuleViolation::class);
        $this->expectExceptionMessage('übersteigt den offenen Betrag des Belegs');
        $this->zuordnen($beleg, $dritte, 1);
    }

    public function testACashDiscountIsClosedAsADifferenceWithAReason(): void
    {
        $beleg = $this->beleg(10000);
        $buchung = $this->importierteBuchung($this->bankkonto('Girokonto'), '2026-03-20', -9700, 'RE-1 abzgl. 3 % Skonto', 'Bauhaus');
        $this->zuordnen($beleg, $buchung, 9700);
        self::assertSame('teilbezahlt', $this->rechnung($beleg)['payment_status']);
        self::assertSame('zugeordnet', $this->buchung($buchung)['doc_status']);

        $this->zuordnung()->differenzAbschliessen($this->tresor, $beleg, Differenzgrund::Skonto, $this->userId, self::IP, new \DateTimeImmutable());

        $zeile = $this->rechnung($beleg);
        self::assertSame('bezahlt', $zeile['payment_status']);
        self::assertSame('skonto', $zeile['difference_reason']);
        $record = new InvoiceRepository($this->pdo())->find($beleg) ?? self::fail();
        self::assertSame(300, $this->zuordnung()->zahlungsstand($this->tresor, $record)->differenzbetrag());
        self::assertSame(0, $this->zuordnung()->zahlungsstand($this->tresor, $record)->offen(), 'closed: nothing left to allocate');

        $dokument = (int) $zeile['document_id'];
        $protokoll = $this->protokoll('document', $dokument);
        self::assertSame([AuditAction::BelegDifferenzAbgeschlossen->value], array_map(static fn(AuditEntry $e): string => $e->action, $protokoll));
        self::assertSame(['grund' => 'skonto'], $this->audit->details($protokoll[0], $this->tresor));

        try {
            $this->zuordnung()->differenzAbschliessen($this->tresor, $beleg, Differenzgrund::Sonstiges, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('closed twice');
        } catch (BankRuleViolation $e) {
            self::assertStringContainsString('bereits abgeschlossen', $e->getMessage());
        }

        $this->zuordnung()->differenzOeffnen($this->tresor, $beleg, $this->userId, self::IP, new \DateTimeImmutable());
        $zeile = $this->rechnung($beleg);
        self::assertSame('teilbezahlt', $zeile['payment_status']);
        self::assertNull($zeile['difference_reason']);
        self::assertSame(
            [AuditAction::BelegDifferenzGeoeffnet->value, AuditAction::BelegDifferenzAbgeschlossen->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $this->protokoll('document', $dokument)),
        );
    }

    public function testADifferenceNeedsSomethingAllocatedAndSomethingMissing(): void
    {
        $konto = $this->bankkonto('Girokonto');
        $offen = $this->beleg(10000);
        try {
            $this->zuordnung()->differenzAbschliessen($this->tresor, $offen, Differenzgrund::Skonto, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('nothing allocated');
        } catch (BankRuleViolation $e) {
            self::assertStringContainsString('ein Teil des Belegs zugeordnet', $e->getMessage());
        }

        $bezahlt = $this->beleg(10000);
        $this->zuordnen($bezahlt, $this->importierteBuchung($konto, '2026-03-20', -10000, 'RE-2', 'Bauhaus'), 10000);
        try {
            $this->zuordnung()->differenzAbschliessen($this->tresor, $bezahlt, Differenzgrund::Skonto, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('nothing missing');
        } catch (BankRuleViolation $e) {
            self::assertStringContainsString('ein Rest fehlt', $e->getMessage());
        }

        try {
            $this->zuordnung()->differenzOeffnen($this->tresor, $bezahlt, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('nothing closed');
        } catch (BankRuleViolation $e) {
            self::assertStringContainsString('keine Differenz abgeschlossen', $e->getMessage());
        }
        self::assertSame('bezahlt', $this->rechnung($bezahlt)['payment_status']);
    }

    public function testACreditNoteIsSettledByTheOppositeDirectionOrNetted(): void
    {
        $konto = $this->bankkonto('Girokonto');
        $gutschrift = $this->beleg(3000, art: InvoiceType::Gutschrift);
        $rueckzahlung = $this->importierteBuchung($konto, '2026-03-20', 3000, 'Gutschrift GS-7', 'Bauhaus');
        $abbuchung = $this->importierteBuchung($konto, '2026-03-21', -3000, 'irgendwas', 'Bauhaus');

        try {
            $this->zuordnen($gutschrift, $abbuchung, 3000);
            self::fail('an expense booking does not settle a supplier\'s credit note');
        } catch (BankRuleViolation $e) {
            self::assertStringContainsString('Gegenrichtung', $e->getMessage());
        }

        $this->zuordnen($gutschrift, $rueckzahlung, 3000);
        self::assertSame('bezahlt', $this->rechnung($gutschrift)['payment_status']);
        self::assertSame('zugeordnet', $this->buchung($rueckzahlung)['doc_status']);

        // Netted: the invoice closes with the reason, the credit note has
        // no payment of its own.
        $rechnung = $this->beleg(10000);
        $verrechnet = $this->beleg(3000, art: InvoiceType::Gutschrift, weg: Zahlungsweg::Keine);
        $this->zuordnen($rechnung, $this->importierteBuchung($konto, '2026-04-02', -7000, 'RE-9 abzgl. GS-8', 'Bauhaus'), 7000);
        $this->zuordnung()->differenzAbschliessen($this->tresor, $rechnung, Differenzgrund::Gutschrift, $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('bezahlt', $this->rechnung($rechnung)['payment_status']);
        self::assertSame('gutschrift', $this->rechnung($rechnung)['difference_reason']);
        self::assertSame('kein_zahlungsbezug', $this->rechnung($verrechnet)['payment_status']);
    }

    public function testAReimbursementUsesItsOwnWords(): void
    {
        $konto = $this->bankkonto('Girokonto');
        $beleg = $this->beleg(2550, weg: Zahlungsweg::Erstattung);
        self::assertSame('erstattung_offen', $this->rechnung($beleg)['payment_status']);

        $this->zuordnen($beleg, $this->importierteBuchung($konto, '2026-03-20', -1000, 'Erstattung Teil 1', 'Erika Einreicher'), 1000);
        self::assertSame('teilbezahlt', $this->rechnung($beleg)['payment_status']);

        $this->zuordnen($beleg, $this->importierteBuchung($konto, '2026-03-27', -1550, 'Erstattung Rest', 'Erika Einreicher'), 1550);
        self::assertSame('erstattet', $this->rechnung($beleg)['payment_status']);
    }

    public function testReceiptsThatExpectNoBookingAreNotAllocated(): void
    {
        $konto = $this->bankkonto('Girokonto');
        $bar = $this->beleg(1299, weg: Zahlungsweg::Bar);
        $keine = $this->beleg(1299, weg: Zahlungsweg::Keine);
        self::assertSame('bezahlt', $this->rechnung($bar)['payment_status'], 'a cash receipt is the proof of payment');
        self::assertSame('kein_zahlungsbezug', $this->rechnung($keine)['payment_status']);

        foreach ([$bar, $keine] as $beleg) {
            try {
                $this->zuordnen($beleg, $this->importierteBuchung($konto, '2026-03-20', -1299, 'x', 'y'), 1299);
                self::fail('allocated although no booking is expected');
            } catch (BankRuleViolation $e) {
                self::assertStringContainsString('keine Buchung erwartet', $e->getMessage());
            }
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM allocation')->fetchColumn());
    }

    public function testDirectionsMustMatchAndTheAmountMustBePositive(): void
    {
        $konto = $this->bankkonto('Girokonto');
        $einnahme = $this->beleg(700, richtung: InvoiceDirection::Einnahme);
        $ausgabe = $this->importierteBuchung($konto, '2026-03-20', -700, 'x', 'y');
        try {
            $this->zuordnen($einnahme, $ausgabe, 700);
            self::fail('directions differ');
        } catch (BankRuleViolation $e) {
            self::assertStringContainsString('Richtung', $e->getMessage());
        }

        $eingang = $this->importierteBuchung($konto, '2026-03-20', 700, 'Spende', 'Spender');
        try {
            $this->zuordnen($einnahme, $eingang, 0);
            self::fail('zero');
        } catch (BankRuleViolation $e) {
            self::assertSame('betrag', $e->feld);
        }
        try {
            $this->zuordnen($einnahme, $eingang, -700);
            self::fail('negative');
        } catch (BankRuleViolation $e) {
            self::assertSame('betrag', $e->feld);
        }

        $this->zuordnen($einnahme, $eingang, 700);
        self::assertSame('bezahlt', $this->rechnung($einnahme)['payment_status']);
        self::assertSame('zugeordnet', $this->buchung($eingang)['doc_status'], 'income that needed no receipt now has one');
    }

    public function testTheSamePairIsLinkedOnlyOnce(): void
    {
        $beleg = $this->beleg(10000);
        $buchung = $this->importierteBuchung($this->bankkonto('Girokonto'), '2026-03-20', -10000, 'RE-1', 'Bauhaus');
        $this->zuordnen($beleg, $buchung, 4000);

        try {
            $this->zuordnen($beleg, $buchung, 6000);
            self::fail('linked twice');
        } catch (BankRuleViolation $e) {
            self::assertStringContainsString('einander bereits zugeordnet', $e->getMessage());
        }
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM allocation')->fetchColumn());
        self::assertSame('teilbezahlt', $this->rechnung($beleg)['payment_status'], 'the refused attempt changed nothing');
    }

    public function testUnknownIdsAreRefused(): void
    {
        $beleg = $this->beleg(10000);
        try {
            $this->zuordnen($beleg, 999, 1);
            self::fail('unknown booking');
        } catch (BankRuleViolation $e) {
            self::assertSame('Diese Buchung gibt es nicht.', $e->getMessage());
        }
        try {
            $this->zuordnen(999, $this->importierteBuchung($this->bankkonto('Girokonto'), '2026-03-20', -1, 'x', 'y'), 1);
            self::fail('unknown receipt');
        } catch (BankRuleViolation $e) {
            self::assertSame('Diesen Beleg gibt es nicht.', $e->getMessage());
        }

        $this->expectException(BankRuleViolation::class);
        $this->zuordnung()->loesen($this->tresor, 999, $this->userId, self::IP, new \DateTimeImmutable());
    }

    public function testReleasingAnAllocationDerivesBothStatusesAgain(): void
    {
        $konto = $this->bankkonto('Girokonto');
        $beleg = $this->beleg(10000);
        $ausgabe = $this->importierteBuchung($konto, '2026-03-20', -9700, 'RE-1', 'Bauhaus');
        $id = $this->zuordnen($beleg, $ausgabe, 9700);
        $this->zuordnung()->differenzAbschliessen($this->tresor, $beleg, Differenzgrund::Skonto, $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('bezahlt', $this->rechnung($beleg)['payment_status']);

        $this->zuordnung()->loesen($this->tresor, $id, $this->userId, self::IP, new \DateTimeImmutable());

        $zeile = $this->rechnung($beleg);
        self::assertSame('offen', $zeile['payment_status']);
        self::assertNull($zeile['difference_reason'], 'a difference needs something allocated');
        self::assertSame('fehlt', $this->buchung($ausgabe)['doc_status'], 'an expense needs its receipt again');
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM allocation')->fetchColumn());

        $protokoll = $this->protokoll('allocation', $id);
        self::assertSame([AuditAction::ZuordnungGeloest->value, AuditAction::ZuordnungAngelegt->value], array_map(static fn(AuditEntry $e): string => $e->action, $protokoll));
        self::assertSame(['invoice_id' => $beleg, 'transaction_id' => $ausgabe, 'method' => 'manuell'], $this->audit->details($protokoll[0], $this->tresor));

        // Income that needed no receipt goes back to "nicht nötig".
        $einnahme = $this->beleg(700, richtung: InvoiceDirection::Einnahme);
        $eingang = $this->importierteBuchung($konto, '2026-03-20', 700, 'Spende', 'Spender');
        $this->zuordnung()->loesen($this->tresor, $this->zuordnen($einnahme, $eingang, 700), $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('nicht_noetig', $this->buchung($eingang)['doc_status']);
    }

    public function testAnAllocatedBookingKeepsItsStatusAgainstDeletionRulesAndChoice(): void
    {
        $kasse = $this->kasse('Barkasse');
        $buchungen = $this->buchungen();
        $kategorie = $this->kategorie('Platzpflege & Grünanlagen');
        $manuell = $buchungen->anlegen($this->tresor, [
            'konto' => (string) $kasse, 'datum' => '2026-03-20', 'betrag' => '100,00', 'richtung' => 'ausgabe',
            'kategorie' => (string) $kategorie, 'zweck' => 'Dünger bar', 'gegenseite' => 'Bauhaus', 'beleg' => '',
        ], new \DateTimeImmutable());
        $beleg = $this->beleg(10000);
        $this->zuordnen($beleg, $manuell, 4000);
        self::assertSame('teilweise', $this->buchung($manuell)['doc_status']);

        $buchung = $buchungen->finde($this->tresor, $manuell, Zugriffsbereich::unbeschraenkt()) ?? self::fail();
        try {
            $buchungen->loeschen($buchung);
            self::fail('deleted with a receipt');
        } catch (BankRuleViolation $e) {
            self::assertStringContainsString('erst die Zuordnung lösen', $e->getMessage());
        }
        self::assertSame(['doc_required'], $buchungen->aendern($this->tresor, $buchung, [
            'konto' => (string) $kasse, 'datum' => '2026-03-20', 'betrag' => '100,00', 'richtung' => 'ausgabe',
            'kategorie' => (string) $kategorie, 'zweck' => 'Dünger bar', 'gegenseite' => 'Bauhaus', 'beleg' => Buchungen::BELEG_NICHT_NOETIG,
        ], new \DateTimeImmutable()));
        self::assertSame('teilweise', $this->buchung($manuell)['doc_status'], 'the choice changes the need, not what is allocated');
        self::assertSame(0, (int) $this->buchung($manuell)['doc_required']);
        foreach ([['betrag' => '50,00'], ['richtung' => 'einnahme']] as $abweichend) {
            try {
                $buchungen->aendern($this->tresor, $buchung, [
                    'konto' => (string) $kasse, 'datum' => '2026-03-20', 'betrag' => '100,00', 'richtung' => 'ausgabe',
                    'kategorie' => (string) $kategorie, 'zweck' => 'Dünger bar', 'gegenseite' => 'Bauhaus', 'beleg' => '',
                    ...$abweichend,
                ], new \DateTimeImmutable());
                self::fail('amount or direction changed under an allocation');
            } catch (BankRuleViolation $e) {
                self::assertStringContainsString('wenn die Zuordnung gelöst ist', $e->getMessage());
            }
        }
        self::assertSame('teilweise', $this->buchung($manuell)['doc_status']);

        // A rule taken back leaves a partly allocated booking alone too.
        $importiert = $this->importierteBuchung($this->bankkonto('Girokonto'), '2026-03-21', -10000, 'RE-1', 'Bauhaus');
        $this->zuordnen($beleg, $importiert, 6000);
        $regel = new AssignmentRuleRepository($this->pdo())->insert($this->tresor->sealDataKey(DataKey::generate()), null, true, null, $this->userId, new \DateTimeImmutable());
        $this->pdo()->prepare('UPDATE bank_transaction SET rule_id = ?, doc_source = ? WHERE id = ?')->execute([$regel, BankTransactionSetBy::Regel->value, $importiert]);
        self::assertSame(1, new BankTransactionRepository($this->pdo())->regelZuruecknehmen($regel, false, new \DateTimeImmutable()));
        self::assertSame('teilweise', $this->buchung($importiert)['doc_status']);
        self::assertSame('standard', $this->buchung($importiert)['doc_source']);
    }

    public function testALockedReceiptCanStillBePaid(): void
    {
        $beleg = $this->beleg(10000, status: DocumentStatus::Festgeschrieben);
        $this->pdo()->prepare('UPDATE invoice SET locked_by = ?, locked_at = NOW() WHERE id = ?')->execute([$this->userId, $beleg]);
        $buchung = $this->importierteBuchung($this->bankkonto('Girokonto'), '2026-03-20', -10000, 'RE-1', 'Bauhaus');

        $this->zuordnen($beleg, $buchung, 10000);

        self::assertSame('bezahlt', $this->rechnung($beleg)['payment_status']);
        self::assertNotNull($this->rechnung($beleg)['locked_at'], 'the lock stays');
    }

    // ------------------------------------------------ the review page side

    public function testTheReviewPageKeepsWhatTheAllocationsRelyOn(): void
    {
        $konto = $this->bankkonto('Girokonto');
        $beleg = $this->beleg(10000, status: DocumentStatus::InPruefung);
        $this->zuordnen($beleg, $this->importierteBuchung($konto, '2026-03-20', -4000, 'Rate 1', 'Bauhaus'), 4000);
        $dokument = new DocumentRepository($this->pdo())->find((int) $this->rechnung($beleg)['document_id']) ?? self::fail();
        $pruefung = $this->pruefung();
        $felder = $this->formular(['brutto' => '100,00']);

        foreach ([
            ['zahlungsweg', ['zahlungsweg' => 'bar']],
            ['zahlungsweg', ['zahlungsweg' => 'keine']],
            ['richtung', ['richtung' => 'einnahme', 'kategorie' => '']],
            ['richtung', ['belegart' => 'gutschrift']],
            ['brutto', ['brutto' => '39,99']],
        ] as [$feld, $abweichend]) {
            try {
                $pruefung->speichern($dokument, $this->tresor, [...$felder, ...$abweichend], false, false, $this->userId, self::IP, new \DateTimeImmutable());
                self::fail('saved ' . json_encode($abweichend));
            } catch (InvoiceRuleViolation $e) {
                self::assertSame($feld, $e->feld, $e->getMessage());
                self::assertStringContainsString('zugeordnet', $e->getMessage());
            }
        }
        self::assertSame('teilbezahlt', $this->rechnung($beleg)['payment_status']);

        // Raising the gross amount is fine - the status follows.
        $pruefung->speichern($dokument, $this->tresor, [...$felder, 'brutto' => '120,00'], false, false, $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('teilbezahlt', $this->rechnung($beleg)['payment_status']);
        $record = new InvoiceRepository($this->pdo())->find($beleg) ?? self::fail();
        self::assertSame(8000, $this->zuordnung()->zahlungsstand($this->tresor, $record)->offen());

        // Switching to a reimbursement keeps the allocation and re-words the status.
        $pruefung->speichern($dokument, $this->tresor, [...$felder, 'brutto' => '40,00', 'zahlungsweg' => 'erstattung'], false, false, $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('erstattet', $this->rechnung($beleg)['payment_status']);
        self::assertSame('erstattung', $this->rechnung($beleg)['payment_kind']);

        // Still allocated: no way out of the booking expectation.
        try {
            $pruefung->speichern($dokument, $this->tresor, [...$felder, 'brutto' => '40,00', 'zahlungsweg' => 'bar'], false, false, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('changed to bar while allocated');
        } catch (InvoiceRuleViolation $e) {
            self::assertSame('zahlungsweg', $e->feld);
        }
        self::assertSame('erstattet', $this->rechnung($beleg)['payment_status']);
    }

    // ------------------------------------------------------- the ZIP export

    public function testTheExportReadsThePaymentFromTheAllocations(): void
    {
        $giro = $this->bankkonto('Girokonto');
        $kasse = $this->kasse('Barkasse');
        $kategorie = $this->kategorie('Platzpflege & Grünanlagen');

        $bezahlt = $this->beleg(10000, datum: '2026-03-01', nummer: 'RE-1', kategorie: $kategorie);
        $this->zuordnen($bezahlt, $this->importierteBuchung($giro, '2026-03-05', -4000, 'RE-1 Rate 1', 'Bauhaus'), 4000);
        $this->zuordnen($bezahlt, $this->importierteBuchung($giro, '2026-03-20', -6000, 'RE-1 Rate 2', 'Bauhaus'), 6000);

        $teilweise = $this->beleg(10000, datum: '2026-03-02', nummer: 'RE-2', kategorie: $kategorie);
        $this->zuordnen($teilweise, $this->importierteBuchung($giro, '2026-03-06', -4000, 'RE-2 Teil', 'Bauhaus'), 4000);

        $bar = $this->beleg(1299, datum: '2026-03-03', nummer: 'BON-3', kategorie: $kategorie, weg: Zahlungsweg::Bar);

        $ausKasse = $this->beleg(2500, datum: '2026-03-04', nummer: 'BON-4', kategorie: $kategorie);
        $kassenbuchung = $this->buchungen()->anlegen($this->tresor, [
            'konto' => (string) $kasse, 'datum' => '2026-03-04', 'betrag' => '25,00', 'richtung' => 'ausgabe',
            'kategorie' => (string) $kategorie, 'zweck' => 'Kleinteile', 'gegenseite' => 'Baumarkt', 'beleg' => '',
        ], new \DateTimeImmutable());
        $this->zuordnen($ausKasse, $kassenbuchung, 2500);

        $angaben = $this->zuordnung()->zahlungsangaben($this->tresor, array_map(
            fn(int $id) => new InvoiceRepository($this->pdo())->find($id) ?? self::fail(),
            [$bezahlt, $teilweise, $bar, $ausKasse],
        ));
        $ids = array_keys($angaben);
        sort($ids);
        self::assertSame([$bezahlt, $bar, $ausKasse], $ids, 'a partly paid receipt has no payment to report');
        self::assertSame('2026-03-20', $angaben[$bezahlt]->bezahltAm?->format('Y-m-d'), 'the last instalment');
        self::assertSame(['Girokonto'], $angaben[$bezahlt]->konten);
        self::assertSame(['RE-1 Rate 1', 'RE-1 Rate 2'], $angaben[$bezahlt]->referenzen);
        self::assertFalse($angaben[$bezahlt]->kasse);
        self::assertTrue($angaben[$bar]->kasse);
        self::assertNull($angaben[$bar]->bezahltAm);
        self::assertTrue($angaben[$ausKasse]->kasse, 'settled from the cash box');
        self::assertSame(['Barkasse'], $angaben[$ausKasse]->konten);

        $pdo = $this->pdo();
        new SettingRepository($pdo)->set('export_pfad_muster', '{jahr}/{monatsname}/{kasse}/{lieferant} {datum}.pdf');
        $export = new ZipExport(
            new InvoiceRepository($pdo),
            $this->blobs(),
            new SupplierService($pdo, new SupplierRepository($pdo), new CategoryRepository($pdo)),
            new CategoryRepository($pdo),
            new CostCenterRepository($pdo),
            new SettingRepository($pdo),
            $this->zuordnung(),
        );
        $plan = $export->vorbereiten(new ExportFilter(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')), Zugriffsbereich::unbeschraenkt(), $this->tresor);

        self::assertSame(
            "\u{FEFF}Datum;Richtung;Lieferant;Rechnungsnr.;Brutto;Kategorie;Kostenstelle;Status;bezahlt am;Konto;Referenz;Datei\r\n"
            . "01.03.2026;Ausgabe;;RE-1;100,00;Platzpflege & Grünanlagen;;Geprüft;20.03.2026;Girokonto;RE-1 Rate 1 | RE-1 Rate 2;(Datei fehlt)\r\n"
            . "02.03.2026;Ausgabe;;RE-2;100,00;Platzpflege & Grünanlagen;;Geprüft;;;;(Datei fehlt)\r\n"
            . "03.03.2026;Ausgabe;;BON-3;12,99;Platzpflege & Grünanlagen;;Geprüft;;;;(Datei fehlt)\r\n"
            . "04.03.2026;Ausgabe;;BON-4;25,00;Platzpflege & Grünanlagen;;Geprüft;04.03.2026;Barkasse;Kleinteile;(Datei fehlt)\r\n",
            $plan->indexCsv,
        );
    }

    // ------------------------------------------------------------ helpers

    private function zuordnen(int $beleg, int $buchung, int $betrag): int
    {
        return $this->zuordnung()->zuordnen($this->tresor, $beleg, $buchung, $betrag, ZuordnungMethode::Manuell, null, null, $this->userId, self::IP, new \DateTimeImmutable());
    }

    private function zuordnung(): Zuordnung
    {
        return ZuordnungFabrik::bauen($this->pdo(), $this->audit);
    }

    private function buchungen(): Buchungen
    {
        return new Buchungen($this->pdo(), new BankTransactionRepository($this->pdo()), $this->konten(), new CategoryRepository($this->pdo()));
    }

    private function konten(): BankAccountService
    {
        return new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()));
    }

    private function bankkonto(string $name): int
    {
        return $this->konten()->anlegen($this->tresor, BankAccountKind::Bank, ['name' => $name, 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01'], new \DateTimeImmutable())->id;
    }

    private function kasse(string $name): int
    {
        return $this->konten()->anlegen($this->tresor, BankAccountKind::Kasse, ['name' => $name, 'opening_balance' => '500,00', 'opening_date' => '2026-01-01'], new \DateTimeImmutable())->id;
    }

    private function kategorie(string $name): int
    {
        foreach (new CategoryRepository($this->pdo())->all() as $kategorie) {
            if ($kategorie->name === $name) {
                return $kategorie->id;
            }
        }

        self::fail('No category ' . $name);
    }

    /** A checked receipt straight into the encrypted store, without files; returns the `invoice` id. */
    private function beleg(
        int $brutto,
        InvoiceDirection $richtung = InvoiceDirection::Ausgabe,
        InvoiceType $art = InvoiceType::Rechnung,
        Zahlungsweg $weg = Zahlungsweg::Konto,
        DocumentStatus $status = DocumentStatus::Geprueft,
        string $datum = '2026-03-14',
        string $nummer = '',
        ?int $kategorie = null,
    ): int {
        ++$this->laufnummer;
        $dokument = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('+' . $this->laufnummer . ' seconds'),
            $status,
            createdBy: $this->userId,
        );
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure($art, $richtung, null, new \DateTimeImmutable($datum), null, null, null, $kategorie, null, $weg),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData($nummer, $brutto, null, [], 'EUR', 'Zweck', '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    /** A booking as the statement import writes it, encrypted under the test vault. */
    private function importierteBuchung(int $konto, string $datum, int $cent, string $zweck, string $name): int
    {
        $repository = new BankTransactionRepository($this->pdo());
        $richtung = BankTransactionDirection::ausDemBetrag($cent);
        $key = DataKey::generate();
        $id = $repository->insert(
            $konto,
            null,
            new \DateTimeImmutable($datum),
            null,
            $richtung,
            $this->tresor->sealDataKey($key),
            random_bytes(32),
            null,
            BankTransactionDocStatus::fuerNeueBuchung($richtung === BankTransactionDirection::Ausgabe),
            $richtung === BankTransactionDirection::Ausgabe,
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => $name, 'counterparty_iban' => '',
            'purpose' => $zweck, 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    /**
     * @param array<string, string> $abweichend
     *
     * @return array<string, string>
     */
    private function formular(array $abweichend = []): array
    {
        return [
            ...array_fill_keys(Pruefung::feldnamen(), ''),
            'richtung' => 'ausgabe',
            'belegart' => 'rechnung',
            'zahlungsweg' => 'konto',
            'datum' => '2026-03-14',
            'brutto' => '100,00',
            'waehrung' => 'EUR',
            'kategorie' => (string) $this->kategorie('Platzpflege & Grünanlagen'),
            ...$abweichend,
        ];
    }

    private function pruefung(): Pruefung
    {
        $pdo = $this->pdo();
        $documents = new DocumentRepository($pdo);
        $kostenstellen = new CostCenterRepository($pdo);
        $kategorien = new CategoryRepository($pdo);

        return new Pruefung(
            $pdo,
            $documents,
            new InvoiceRepository($pdo),
            $kategorien,
            $kostenstellen,
            new SupplierRepository($pdo),
            new SupplierService($pdo, new SupplierRepository($pdo), $kategorien),
            new DocumentArtifactRepository($pdo),
            new Texterkennung($documents, new DocumentArtifactRepository($pdo), $this->blobs()),
            new Posteingang($documents, $kostenstellen, $this->blobs(), $this->audit, new JobRepository($pdo)),
            $this->audit,
            KiAuslesungFabrik::bauen($pdo, $this->blobs(), $this->crypto),
            new Buchungsregeln($pdo, new AssignmentRuleRepository($pdo), new BankTransactionRepository($pdo), $kategorien, new SettingRepository($pdo)),
            KiAuslesungFabrik::lieferanten($pdo, $this->blobs(), $this->crypto, $this->audit),
            $this->zuordnung(),
        );
    }

    private function blobs(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend(sys_get_temp_dir() . '/vb_zuordnung_blobs_' . getmypid()));
    }

    /**
     * @return array<string, mixed>
     */
    private function rechnung(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM invoice WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    /**
     * @return array<string, mixed>
     */
    private function buchung(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM bank_transaction WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    /**
     * @return list<AuditEntry> newest first
     */
    private function protokoll(string $entity, int $id): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: $entity, entityId: $id), null, 10);
    }

    private function rohTabelle(string $tabelle): string
    {
        $roh = '';
        foreach ($this->pdo()->query('SELECT * FROM ' . $tabelle)->fetchAll() as $zeile) {
            $roh .= implode('|', array_map(static fn(mixed $w): string => (string) $w, $zeile)) . "\n";
        }

        return $roh;
    }
}
