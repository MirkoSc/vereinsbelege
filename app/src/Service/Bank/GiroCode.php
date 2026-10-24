<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\Iban;
use App\Support\QrCode;

/**
 * A GiroCode (EPC069-12 "Quick Response Code: Guidelines to Enable Data
 * Capture for the Initiation of a SEPA Credit Transfer", version 002) for
 * one unpaid receipt (issue #86, docs/spec/04-bank-und-abgleich.md section
 * 5 "Stand Backlog: GiroCode", E-40). A banking app scans it and prefills
 * a transfer - the person still checks and confirms it there.
 *
 * Payload: twelve lines at most, separated by LF, UTF-8 (character set 1),
 * BIC optional (version 002), no purpose code, no structured reference;
 * the invoice number goes into the unstructured remittance line. Trailing
 * empty lines are left out, as the guideline asks. Name and remittance are
 * cut to the guideline's lengths (70/140 characters) and, if UTF-8 makes
 * the payload longer than the 331 bytes the guideline allows, shortened
 * further - remittance first, then the name. Everything else that does not
 * fit (IBAN with a wrong check digit, amount out of range, currency other
 * than EUR) is refused instead of producing a code that transfers wrongly.
 *
 * Pure value logic: no repository, no session - App\Service\Bank\Uebersichten
 * decides which receipt and which IBAN.
 */
final readonly class GiroCode
{
    public const int NAME_MAX = 70;

    public const int ZWECK_MAX = 140;

    /** The guideline's maximum payload - also what App\Support\QrCode holds at level M (version 13). */
    public const int PAYLOAD_MAX = 331;

    /** EUR 999,999,999.99 - the largest amount the guideline allows. */
    public const int BETRAG_MAX = 99_999_999_999;

    private function __construct(
        public string $name,
        public string $iban,
        public string $bic,
        public int $cent,
        public string $zweck,
    ) {
    }

    /**
     * @param int $cent the open amount in euro cents, 1 to BETRAG_MAX
     *
     * @throws \InvalidArgumentException with a German message for the page
     */
    public static function erstellen(string $name, string $iban, string $bic, int $cent, string $zweck): self
    {
        $iban = Iban::normalisieren($iban);
        if (!Iban::istGueltig($iban)) {
            throw new \InvalidArgumentException('Die IBAN des Lieferanten ist ungültig (Prüfziffer oder Länge).');
        }
        if ($cent < 1 || $cent > self::BETRAG_MAX) {
            throw new \InvalidArgumentException('Der offene Betrag liegt außerhalb dessen, was ein GiroCode erlaubt (0,01 bis 999.999.999,99 EUR).');
        }
        $name = self::text($name, self::NAME_MAX);
        if ($name === '') {
            throw new \InvalidArgumentException('Für einen GiroCode fehlt der Name des Empfängers.');
        }
        $bic = strtoupper(trim($bic));
        if (preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) !== 1) {
            $bic = '';
        }
        $zweck = self::text($zweck, self::ZWECK_MAX);

        $code = new self($name, $iban, $bic, $cent, $zweck);
        // Only multi-byte characters can push the payload over the limit:
        // shorten the remittance, then the name, one character at a time.
        while (strlen($code->payload()) > self::PAYLOAD_MAX) {
            $code = $code->zweck !== ''
                ? new self($code->name, $iban, $bic, $cent, mb_substr($code->zweck, 0, -1))
                : new self(mb_substr($code->name, 0, -1), $iban, $bic, $cent, '');
        }

        return $code;
    }

    public function payload(): string
    {
        return rtrim(implode("\n", [
            'BCD',
            '002',
            '1',
            'SCT',
            $this->bic,
            $this->name,
            $this->iban,
            'EUR' . self::betrag($this->cent),
            '',
            '',
            $this->zweck,
        ]), "\n");
    }

    public function svg(): string
    {
        return QrCode::svg($this->payload(), 4, 4, 'GiroCode für die Überweisung');
    }

    /** "EUR" amount as the guideline writes it: decimal point, two decimals, no grouping. */
    public static function betrag(int $cent): string
    {
        return sprintf('%d.%02d', intdiv($cent, 100), $cent % 100);
    }

    /**
     * One line of free text: valid UTF-8, no control characters (a line
     * break would shift every later field), runs of white space collapsed,
     * cut to $max characters.
     */
    private static function text(string $wert, int $max): string
    {
        $wert = mb_scrub($wert, 'UTF-8');
        $wert = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', $wert);

        return trim(mb_substr(trim($wert), 0, $max));
    }
}
