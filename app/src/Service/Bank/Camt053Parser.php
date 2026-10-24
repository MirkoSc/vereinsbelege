<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\Iban;
use App\Service\Archiv\TextQuelle;
use App\Service\Archiv\UngueltigesZip;
use App\Service\Archiv\ZipEintragDefekt;
use App\Service\Archiv\ZipLeser;

/**
 * Reads ISO 20022 account statements CAMT.053 (issue #91,
 * docs/spec/04-bank-und-abgleich.md section 2a) into the same value
 * objects as the MT940 parser (App\Service\Bank\Kontoauszug), so the
 * import pipeline stays one. Pure PHP on ext-dom, no library.
 *
 * Works on the file content in memory only and keeps nothing: no logging,
 * no temp files, and an exception names the position (file in the ZIP,
 * statement, entry, element) but never what it says (CLAUDE.md section 4).
 *
 * What it reads:
 * - camt.053.001.02 up to .08 (and later ones with the same structure):
 *   elements are found by their local name, the namespace is not looked
 *   at. Differences between the versions are handled where they matter
 *   (`<Sts>BOOK</Sts>` vs. `<Sts><Cd>BOOK</Cd></Sts>`, `Cdtr/Nm` vs.
 *   `Cdtr/Pty/Nm`, `BIC` vs. `BICFI`).
 * - A single XML file with one or more statements (`Stmt`), or a ZIP of
 *   such files as the banks hand them out for a period - every `.xml`
 *   entry is read, anything else in the archive is ignored. The same
 *   statement (`Stmt/Id`) twice is read once.
 * - Balances: opening = OPBD, else PRCD (what most German banks send),
 *   else the first ITBD; closing = CLBD, else the last ITBD (then marked as
 *   interim, like :62M:).
 * - Only booked entries (status BOOK); pending or informational ones are
 *   counted and skipped - they come again once booked.
 * - One Umsatz per entry (`Ntry`), its amount as the balance sees it: the
 *   credit/debit indicator gives the sign, `RvslInd` marks a reversal (it
 *   keeps the sign of its effect, like RC/RD in MT940). A batch with
 *   several transaction details stays one booking; its purposes are joined.
 * - German bank transaction code (`BkTxCd/Prtry/Cd` "NTRF+166+931", DK
 *   format): transaction type, GVC and its supplement, as in MT940.
 * - Counterparty from the transaction details: the debtor for a credit,
 *   the creditor for a debit (the original direction for a reversal).
 *   EndToEndId, MndtId, creditor id and ultimate parties become the same
 *   SEPA keys MT940 has (EREF, MREF, CRED, ABWA, ABWE), the unstructured
 *   remittance information (`Ustrd`, joined) the purpose (SVWZ).
 *
 * Refused: a DOCTYPE (no entity expansion, no external entities), another
 * ISO 20022 message (camt.052 report, camt.054 notification), a statement
 * without account or balances, an amount that is no plain decimal.
 */
final class Camt053Parser
{
    /**
     * Largest XML file read, also per entry of a ZIP. Every step of the
     * import chain reads the file again: a year of daily statements of a
     * busy account (7,300 entries, 4.5 MB of XML) takes about half a second
     * and 35 MB.
     */
    public const int MAX_XML_BYTES = 5 * 1024 * 1024;

    /** All XML of a ZIP together. */
    private const int MAX_ZIP_SUMME = 10 * 1024 * 1024;

    /** More statement files than a decade of daily statements. */
    private const int MAX_ZIP_DATEIEN = 5000;

    /** One `Ustrd` element: a full one is continued without a space. */
    private const int USTRD_LAENGE = 140;

    private const string NICHT_ANGEGEBEN = 'NOTPROVIDED';

    /** Dates are calendar days of the club (CLAUDE.md section 5). */
    private const string ZEITZONE = 'Europe/Berlin';

    public static function istZip(string $inhalt): bool
    {
        return str_starts_with($inhalt, "PK\x03\x04") || str_starts_with($inhalt, "PK\x05\x06");
    }

