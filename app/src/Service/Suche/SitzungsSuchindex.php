<?php

declare(strict_types=1);

namespace App\Service\Suche;

/**
 * The few bytes of the full-text index that live in the PHP session (issue
 * #94): which index directory belongs to this session, how far the build
 * got, and the index key - sealed to the vault's public key like every
 * `dek_sealed`, so opening it needs the unlocked vault, which needs the
 * cookie `__Host-vk` (App\Service\Account\SessionVault). The session files
 * alone therefore open nothing, and the session stays small: the index
 * itself lies encrypted in App\Service\Suche\SuchindexSpeicher.
 *
 * No state of its own, like SessionVault: everything is in $_SESSION.
 *
 *     $_SESSION['suchindex'] = [v, id, schluessel, benutzer, bereich,
 *                               cursor, teile, fertig, mitglieder]
 */
final class SitzungsSuchindex
{
    // 2: `nachholen`, the reservations below the cursor (issue #53).
    public const int VERSION = 2;

    private const string SESSION_KEY = 'suchindex';

    /**
     * @return array{id: string, schluessel: string, benutzer: int, bereich: string, cursor: int, teile: int, fertig: bool, mitglieder: string, nachholen: list<int>}|null
     *         null when there is none or it is not in a format this release writes
     */
    public function lies(): ?array
    {
        $wert = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($wert) || ($wert['v'] ?? null) !== self::VERSION) {
            return null;
        }
        if (!is_string($wert['id'] ?? null) || !is_string($wert['schluessel'] ?? null) || !is_string($wert['bereich'] ?? null)
            || !is_int($wert['benutzer'] ?? null) || !is_int($wert['cursor'] ?? null) || !is_int($wert['teile'] ?? null)
            || !is_bool($wert['fertig'] ?? null) || !is_string($wert['mitglieder'] ?? null)
            || !is_array($wert['nachholen'] ?? null) || !array_is_list($wert['nachholen'])
            || array_filter($wert['nachholen'], is_int(...)) !== $wert['nachholen']) {
            return null;
        }

        return [
            'id' => $wert['id'],
            'schluessel' => $wert['schluessel'],
            'benutzer' => $wert['benutzer'],
            'bereich' => $wert['bereich'],
            'cursor' => $wert['cursor'],
            'teile' => $wert['teile'],
            'fertig' => $wert['fertig'],
            'mitglieder' => $wert['mitglieder'],
            'nachholen' => $wert['nachholen'],
        ];
    }

    /**
     * @param array{id: string, schluessel: string, benutzer: int, bereich: string, cursor: int, teile: int, fertig: bool, mitglieder: string, nachholen: list<int>} $zustand
     */
    public function schreibe(array $zustand): void
    {
        $_SESSION[self::SESSION_KEY] = ['v' => self::VERSION, ...$zustand];
    }

    /**
     * Forgets the entry and removes the index files it pointed to - the one
     * way an index is thrown away (logout, "neu aufbauen", a broken part).
     * Needs no database and no key.
     */
    public function verwerfen(SuchindexSpeicher $speicher): void
    {
        $id = $this->vergiss();
        if ($id !== null) {
            $speicher->loesche($id);
        }
    }

    /** Forgets the entry and returns the index id it pointed to, if any. */
    public function vergiss(): ?string
    {
        $wert = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        return is_array($wert) && is_string($wert['id'] ?? null) ? $wert['id'] : null;
    }
}
