<?php

declare(strict_types=1);

namespace App\Service\Bank\Sepa;

use App\Domain\Iban;

/**
 * A SEPA credit transfer initiation, ISO 20022 pain.001.001.09 as the
 * German banking industry specifies it (DK, DFÜ-Abkommen Anlage 3; issue
 * #85, E-42): one group header, one payment information block for the
 * paying account, one transaction per payee.
 *
 * - Service level SEPA, charges SLEV, currency EUR only.
 * - `BtchBookg` false: the bank books every transfer on its own, so each
 *   one turns up in the statement with the payee's IBAN and the matching
 *   (App\Service\Bank\Erstattungen) finds it.
 * - The payer's bank by BIC when known, else `NOTPROVIDED` (IBAN only).
 * - The payee's bank is left out (IBAN only, allowed in SEPA).
 *
 * Pure: builds the XML in memory and checks every rule it can (character
 * set, lengths, IBAN, BIC, amounts); the tests also validate the result
 * against the official schema (tests/fixtures/sepa/). Nothing is written
 * anywhere.
 */
final class Pain001
{
    public const string NAMESPACE = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09';

    /** Highest amount of one SEPA transfer: 999 999 999.99 EUR. */
    public const int MAX_CENT = 99_999_999_999;

    public const int NAME_LAENGE = 70;

    public const int ZWECK_LAENGE = 140;

    private function __construct()
    {
        // Static utility, no instances.
    }

    /**
     * @throws \InvalidArgumentException when the order breaks a rule - a
     *         message without values (never a name, IBAN or amount)
     */
    public static function xml(Pain001Auftrag $auftrag): string
    {
        self::pruefe($auftrag);

        $summe = array_sum(array_map(static fn(Pain001Ueberweisung $u): int => $u->cent, $auftrag->ueberweisungen));
        $anzahl = (string) count($auftrag->ueberweisungen);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $wurzel = $doc->createElementNS(self::NAMESPACE, 'Document');
        $doc->appendChild($wurzel);
        $initiierung = self::kind($doc, $wurzel, 'CstmrCdtTrfInitn');

        $kopf = self::kind($doc, $initiierung, 'GrpHdr');
        self::kind($doc, $kopf, 'MsgId', $auftrag->msgId);
        self::kind($doc, $kopf, 'CreDtTm', $auftrag->erstellt->format('Y-m-d\TH:i:s'));
        self::kind($doc, $kopf, 'NbOfTxs', $anzahl);
        self::kind($doc, $kopf, 'CtrlSum', self::betrag($summe));
        self::kind($doc, self::kind($doc, $kopf, 'InitgPty'), 'Nm', $auftrag->auftraggeber);

        $zahlung = self::kind($doc, $initiierung, 'PmtInf');
        self::kind($doc, $zahlung, 'PmtInfId', $auftrag->pmtInfId);
        self::kind($doc, $zahlung, 'PmtMtd', 'TRF');
        self::kind($doc, $zahlung, 'BtchBookg', 'false');
        self::kind($doc, $zahlung, 'NbOfTxs', $anzahl);
        self::kind($doc, $zahlung, 'CtrlSum', self::betrag($summe));
        self::kind($doc, self::kind($doc, self::kind($doc, $zahlung, 'PmtTpInf'), 'SvcLvl'), 'Cd', 'SEPA');
        self::kind($doc, self::kind($doc, $zahlung, 'ReqdExctnDt'), 'Dt', $auftrag->ausfuehrung->format('Y-m-d'));
        self::kind($doc, self::kind($doc, $zahlung, 'Dbtr'), 'Nm', $auftrag->auftraggeber);
        self::kind($doc, self::kind($doc, self::kind($doc, $zahlung, 'DbtrAcct'), 'Id'), 'IBAN', $auftrag->iban);
        $institut = self::kind($doc, self::kind($doc, $zahlung, 'DbtrAgt'), 'FinInstnId');
        if ($auftrag->bic !== '') {
            self::kind($doc, $institut, 'BICFI', $auftrag->bic);
        } else {
            self::kind($doc, self::kind($doc, $institut, 'Othr'), 'Id', 'NOTPROVIDED');
        }
        self::kind($doc, $zahlung, 'ChrgBr', 'SLEV');

        foreach ($auftrag->ueberweisungen as $u) {
            $tx = self::kind($doc, $zahlung, 'CdtTrfTxInf');
            self::kind($doc, self::kind($doc, $tx, 'PmtId'), 'EndToEndId', $u->endToEndId);
            self::kind($doc, self::kind($doc, $tx, 'Amt'), 'InstdAmt', self::betrag($u->cent))->setAttribute('Ccy', 'EUR');
            self::kind($doc, self::kind($doc, $tx, 'Cdtr'), 'Nm', $u->name);
            self::kind($doc, self::kind($doc, self::kind($doc, $tx, 'CdtrAcct'), 'Id'), 'IBAN', $u->iban);
            if ($u->verwendungszweck !== '') {
                self::kind($doc, self::kind($doc, $tx, 'RmtInf'), 'Ustrd', $u->verwendungszweck);
            }
        }

        return (string) $doc->saveXML();
    }