    /** Whether the content looks like XML at all (BOM and whitespace skipped). */
    public static function istXml(string $inhalt): bool
    {
        $text = ltrim(str_starts_with($inhalt, "\xEF\xBB\xBF") ? substr($inhalt, 3) : $inhalt);

        return str_starts_with($text, '<');
    }

    /**
     * @throws Camt053Exception when the file is no readable CAMT.053
     */
    public function parse(string $inhalt): Camt053Ergebnis
    {
        $zip = self::istZip($inhalt);
        $gelesen = [];
        foreach ($zip ? $this->ausZip($inhalt) : [$inhalt] as $i => $xml) {
            try {
                array_push($gelesen, ...$this->datei($xml));
            } catch (Camt053Exception $e) {
                throw $zip ? new Camt053Exception(sprintf('XML-Datei %d im ZIP: %s', $i + 1, $e->getMessage())) : $e;
            }
        }

        // The same statement twice (a ZIP of overlapping downloads) counts once.
        $gesehen = [];
        $eindeutig = [];
        foreach ($gelesen as [$auszug, $vorgemerkt]) {
            $schluessel = $auszug->konto->roh . "\0" . $auszug->referenz;
            if ($auszug->referenz !== '' && isset($gesehen[$schluessel])) {
                continue;
            }
            $gesehen[$schluessel] = true;
            $eindeutig[] = [$auszug, $vorgemerkt];
        }

        // Chronological; statements of one day by their sequence number,
        // otherwise (stable sort) in file order.
        usort($eindeutig, static fn(array $a, array $b): int => self::reihenfolge($a[0]) <=> self::reihenfolge($b[0]));

        return new Camt053Ergebnis(
            array_map(static fn(array $paar): Kontoauszug => $paar[0], $eindeutig),
            array_sum(array_map(static fn(array $paar): int => $paar[1], $eindeutig)),
        );
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable, int}
     */
    private static function reihenfolge(Kontoauszug $auszug): array
    {
        $nummer = $auszug->auszugsnummer !== null && ctype_digit($auszug->auszugsnummer) ? (int) $auszug->auszugsnummer : 0;

        return [$auszug->anfangssaldo->datum, $auszug->schlusssaldo->datum, $nummer];
    }

    /**
     * The XML files of a ZIP, in archive order.
     *
     * @return list<string>
     *
     * @throws Camt053Exception
     */
    private function ausZip(string $inhalt): array
    {
        $zip = new ZipLeser(new TextQuelle($inhalt));
        $dateien = [];
        $summe = 0;
        try {
            foreach ($zip->verzeichnis() as $eintrag) {
                $name = $eintrag->dateiname();
                if ($eintrag->ordner || !str_ends_with(strtolower($name), '.xml') || str_starts_with($name, '.') || str_starts_with($eintrag->pfad, '__MACOSX/')) {
                    continue;
                }
                if (count($dateien) >= self::MAX_ZIP_DATEIEN) {
                    throw new Camt053Exception('Das ZIP enthält zu viele Dateien – bitte einen kürzeren Zeitraum exportieren.');
                }
                if ($eintrag->groesse > self::MAX_XML_BYTES || $summe + $eintrag->groesse > self::MAX_ZIP_SUMME) {
                    throw new Camt053Exception('Das ZIP ist entpackt zu groß – bitte einen kürzeren Zeitraum exportieren.');
                }
                try {
                    $xml = implode('', iterator_to_array($zip->inhalt($eintrag, self::MAX_XML_BYTES), false));
                } catch (ZipEintragDefekt $e) {
                    throw new Camt053Exception(sprintf('XML-Datei %d im ZIP: %s', count($dateien) + 1, $e->getMessage()));
                }
                $summe += strlen($xml);
                $dateien[] = $xml;
            }
        } catch (UngueltigesZip $e) {
            throw new Camt053Exception($e->getMessage());
        }
        if ($dateien === []) {
            throw new Camt053Exception('Das ZIP enthält keine XML-Datei.');
        }

        return $dateien;
    }

