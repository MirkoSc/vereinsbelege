<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\Iban;
use App\Domain\SupplierData;
use App\Domain\SupplierKeyKind;

/**
 * Which values of a supplier get a blind index in `supplier_key`, and in
 * which normalised form (M6-2, issue #36, docs/spec/03-erfassung-und-ki.md
 * section 7).
 *
 * The normalisation is the one the automatic resolution (M7-6) compares
 * with: a value found on a receipt only matches if both sides went through
 * the same function. Pure functions without Http, Session or Repository, so
 * the resolution can use them wherever it runs.
 *
 * App\Service\Crypto\BlindIndex::normalize() lower-cases and collapses
 * whitespace on top of this; what is done here is what only knows the kind
 * of value.
 */
final class SupplierKeys
{
    /**
     * Legal forms dropped from the end of a name ("Rechtsform entfernt"),
     * as tokens after normalize() has lower-cased, transliterated and split
     * on punctuation - "GmbH & Co. KG" arrives as gmbh, &, co, kg and goes
     * token by token. Only trailing tokens go: "Sport AG Nord" keeps its AG.
     */
    private const array RECHTSFORMEN = [
        'gmbh', 'ggmbh', 'mbh', 'ag', 'kg', 'kgaa', 'ohg', 'gbr', 'ug', 'haftungsbeschraenkt',
        'co', '&', 'und', 'ev', 'ek', 'ekfm', 'eg', 'se', 'partg', 'mbb', 'ltd', 'limited', 'inc',
        'llc', 'plc', 'sarl', 'sas', 'bv', 'nv', 'srl', 'spa',
    ];

    private const array UMSCHRIFT = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e',
        'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u',
    ];

    private function __construct()
    {
        // Static utility, no instances.
    }

    /**
     * Every key of a supplier, each (kind, value) once.
     *
     * @return list<array{SupplierKeyKind, string}>
     */
    public static function fuer(SupplierData $data): array
    {
        $keys = [];
        $add = static function (SupplierKeyKind $kind, string $wert) use (&$keys): void {
            if ($wert !== '') {
                $keys[$kind->value . "\0" . $wert] = [$kind, $wert];
            }
        };

        foreach ([$data->name, ...$data->aliases] as $name) {
            $add(SupplierKeyKind::Name, self::name($name));
        }
        foreach ($data->ibans as $iban) {
            $add(SupplierKeyKind::Iban, Iban::normalisieren($iban));
        }
        $add(SupplierKeyKind::VatId, self::kennung($data->vatId));
        $add(SupplierKeyKind::TaxNumber, self::steuernummer($data->taxNumber));
        $add(SupplierKeyKind::CreditorId, self::kennung($data->creditorId));
        foreach ($data->mandateRefs as $mandat) {
            $add(SupplierKeyKind::Mandate, self::kennung($mandat));
        }

        return array_values($keys);
    }

    /**
     * The name as the resolution compares it (docs/spec/03-erfassung-und-ki.md
     * section 7, step 4): lower case, umlauts spelled out (ä → ae, ß → ss),
     * accents dropped, punctuation turned into spaces, the legal form at the
     * end removed. "Getränke Müller GmbH & Co. KG" → "getraenke mueller".
     *
     * A name that would be nothing but a legal form keeps its tokens - an
     * empty key would match every other empty key.
     */
    public static function name(string $name): string
    {
        $text = strtr(mb_strtolower(trim($name), 'UTF-8'), self::UMSCHRIFT);
        // "e. V.", "e.K.", "e V" become one token before punctuation turns
        // into spaces: all of them → "ev".
        $text = (string) preg_replace('/\be\s*\.?\s*(v|k|g|kfm)\b\.?/u', 'e$1', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}&]+/u', ' ', $text);
        $text = (string) preg_replace('/&/', ' & ', $text);

        $tokens = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rest = $tokens;
        while ($rest !== [] && in_array($rest[array_key_last($rest)], self::RECHTSFORMEN, true)) {
            array_pop($rest);
        }

        return implode(' ', $rest === [] ? $tokens : $rest);
    }

    /**
     * Lower case with umlauts spelled out and whitespace collapsed - for
     * sorting and searching the decrypted list, where "Müller" should come
     * before "Nagel" and be found by "mueller" as well as "müller".
     */
    public static function umschrift(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strtr(mb_strtolower($text, 'UTF-8'), self::UMSCHRIFT)));
    }

    /**
     * VAT id, creditor id, mandate reference, IBAN-like values: upper case,
     * no whitespace - "de 123 456 789" and "DE123456789" are the same id.
     */
    public static function kennung(string $wert): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $wert));
    }

    /**
     * A tax number is written with slashes, spaces or not at all
     * ("12/345/67890", "12 345 67890"): only letters and digits count.
     */
    public static function steuernummer(string $wert): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $wert));
    }
}
