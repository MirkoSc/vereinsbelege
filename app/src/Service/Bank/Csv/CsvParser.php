<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

use App\Domain\Iban;
use App\Service\Bank\Umsatz;
use App\Service\Bank\Umsatzdetails;

/**
 * Reads a bank's CSV export with a profile (docs/spec/
 * 04-bank-und-abgleich.md section 3). Framework-free and pure: the bytes
 * come in, value objects go out; nothing is written, nothing logged. The
 * mapping assistant's preview and the import (M9-4) use the very same
 * code, so the preview shows exactly what an import would read.
 *
 * Rules:
 * - The header is the first record (within the first KOPF_SUCHE) that
 *   carries every required column of the profile - a preamble above it is
 *   skipped.
 * - A row with a different number of cells than the header is a row error
 *   (trailing empty cells, as some exports write them, are tolerated).
 * - Booking date required; value date empty = booking date.
 * - Amount: one signed column, or "Soll"/"Haben": a debit counts negative,
 *   a credit positive, whatever sign the bank writes; exactly one of the
 *   two must be filled.
 * - Currency empty or not mapped = EUR; otherwise three letters.
 * - A row whose status column says "vorgemerkt" (pending) is left out and
 *   counted: it is not booked yet and comes again once it is.
 * - Never guessed: a cell that does not fit the profile makes the row an
 *   error, named by line and column, never by its content.
 */
final class CsvParser
{
    public const int KOPF_SUCHE = 30;

    /**
     * @throws CsvException when the file is no text, cannot be decoded or
     *         has no header the profile knows
     */
    public function parse(string $inhalt, CsvProfil $profil): CsvErgebnis
    {
        CsvTabelle::pruefeText($inhalt);
        $zeilen = CsvTabelle::zerlege($profil->zeichensatz->zuUtf8($inhalt), $profil->trennzeichen);

        $kopfIndex = $this->kopf($zeilen, $profil);
        $kopf = array_map(trim(...), $zeilen[$kopfIndex]->zellen);
        $spalten = $profil->aufloesen($kopf);

        $buchungen = [];
        $fehler = [];
        $vorgemerkt = 0;
        foreach (array_slice($zeilen, $kopfIndex + 1) as $zeile) {
            if ($zeile->istLeer()) {
                continue;
            }
            try {
                $buchung = $this->buchung($zeile, $kopf, $spalten, $profil);
            } catch (CsvZeilenfehlerException $e) {
                $fehler[] = new CsvZeilenfehler($zeile->zeile, $e->getMessage());
                continue;
            }
            if ($buchung === null) {
                $vorgemerkt++;
                continue;
            }
            $buchungen[] = $buchung;
        }

        return new CsvErgebnis($kopf, $zeilen[$kopfIndex]->zeile, $buchungen, $fehler, $vorgemerkt);
    }

    /**
     * @param list<CsvZeile> $zeilen
     */
    private function kopf(array $zeilen, CsvProfil $profil): int
    {
        $kandidat = null;
        foreach (array_slice($zeilen, 0, self::KOPF_SUCHE, true) as $index => $zeile) {
            if ($profil->passtZu(array_map(trim(...), $zeile->zellen))) {
                return $index;
            }
            if ($kandidat === null || count($zeile->zellen) > count($zeilen[$kandidat]->zellen)) {
                $kandidat = $index;
            }
        }

        $fehlt = $kandidat === null
            ? CsvFeld::Buchungstag
            : ($profil->fehlendesPflichtfeld(array_map(trim(...), $zeilen[$kandidat]->zellen)) ?? CsvFeld::Buchungstag);

        throw new CsvException(sprintf(
            'Die Datei passt nicht zum Format „%s“: Keine Kopfzeile mit einer Spalte für „%s“ gefunden.',
            $profil->name,
            $fehlt->label(),
        ));
    }