    /**
     * @return list<array{Kontoauszug, int}> each statement with the number of
     *         its skipped entries not yet booked
     *
     * @throws Camt053Exception
     */
    private function datei(string $xml): array
    {
        if (strlen($xml) > self::MAX_XML_BYTES) {
            throw new Camt053Exception('Die XML-Datei ist zu groß.');
        }
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false) {
            throw new Camt053Exception('Die Datei ist keine lesbare XML-Datei.');
        }

        $vorher = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            // The byte search above misses a DOCTYPE in UTF-16; libxml does
            // not substitute entities without LIBXML_NOENT, and the file is
            // refused here all the same.
            if (!$dom->loadXML($xml, LIBXML_NONET) || $dom->documentElement === null || $dom->doctype !== null) {
                throw new Camt053Exception('Die Datei ist keine lesbare XML-Datei.');
            }
            $nachricht = self::kind($dom->documentElement, 'BkToCstmrStmt');
            if ($nachricht === null) {
                throw new Camt053Exception(self::andereNachricht($dom->documentElement));
            }

            $auszuege = [];
            foreach (self::kinder($nachricht, 'Stmt') as $i => $stmt) {
                $auszuege[] = $this->auszug($stmt, $i + 1);
            }
            if ($auszuege === []) {
                throw new Camt053Exception('Die Datei enthält keinen Kontoauszug (Stmt).');
            }

