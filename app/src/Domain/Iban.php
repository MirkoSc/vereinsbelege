<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * IBAN validation for the public submission's reimbursement field
 * (docs/spec/03-erfassung-und-ki.md section 1: "IBAN Pflicht, mit
 * Prüfziffer-Validierung mod 97", issue #24/M4-2).
 *
 * Two checks: the country's fixed length (SEPA and a few common
 * neighbours - a club's donors and members are not going to bank outside
 * that set, but an unknown country code still gets the checksum check
 * instead of being rejected outright) and the ISO 7064 MOD 97-10 check
 * digits.
 */
final class Iban
{
    /** @var array<string, int> ISO country code => total IBAN length. */
    private const array LAENGE = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28,
        'CZ' => 24, 'DE' => 22, 'DK' => 18, 'EE' => 20, 'ES' => 24, 'FI' => 18,
        'FR' => 27, 'GB' => 22, 'GR' => 27, 'HR' => 21, 'HU' => 28, 'IE' => 22,
        'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21,
        'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28, 'PT' => 25,
        'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27,
    ];

    private function __construct()
    {
        // Static utility, no instances.
    }

    /** Upper case, without spaces - how an IBAN is stored and compared. */
    public static function normalisieren(string $wert): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $wert));
    }

    public static function istGueltig(string $wert): bool
    {
        $iban = self::normalisieren($wert);

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban) !== 1) {
            return false;
        }

        $erwartet = self::LAENGE[substr($iban, 0, 2)] ?? null;
        if ($erwartet !== null ? strlen($iban) !== $erwartet : (strlen($iban) < 15 || strlen($iban) > 34)) {
            return false;
        }

        return self::mod97($iban) === 1;
    }

    /** Grouped in fours for display: "DE89 3704 0044 0532 0130 00". */
    public static function formatieren(string $wert): string
    {
        return trim((string) chunk_split(self::normalisieren($wert), 4, ' '));
    }

    /**
     * ISO 7064 MOD 97-10: move the first four characters to the end, turn
     * letters into two-digit numbers (A=10 ... Z=35) and reduce mod 97 - a
     * valid IBAN always leaves remainder 1. The resulting number is far
     * longer than PHP's integer range, so it is reduced digit by digit
     * instead of being parsed as one.
     */
    private static function mod97(string $iban): int
    {
        $umgestellt = substr($iban, 4) . substr($iban, 0, 4);

        $rest = 0;
        foreach (str_split($umgestellt) as $zeichen) {
            foreach (str_split(ctype_alpha($zeichen) ? (string) (ord($zeichen) - ord('A') + 10) : $zeichen) as $ziffer) {
                $rest = ($rest * 10 + (int) $ziffer) % 97;
            }
        }

        return $rest;
    }
}
