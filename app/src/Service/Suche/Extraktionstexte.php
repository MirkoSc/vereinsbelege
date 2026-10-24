<?php

declare(strict_types=1);

namespace App\Service\Suche;

/**
 * The searchable strings of an extraction in the format `extract-v1`
 * (docs/spec/03-erfassung-und-ki.md section 6) - written by the AI and by
 * the e-invoice reader alike. Free texts and numbers a person would look
 * for; enums, ids, dates and confidences are left out (dates and categories
 * are what the lists filter by, not what one types).
 *
 * The gross amount is added in German notation ("1234,56"), the way it is
 * printed on a receipt and typed into a search field.
 */
final class Extraktionstexte
{
    private const array LIEFERANT = ['name', 'address', 'iban', 'bic', 'vat_id', 'tax_number', 'email', 'website', 'creditor_id'];

    private const array OBEN = ['invoice_number', 'customer_number', 'contract_number', 'purpose_short'];

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $extraktion
     *
     * @return list<string>
     */
    public static function aus(array $extraktion): array
    {
        $texte = [];
        $lieferant = is_array($extraktion['supplier'] ?? null) ? $extraktion['supplier'] : [];
        foreach (self::LIEFERANT as $feld) {
            $texte[] = $lieferant[$feld] ?? null;
        }
        foreach (self::OBEN as $feld) {
            $texte[] = $extraktion[$feld] ?? null;
        }
        $texte[] = is_array($extraktion['payment'] ?? null) ? ($extraktion['payment']['mandate_reference'] ?? null) : null;
        $texte[] = is_array($extraktion['recurring'] ?? null) ? ($extraktion['recurring']['evidence'] ?? null) : null;

        $brutto = $extraktion['total_gross'] ?? null;
        if (is_string($brutto) && preg_match('/^-?[0-9]+(\.[0-9]{1,2})?$/', $brutto) === 1) {
            $texte[] = str_replace('.', ',', $brutto);
        }

        return array_values(array_filter($texte, static fn(mixed $t): bool => is_string($t) && trim($t) !== ''));
    }
}
