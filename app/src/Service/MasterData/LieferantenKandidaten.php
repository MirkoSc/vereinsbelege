<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\Iban;
use App\Domain\SupplierKeyKind;

/**
 * What in a receipt's text could identify a known supplier - the
 * blind-index preselection of the prompt `extract-v1` (issue #48/M7-6,
 * docs/spec/03-erfassung-und-ki.md section 6: "bekannte Lieferanten nur als
 * Top-Kandidaten (per Blind-Index-Vorauswahl, max. 10 Namen)").
 *
 * The suppliers' values are only stored as blind indexes (`supplier_key`),
 * so nothing can search the list for a word of the text. The other way
 * round works: every value in the text that looks like an IBAN, a VAT id, a
 * tax number or a creditor id, and every run of one to NGRAMM_MAX words of
 * a line as a name, normalised exactly like the keys (SupplierKeys) - the
 * caller hashes them and asks which suppliers carry one of those keys.
 * A value that is no id at all costs one lookup that finds nothing.
 *
 * Pure functions, no vault, no database. The text is business data: what
 * comes back must not be logged either.
 */
final class LieferantenKandidaten
{
    /** Characters of the text looked at - letterhead and footer of the first pages are where a supplier names itself. */
    public const int TEXT_MAX = 20_000;

    /** Words per name at most ("Getränke Müller Nord GmbH & Co. KG" is four after the legal form goes). */
    public const int NGRAMM_MAX = 4;

    /** Normalised names shorter than this are no candidate - "ab", "nr" would find any short alias. */
    public const int NAME_MIN = 3;

    /** Keys at most, whatever the text holds. */
    public const int SCHLUESSEL_MAX = 20_000;

    private function __construct()
    {
        // Static utility, no instances.
    }

    /**
     * Every (kind, normalised value) of $text, each once, ids before names
     * and in the order they appear.
     *
     * @return list<array{SupplierKeyKind, string}>
     */
    public static function aus(string $text): array
    {
        $text = mb_substr($text, 0, self::TEXT_MAX);
        if (trim($text) === '') {
            return [];
        }
        $schluessel = [];
        $add = static function (SupplierKeyKind $kind, string $wert) use (&$schluessel): void {
            if ($wert !== '' && count($schluessel) < self::SCHLUESSEL_MAX) {
                $schluessel[$kind->value . "\0" . $wert] ??= [$kind, $wert];
            }
        };

        $gross = mb_strtoupper($text);
        foreach (self::ibans($gross) as $iban) {
            $add(SupplierKeyKind::Iban, $iban);
        }
        foreach (self::treffer('/(?<![A-Z0-9])(?:[A-Z]{2} ?[0-9][0-9 ]{6,13}[0-9]|ATU ?[0-9]{8})(?![A-Z0-9])/u', $gross) as $wert) {
            $add(SupplierKeyKind::VatId, SupplierKeys::kennung($wert));
        }
        foreach (self::treffer('/(?<![0-9\/])(?:[0-9]{2,3}[\/ ][0-9]{3,4}[\/ ][0-9]{4,5}|[0-9]{13})(?![0-9\/])/u', $gross) as $wert) {
            $add(SupplierKeyKind::TaxNumber, SupplierKeys::steuernummer($wert));
        }
        foreach (self::treffer('/(?<![A-Z0-9])[A-Z]{2}[0-9]{2} ?[A-Z0-9]{3} ?[0-9]{8,11}(?![A-Z0-9])/u', $gross) as $wert) {
            $add(SupplierKeyKind::CreditorId, SupplierKeys::kennung($wert));
        }

        foreach (preg_split('/\R/u', $text) ?: [] as $zeile) {
            $woerter = preg_split('/\s+/u', trim($zeile), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $anzahl = count($woerter);
            for ($start = 0; $start < $anzahl; $start++) {
                for ($laenge = 1; $laenge <= self::NGRAMM_MAX && $start + $laenge <= $anzahl; $laenge++) {
                    $name = SupplierKeys::name(implode(' ', array_slice($woerter, $start, $laenge)));
                    if (mb_strlen($name) >= self::NAME_MIN) {
                        $add(SupplierKeyKind::Name, $name);
                    }
                }
            }
        }

        return array_values($schluessel);
    }

    /**
     * The valid IBANs of an upper-cased text, written with or without the
     * usual blanks. A match may run into the next word ("… 0130 00 BIC
     * COBADEFF"): the longest prefix with a valid check sum counts.
     *
     * @return list<string>
     */
    private static function ibans(string $gross): array
    {
        $ibans = [];
        foreach (self::treffer('/(?<![A-Z0-9])[A-Z]{2}[0-9]{2}(?: ?[A-Z0-9]){11,40}/u', $gross) as $treffer) {
            $kompakt = Iban::normalisieren($treffer);
            for ($laenge = min(34, strlen($kompakt)); $laenge >= 15; $laenge--) {
                $kandidat = substr($kompakt, 0, $laenge);
                if (Iban::istGueltig($kandidat)) {
                    $ibans[] = $kandidat;
                    break;
                }
            }
        }

        return $ibans;
    }

    /**
     * @return list<string>
     */
    private static function treffer(string $muster, string $text): array
    {
        return preg_match_all($muster, $text, $treffer) > 0 ? $treffer[0] : [];
    }
}