    /** Integer cents as the decimal the schema wants: 1234 -> "12.34". */
    public static function betrag(int $cent): string
    {
        return sprintf('%d.%02d', intdiv($cent, 100), $cent % 100);
    }

    public static function istBic(string $bic): bool
    {
        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) === 1;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function pruefe(Pain001Auftrag $auftrag): void
    {
        foreach (['MsgId' => $auftrag->msgId, 'PmtInfId' => $auftrag->pmtInfId] as $feld => $wert) {
            if (!SepaText::istReferenz($wert)) {
                throw new \InvalidArgumentException($feld . ' ist keine gültige SEPA-Referenz.');
            }
        }
        self::pruefeName($auftrag->auftraggeber, 'Der Name des Auftraggebers');
        if (!Iban::istGueltig($auftrag->iban) || Iban::normalisieren($auftrag->iban) !== $auftrag->iban) {
            throw new \InvalidArgumentException('Die IBAN des Auftraggeberkontos ist ungültig.');
        }
        if ($auftrag->bic !== '' && !self::istBic($auftrag->bic)) {
            throw new \InvalidArgumentException('Die BIC des Auftraggeberkontos ist ungültig.');
        }
        if ($auftrag->ueberweisungen === []) {
            throw new \InvalidArgumentException('Der Zahllauf enthält keine Überweisung.');
        }
        $referenzen = [];
        $summe = 0;
        foreach ($auftrag->ueberweisungen as $u) {
            if (!SepaText::istReferenz($u->endToEndId) || isset($referenzen[$u->endToEndId])) {
                throw new \InvalidArgumentException('Eine Ende-zu-Ende-Referenz ist ungültig oder doppelt.');
            }
            $referenzen[$u->endToEndId] = true;
            self::pruefeName($u->name, 'Der Name eines Empfängers');
            if (!Iban::istGueltig($u->iban) || Iban::normalisieren($u->iban) !== $u->iban) {
                throw new \InvalidArgumentException('Die IBAN eines Empfängers ist ungültig.');
            }
            if ($u->iban === $auftrag->iban) {
                throw new \InvalidArgumentException('Ein Empfänger hat die IBAN des Auftraggeberkontos.');
            }
            if ($u->cent < 1 || $u->cent > self::MAX_CENT) {
                throw new \InvalidArgumentException('Ein Betrag liegt außerhalb der SEPA-Grenzen.');
            }
            if (strlen($u->verwendungszweck) > self::ZWECK_LAENGE || !SepaText::istZulaessig($u->verwendungszweck)) {
                throw new \InvalidArgumentException('Ein Verwendungszweck ist zu lang oder enthält unzulässige Zeichen.');
            }
            $summe += $u->cent;
        }
        // The control sum has at most 18 digits; far above any club's run.
        if ($summe > 99_999_999_999_999_999) {
            throw new \InvalidArgumentException('Die Summe des Zahllaufs ist zu groß.');
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function pruefeName(string $name, string $was): void
    {
        if (trim($name) === '' || strlen($name) > self::NAME_LAENGE || !SepaText::istZulaessig($name)) {
            throw new \InvalidArgumentException($was . ' fehlt, ist zu lang oder enthält unzulässige Zeichen.');
        }
    }

    private static function kind(\DOMDocument $doc, \DOMElement $eltern, string $name, ?string $text = null): \DOMElement
    {
        $element = $doc->createElementNS(self::NAMESPACE, $name);
        if ($text !== null) {
            $element->appendChild($doc->createTextNode($text));
        }
        $eltern->appendChild($element);

        return $element;
    }
}