    /**
     * @param list<string>             $kopf
     * @param array<string, list<int>> $spalten
     *
     * @return CsvBuchung|null null for a pending row
     *
     * @throws CsvZeilenfehlerException
     */
    private function buchung(CsvZeile $zeile, array $kopf, array $spalten, CsvProfil $profil): ?CsvBuchung
    {
        $zellen = $zeile->zellen;
        while (count($zellen) > count($kopf) && trim($zellen[count($zellen) - 1]) === '') {
            array_pop($zellen);
        }
        if (count($zellen) !== count($kopf)) {
            throw new CsvZeilenfehlerException(sprintf('Zeile %d: %d statt %d Spalten.', $zeile->zeile, count($zellen), count($kopf)));
        }

        $wert = static function (CsvFeld $feld) use ($zellen, $spalten): string {
            $teile = [];
            foreach ($spalten[$feld->value] ?? [] as $index) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $zellen[$index]));
                if ($text !== '') {
                    $teile[] = $text;
                }
            }

            return implode(' ', $teile);
        };
        $fehler = static fn (CsvFeld $feld, string $was): CsvZeilenfehlerException => new CsvZeilenfehlerException(sprintf(
            'Zeile %d, Spalte „%s“: %s',
            $zeile->zeile,
            $kopf[$spalten[$feld->value][0] ?? -1] ?? $feld->label(),
            $was,
        ));

        if (mb_stripos($wert(CsvFeld::Hinweis), 'vorgemerkt') !== false) {
            return null;
        }

        $datum = $profil->datumsformat;
        $keinDatum = 'kein Datum im Format ' . $datum->label() . '.';
        $buchungstag = $datum->parse($wert(CsvFeld::Buchungstag)) ?? throw $fehler(CsvFeld::Buchungstag, $keinDatum);
        $valutaText = $wert(CsvFeld::Valuta);
        $valuta = $valutaText === '' ? $buchungstag : ($datum->parse($valutaText) ?? throw $fehler(CsvFeld::Valuta, $keinDatum));

        $cent = $this->betrag($wert, $fehler, $profil);

        $waehrung = strtoupper($wert(CsvFeld::Waehrung));
        if ($waehrung === '') {
            $waehrung = 'EUR';
        } elseif (preg_match('/^[A-Z]{3}$/', $waehrung) !== 1) {
            throw $fehler(CsvFeld::Waehrung, 'keine Währungsangabe wie „EUR“.');
        }

        $saldoText = $wert(CsvFeld::Saldo);
        $saldo = $saldoText === ''
            ? null
            : ($profil->dezimaltrenner->cent($saldoText) ?? throw $fehler(CsvFeld::Saldo, 'keine Zahl im Format ' . $profil->dezimaltrenner->label() . '.'));

        $oderNull = static fn (string $text): ?string => $text === '' ? null : $text;
        $sepa = array_filter(
            ['EREF' => $wert(CsvFeld::Eref), 'MREF' => $wert(CsvFeld::Mref), 'CRED' => $wert(CsvFeld::GlaeubigerId)],
            static fn (string $text): bool => $text !== '',
        );
        $zweck = $wert(CsvFeld::Verwendungszweck);

        $details = new Umsatzdetails(
            strukturiert: false,
            gvc: null,
            buchungstext: $oderNull($wert(CsvFeld::Buchungstext)),
            primanota: null,
            verwendungszweck: $zweck,
            verwendungszweckRoh: $zweck,
            sepa: $sepa,
            bic: $oderNull(strtoupper(str_replace(' ', '', $wert(CsvFeld::Bic)))),
            iban: $oderNull(Iban::normalisieren($wert(CsvFeld::Iban))),
            name: $oderNull($wert(CsvFeld::Name)),
            textschluesselergaenzung: null,
        );

        return new CsvBuchung(
            $zeile->zeile,
            new Umsatz($valuta, $buchungstag, $cent, false, '', $sepa['EREF'] ?? 'NONREF', null, null, $details),
            $waehrung,
            $saldo,
        );
    }

    /**
     * @param \Closure(CsvFeld): string                           $wert
     * @param \Closure(CsvFeld, string): CsvZeilenfehlerException $fehler
     *
     * @throws CsvZeilenfehlerException
     */
    private function betrag(\Closure $wert, \Closure $fehler, CsvProfil $profil): int
    {
        $zahl = $profil->dezimaltrenner;
        $keineZahl = 'keine Zahl im Format ' . $zahl->label() . '.';

        if ($profil->spalten(CsvFeld::Betrag) !== []) {
            $text = $wert(CsvFeld::Betrag);

            return $text === ''
                ? throw $fehler(CsvFeld::Betrag, 'kein Betrag.')
                : ($zahl->cent($text) ?? throw $fehler(CsvFeld::Betrag, $keineZahl));
        }

        $sollText = $wert(CsvFeld::Soll);
        $habenText = $wert(CsvFeld::Haben);
        $soll = $sollText === '' ? 0 : ($zahl->cent($sollText) ?? throw $fehler(CsvFeld::Soll, $keineZahl));
        $haben = $habenText === '' ? 0 : ($zahl->cent($habenText) ?? throw $fehler(CsvFeld::Haben, $keineZahl));
        if (($soll === 0) === ($haben === 0)) {
            throw $fehler(CsvFeld::Soll, $soll === 0 ? 'weder Soll noch Haben gefüllt.' : 'Soll und Haben zugleich gefüllt.');
        }

        return $soll !== 0 ? -abs($soll) : abs($haben);
    }
}