            return $auszuege;
        } catch (\ValueError) {
            throw new Camt053Exception('Die Datei ist keine lesbare XML-Datei.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($vorher);
        }
    }

    private static function andereNachricht(\DOMElement $wurzel): string
    {
        $erstes = null;
        foreach ($wurzel->childNodes as $kind) {
            if ($kind instanceof \DOMElement) {
                $erstes = $kind->localName;
                break;
            }
        }

        return match ($erstes) {
            'BkToCstmrAcctRpt' => 'Die Datei ist ein CAMT.052-Umsatzbericht (untertägig, ohne festen Schlusssaldo). Bitte den Kontoauszug als CAMT.053 exportieren.',
            'BkToCstmrDbtCdtNtfctn' => 'Die Datei ist eine CAMT.054-Sammelbuchungsdatei. Bitte den Kontoauszug als CAMT.053 exportieren.',
            default => 'Die XML-Datei ist kein Kontoauszug im Format CAMT.053.',
        };
    }

    /**
     * @return array{Kontoauszug, int}
     *
     * @throws Camt053Exception
     */
    private function auszug(\DOMElement $stmt, int $nr): array
    {
        $konto = $this->kontoangabe($stmt, $nr);

        $salden = [];
        foreach (self::kinder($stmt, 'Bal') as $bal) {
            $salden[strtoupper(self::text($bal, 'Tp/CdOrPrtry/Cd') ?? '')][] = $bal;
        }
        $zwischen = $salden['ITBD'] ?? [];
        $anfangsElement = $salden['OPBD'][0] ?? $salden['PRCD'][0] ?? null;
        $anfangZwischen = $anfangsElement === null;
        // An interim balance opens only if another one is left to close.
        if ($anfangZwischen && count($zwischen) + (isset($salden['CLBD']) ? 1 : 0) >= 2) {
            $anfangsElement = array_shift($zwischen);
        }
        $schlussElement = $salden['CLBD'][0] ?? ($zwischen === [] ? null : $zwischen[count($zwischen) - 1]);
        if ($anfangsElement === null) {
            throw new Camt053Exception(sprintf('Auszug %d: Der Anfangssaldo (OPBD/PRCD) fehlt.', $nr));
        }
        if ($schlussElement === null) {
            throw new Camt053Exception(sprintf('Auszug %d: Der Schlusssaldo (CLBD) fehlt.', $nr));
        }
        $anfangssaldo = $this->saldo($anfangsElement, $anfangZwischen, sprintf('Auszug %d, Anfangssaldo', $nr));
        $schlusssaldo = $this->saldo($schlussElement, !isset($salden['CLBD']), sprintf('Auszug %d, Schlusssaldo', $nr));

        $umsaetze = [];
        $vorgemerkt = 0;
        foreach (self::kinder($stmt, 'Ntry') as $i => $ntry) {
            $status = strtoupper(trim((string) self::kind($ntry, 'Sts')?->textContent));
            if ($status !== 'BOOK') {
                $vorgemerkt++;
                continue;
            }
            $umsaetze[] = $this->umsatz($ntry, sprintf('Auszug %d, Buchung %d', $nr, $i + 1));
        }

        $nummer = self::text($stmt, 'LglSeqNb') ?? self::text($stmt, 'ElctrncSeqNb');

        return [new Kontoauszug(self::text($stmt, 'Id') ?? '', $konto, $nummer, $anfangssaldo, $schlusssaldo, $umsaetze), $vorgemerkt];
    }

    /**
     * @throws Camt053Exception
     */
    private function kontoangabe(\DOMElement $stmt, int $nr): Kontoangabe
    {
        $iban = self::text($stmt, 'Acct/Id/IBAN');
        if ($iban !== null) {
            $iban = Iban::normalisieren($iban);

            return new Kontoangabe($iban, Iban::istGueltig($iban) ? $iban : null, null, null);
        }
        $andere = self::text($stmt, 'Acct/Id/Othr/Id');
        if ($andere !== null) {
            return new Kontoangabe($andere, null, null, null);
        }

        throw new Camt053Exception(sprintf('Auszug %d: Das Konto (Acct/Id) fehlt.', $nr));
    }

    /**
     * @throws Camt053Exception
     */
    private function saldo(\DOMElement $bal, bool $zwischensaldo, string $ort): Saldo
    {
        [$cent, $waehrung] = $this->betrag($bal, $ort);
        $datum = $this->datum($bal, 'Dt', $ort) ?? throw new Camt053Exception($ort . ': Das Datum fehlt.');

        return new Saldo($datum, $cent, $waehrung, $zwischensaldo);
    }

    /**
     * @throws Camt053Exception
     */
    private function umsatz(\DOMElement $ntry, string $ort): Umsatz
    {
        [$cent, ] = $this->betrag($ntry, $ort);
        $storno = in_array(strtolower(self::text($ntry, 'RvslInd') ?? ''), ['true', '1'], true);

        $buchungsdatum = $this->datum($ntry, 'BookgDt', $ort);
        $valuta = $this->datum($ntry, 'ValDt', $ort);
        $buchungsdatum ??= $valuta ?? throw new Camt053Exception($ort . ': Das Buchungsdatum (BookgDt) fehlt.');
        $valuta ??= $buchungsdatum;

        $details = [];
        foreach (self::kinder($ntry, 'NtryDtls') as $dtls) {
            array_push($details, ...self::kinder($dtls, 'TxDtls'));
        }

        $prtry = self::text($ntry, 'BkTxCd/Prtry/Cd');
        foreach ($details as $tx) {
            $prtry ??= self::text($tx, 'BkTxCd/Prtry/Cd');
        }
        [$schluessel, $gvc, $ergaenzung] = self::bankcode($prtry);
        if ($schluessel === '') {
            $schluessel = self::text($ntry, 'BkTxCd/Domn/Fmly/SubFmlyCd') ?? '';
        }

        $buchungstext = self::text($ntry, 'AddtlNtryInf');
        foreach ($details as $tx) {
            $buchungstext ??= self::text($tx, 'AddtlTxInf');
        }

        $zwecke = [];
        foreach ($details as $tx) {
            $zweck = self::verwendungszweck($tx);
            if ($zweck !== '') {
                $zwecke[] = $zweck;
            }
        }

        // Counterparty and references only for a single transaction; a batch
        // keeps the purpose of every part but has no single counterparty.
        $eref = null;
        $sepa = [];
        $name = null;
        $iban = null;
        $bic = null;
        if (count($details) === 1) {
            $tx = $details[0];
            $seite = ($cent > 0) !== $storno ? 'Dbtr' : 'Cdtr';
            $eref = self::ohneNichtAngegeben(self::text($tx, 'Refs/EndToEndId'));
            $sepa = [
                'MREF' => self::text($tx, 'Refs/MndtId'),
                'CRED' => self::text($tx, 'RltdPties/Cdtr/Id/PrvtId/Othr/Id') ?? self::text($tx, 'RltdPties/Cdtr/Pty/Id/PrvtId/Othr/Id'),
                'ABWA' => self::partei($tx, 'UltmtDbtr'),
                'ABWE' => self::partei($tx, 'UltmtCdtr'),
            ];
            $name = self::partei($tx, $seite);
            $kontoIban = self::text($tx, 'RltdPties/' . $seite . 'Acct/Id/IBAN');
            $iban = $kontoIban === null ? null : Iban::normalisieren($kontoIban);
            $bic = self::text($tx, 'RltdAgts/' . $seite . 'Agt/FinInstnId/BICFI') ?? self::text($tx, 'RltdAgts/' . $seite . 'Agt/FinInstnId/BIC');
        }

        $zweck = implode(' / ', $zwecke);
        if ($zweck === '' && count($details) <= 1) {
            $zweck = (string) $buchungstext;
        }
        if ($eref !== null) {
            $sepa['EREF'] = $eref;
        }
        if ($zwecke !== []) {
            $sepa['SVWZ'] = $zweck;
        }
        $sepa = array_filter($sepa, static fn(?string $wert): bool => $wert !== null && $wert !== '');
        ksort($sepa);

        return new Umsatz(
            $valuta,
            $buchungsdatum,
            $cent,
            $storno,
            $schluessel,
            $eref ?? 'NONREF',
            self::text($ntry, 'AcctSvcrRef') ?? ($details !== [] ? self::text($details[0], 'Refs/AcctSvcrRef') : null),
            null,
            new Umsatzdetails(
                strukturiert: true,
                gvc: $gvc,
                buchungstext: $buchungstext,
                primanota: null,
                verwendungszweck: $zweck,
                verwendungszweckRoh: $zweck,
                sepa: $sepa,
                bic: $bic === null ? null : strtoupper($bic),
                iban: $iban,
                name: $name,
                textschluesselergaenzung: $ergaenzung,
            ),
        );
    }

    /**
     * The unstructured remittance information, joined like the banks cut it
     * (a full 140-character element continues directly); else the creditor
     * reference of the structured one.
     */
    private static function verwendungszweck(\DOMElement $tx): string
    {
        $rmt = self::kind($tx, 'RmtInf');
        $text = '';
        $vorher = null;
        foreach (self::kinder($rmt, 'Ustrd') as $ustrd) {
            $teil = trim($ustrd->textContent);
            if ($teil === '') {
                continue;
            }
            // A full element was cut mid-text; a shorter one (or one that
            // ended in a space, trimmed now) ended on a word.
            $text .= ($vorher === null || mb_strlen($vorher) >= self::USTRD_LAENGE ? '' : ' ') . $teil;
            $vorher = $teil;
        }
        if ($text !== '') {
            return $text;
        }
        foreach (self::kinder($rmt, 'Strd') as $strd) {
            $ref = self::text($strd, 'CdtrRefInf/Ref');
            if ($ref !== null) {
                return $ref;
            }
        }

        return '';
    }

    /** The name of a party, in versions .02-.07 (`Nm`) and .08+ (`Pty/Nm`). */
    private static function partei(\DOMElement $tx, string $rolle): ?string
    {
        return self::text($tx, 'RltdPties/' . $rolle . '/Nm') ?? self::text($tx, 'RltdPties/' . $rolle . '/Pty/Nm');
    }

    /**
     * German proprietary code "NTRF+166+931": transaction type, GVC and its
     * supplement.
     *
     * @return array{string, ?string, ?string}
     */
    private static function bankcode(?string $code): array
    {
        if ($code === null) {
            return ['', null, null];
        }
        $teile = explode('+', $code);
        $gvc = isset($teile[1]) && preg_match('/^\d{3}$/', $teile[1]) === 1 ? $teile[1] : null;
        $ergaenzung = isset($teile[2]) && $teile[2] !== '' ? $teile[2] : null;

        return [$teile[0], $gvc, $ergaenzung];
    }

    private static function ohneNichtAngegeben(?string $wert): ?string
    {
        return $wert === null || strtoupper($wert) === self::NICHT_ANGEGEBEN ? null : $wert;
    }

    /**
     * Signed cents of `Amt` with `CdtDbtInd` - a plain decimal with a point,
     * more than two decimals only when they are zeros.
     *
     * @return array{int, string}
     *
     * @throws Camt053Exception
     */
    private function betrag(\DOMElement $element, string $ort): array
    {
        $amt = self::kind($element, 'Amt');
        if ($amt === null) {
            throw new Camt053Exception($ort . ': Der Betrag (Amt) fehlt.');
        }
        if (preg_match('/^(\d{1,15})(?:\.(\d{1,2})0*)?$/', trim($amt->textContent), $t) !== 1) {
            throw new Camt053Exception($ort . ': Der Betrag (Amt) ist ungültig.');
        }
        $cent = (int) $t[1] * 100 + (int) str_pad($t[2] ?? '', 2, '0');
        $waehrung = strtoupper(trim($amt->getAttribute('Ccy')));
        if (preg_match('/^[A-Z]{3}$/', $waehrung) !== 1) {
            throw new Camt053Exception($ort . ': Die Währung (Ccy) fehlt.');
        }

        $vorzeichen = match (strtoupper(self::text($element, 'CdtDbtInd') ?? '')) {
            'CRDT' => 1,
            'DBIT' => -1,
            default => throw new Camt053Exception($ort . ': Soll/Haben (CdtDbtInd) fehlt.'),
        };

        return [$vorzeichen * $cent, $waehrung];
    }

    /**
     * `<X><Dt>` or `<X><DtTm>` as a calendar day - a date-time as its day in
     * Europe/Berlin, whatever zone the process runs in - null when the
     * element is missing.
     *
     * @throws Camt053Exception
     */
    private function datum(\DOMElement $element, string $name, string $ort): ?\DateTimeImmutable
    {
        $huelle = self::kind($element, $name);
        if ($huelle === null) {
            return null;
        }
        $tag = self::text($huelle, 'Dt');
        if ($tag !== null) {
            $datum = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $tag, $t) === 1 && checkdate((int) $t[2], (int) $t[3], (int) $t[1])
                ? new \DateTimeImmutable(sprintf('%s-%s-%s', $t[1], $t[2], $t[3]))
                : null;
        } else {
            $zeit = self::text($huelle, 'DtTm');
            $datum = null;
            if ($zeit !== null && preg_match('/^\d{4}-\d{2}-\d{2}T/', $zeit) === 1) {
                try {
                    $lokal = new \DateTimeImmutable($zeit)->setTimezone(new \DateTimeZone(self::ZEITZONE));
                    $datum = new \DateTimeImmutable($lokal->format('Y-m-d'));
                } catch (\Exception) {
                    $datum = null;
                }
            }
        }

        return $datum ?? throw new Camt053Exception(sprintf('%s: Das Datum (%s) ist ungültig.', $ort, $name));
    }

    /**
     * The trimmed text at a path of local names below $element ("Acct/Id/
     * IBAN"), the first match on every level; null when missing or empty.
     */
    private static function text(?\DOMElement $element, string $pfad): ?string
    {
        foreach (explode('/', $pfad) as $name) {
            $element = self::kind($element, $name);
        }
        if ($element === null) {
            return null;
        }
        $wert = trim($element->textContent);

        return $wert === '' ? null : $wert;
    }

    private static function kind(?\DOMElement $element, string $name): ?\DOMElement
    {
        if ($element === null) {
            return null;
        }
        foreach ($element->childNodes as $kind) {
            if ($kind instanceof \DOMElement && $kind->localName === $name) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @return list<\DOMElement>
     */
    private static function kinder(?\DOMElement $element, string $name): array
    {
        if ($element === null) {
            return [];
        }
        $treffer = [];
        foreach ($element->childNodes as $kind) {
            if ($kind instanceof \DOMElement && $kind->localName === $name) {
                $treffer[] = $kind;
            }
        }

        return $treffer;
    }
}
