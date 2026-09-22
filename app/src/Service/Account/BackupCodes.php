<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * Ten one-time backup codes (docs/spec/01-sicherheit.md section 3), shown
 * once at 2FA setup and whenever they are regenerated
 * (App\Service\Account\MfaEnrollment).
 *
 * Formatting follows the same Crockford Base32 rule
 * App\Service\Crypto\RecoveryKey already established for the recovery key:
 * no I/L/O/U, so nothing a human copies by hand can be misread, and reading
 * back accepts the same forgiving substitutions. Codes are short on purpose
 * (they are typed once, under time pressure, standing in for a lost phone) -
 * two groups of five is plenty of entropy for something that is also rate
 * limited and single-use.
 */
final class BackupCodes
{
    public const int COUNT = 10;

    public const int GROUP_LENGTH = 5;

    public const int GROUPS = 2;

    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * @return list<string> ten codes, formatted as "XXXXX-XXXXX"
     */
    public static function generate(): array
    {
        $codes = [];
        while (count($codes) < self::COUNT) {
            $code = self::randomCode();
            // Vanishingly unlikely to collide, but a set of ten displayed
            // codes must not contain a visible duplicate.
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Same normalisation as App\Service\Crypto\RecoveryKey::normalizeGroup():
     * case and separators are dropped, `O`->`0`, `I`/`L`->`1`, so a code
     * typed by hand compares the way it was read off the page, not the way
     * it was stored.
     */
    public static function normalize(string $input): string
    {
        return strtr(strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $input) ?? ''), [
            'O' => '0',
            'I' => '1',
            'L' => '1',
        ]);
    }

    private static function randomCode(): string
    {
        $groups = [];
        for ($i = 0; $i < self::GROUPS; $i++) {
            $group = '';
            for ($j = 0; $j < self::GROUP_LENGTH; $j++) {
                $group .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }
}
