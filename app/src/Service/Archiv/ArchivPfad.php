<?php

declare(strict_types=1);

namespace App\Service\Archiv;

use App\Domain\InvoiceDirection;
use App\Service\Export\PfadMuster;
use App\Service\Export\ZipExport;

/**
 * The folder semantics of the archive import (issue #77/M12-3,
 * docs/spec/05-auswertung-und-export.md section 3). One path in, one
 * App\Service\Archiv\ArchivVorbelegung out:
 *
 * - the file name: App\Service\Archiv\DateinamenMuster;
 * - a folder `Kasse` → paid in cash (kept as a suggestion until receipts
 *   get payments, M10-1); `Wiedervorlage` or the export's `_Wiedervorlage`
 *   → the receipt starts in Wiedervorlage;
 * - `Ausgaben` / `Einnahmen` (the export's `{richtung}`) → the direction;
 * - a year folder (`2026`, the export's root `Belege_2026`) and a month
 *   folder (`02`, `02. Februar`, `Februar`) only check the date of the
 *   file name - a mismatch is a hint, never a date of its own: a folder
 *   names no day;
 * - a folder named exactly like an active category of the direction (case
 *   aside) → that category; the deepest such folder wins;
 * - `_Originale/` (the export's copies of the originals), `__MACOSX/`,
 *   hidden files, `Thumbs.db`, `desktop.ini` → skipped;
 * - every other folder (a supplier folder, "Belege", "Scans") → ignored.
 *
 * Pure: no database, no session, no vault. The categories come in as
 * id => name per direction.
 */
final class ArchivPfad
{
    private const array MONATE = [
        'januar' => 1, 'jan' => 1, 'februar' => 2, 'feb' => 2, 'märz' => 3, 'maerz' => 3, 'mrz' => 3, 'mär' => 3,
        'april' => 4, 'apr' => 4, 'mai' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9, 'oktober' => 10, 'okt' => 10, 'november' => 11, 'nov' => 11,
        'dezember' => 12, 'dez' => 12,
    ];

    private const array SYSTEMDATEIEN = ['thumbs.db', 'desktop.ini', 'ehthumbs.db'];

    private function __construct()
    {
    }

    /**
     * @param array<int, string> $kategorienAusgabe active categories an expense may have, id => name
     * @param array<int, string> $kategorienEinnahme the same for income
     */
    public static function analysieren(
        string $pfad,
        DateinamenMuster $muster,
        array $kategorienAusgabe,
        array $kategorienEinnahme,
        \DateTimeImmutable $heute,
    ): ArchivVorbelegung {
        $ebenen = $pfad === '' ? [] : explode('/', $pfad);
        $datei = (string) array_pop($ebenen);
        $ordnerKlein = array_map(static fn(string $o): string => mb_strtolower(trim($o)), $ebenen);

        if (in_array('__macosx', $ordnerKlein, true) || str_starts_with($datei, '.') || in_array(mb_strtolower($datei), self::SYSTEMDATEIEN, true)) {
            return new ArchivVorbelegung($pfad, ueberspringen: 'Systemdatei', systemdatei: true);
        }
        if (in_array(mb_strtolower(ZipExport::ORDNER_ORIGINALE), $ordnerKlein, true)) {
            return new ArchivVorbelegung($pfad, ueberspringen: 'Kopie eines Originals aus einem Export (Ordner „_Originale“) – das Original liegt im Hauptordner.');
        }

        $kasse = false;
        $wiedervorlage = false;
        $richtung = null;
        $jahre = [];
        $monate = [];
        foreach ($ordnerKlein as $ordner) {
            if ($ordner === 'kasse') {
                $kasse = true;
            } elseif ($ordner === 'wiedervorlage' || $ordner === mb_strtolower(ZipExport::ORDNER_WIEDERVORLAGE)) {
                $wiedervorlage = true;
            } elseif ($ordner === 'ausgaben') {
                $richtung = InvoiceDirection::Ausgabe;
            } elseif ($ordner === 'einnahmen') {
                $richtung = InvoiceDirection::Einnahme;
            } elseif (($jahr = self::jahr($ordner)) !== null) {
                $jahre[$jahr] = $ordner;
            } elseif (($monat = self::monat($ordner)) !== null) {
                $monate[$monat] = $ordner;
            }
        }

        $richtungAusOrdner = $richtung !== null;
        $richtung ??= InvoiceDirection::Ausgabe;
        $kategorien = $richtung === InvoiceDirection::Ausgabe ? $kategorienAusgabe : $kategorienEinnahme;
        $kategorieId = null;
        foreach ($ebenen as $ordner) {
            $treffer = self::kategorie($ordner, $kategorien);
            if ($treffer !== null) {
                $kategorieId = $treffer;
            }
        }

        $hinweise = [];
        $treffer = $muster->lies($datei, (int) $heute->format('Y') + 1);
        if ($treffer === null) {
            $hinweise[] = sprintf('Der Dateiname passt nicht zum Muster „%s“ – Lieferant und Datum nicht vorbelegt.', $muster->muster);
        } else {
            $hinweise = [...$hinweise, ...$treffer->hinweise];
        }

        $datum = $treffer?->datum;
        if ($datum !== null) {
            foreach ($jahre as $jahr => $name) {
                if ($jahr !== (int) $datum->format('Y')) {
                    $hinweise[] = sprintf('Der Ordner „%s“ passt nicht zum Datum %s im Dateinamen.', $name, $datum->format('d.m.Y'));
                }
            }
            foreach ($monate as $monat => $name) {
                if ($monat !== (int) $datum->format('n')) {
                    $hinweise[] = sprintf('Der Ordner „%s“ passt nicht zum Datum %s im Dateinamen.', $name, $datum->format('d.m.Y'));
                }
            }
        }

        return new ArchivVorbelegung(
            pfad: $pfad,
            lieferant: $treffer?->lieferant,
            ohneLieferant: $treffer !== null && $treffer->ohneLieferant,
            datum: $datum,
            nr: $treffer?->nr,
            kategorieId: $kategorieId,
            richtung: $richtung,
            richtungAusOrdner: $richtungAusOrdner,
            kasse: $kasse,
            wiedervorlage: $wiedervorlage,
            musterPasst: $treffer !== null,
            hinweise: $hinweise,
        );
    }

    /** `2026` or the export's root `Belege_2026`. */
    private static function jahr(string $ordner): ?int
    {
        if (preg_match('/^(?:belege[_ ])?(\d{4})$/u', $ordner, $t) !== 1) {
            return null;
        }
        $jahr = (int) $t[1];

        return $jahr >= DateinamenMuster::ERSTES_JAHR && $jahr <= 2100 ? $jahr : null;
    }

    /** `02`, `2`, `02. Februar` (the export's `{monatsname}`), `Februar`, `Feb`. */
    private static function monat(string $ordner): ?int
    {
        if (preg_match('/^(\d{1,2})(?:\.?\s*([\p{L}]+))?$/u', $ordner, $t) === 1) {
            $zahl = (int) $t[1];
            if ($zahl < 1 || $zahl > 12) {
                return null;
            }
            if (($t[2] ?? '') !== '' && (self::MONATE[$t[2]] ?? null) !== $zahl) {
                return null;
            }

            return $zahl;
        }

        return self::MONATE[$ordner] ?? null;
    }

    /**
     * @param array<int, string> $kategorien
     */
    private static function kategorie(string $ordner, array $kategorien): ?int
    {
        $ordner = mb_strtolower(trim($ordner));
        if ($ordner === '' || $ordner === mb_strtolower(PfadMuster::OHNE_KATEGORIE)) {
            return null;
        }
        foreach ($kategorien as $id => $name) {
            if (mb_strtolower(trim(ZipLeser::nfc($name))) === $ordner) {
                return $id;
            }
        }

        return null;
    }
}
