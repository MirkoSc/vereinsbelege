<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Service\Crypto\Vault;

/**
 * Recomputes the blind indexes of one table during a vault key rotation
 * (issue #90, docs/spec/01-sicherheit.md section 2 "Schlüsselrotation").
 *
 * A blind index is an HMAC under a key derived from VK_priv - a new vault
 * means new index values, or every lookup (duplicate check, IBAN match,
 * supplier resolution) would silently stop finding the old rows. The chain
 * calls pflege() for each row inside the same transaction that re-seals the
 * row's data key, with the row locked: "sealed to the new generation" and
 * "indexed under the new key" change together.
 *
 * Damaged data (a value that does not decrypt) is not a reason to stop the
 * chain: the index is cleared instead - what it pointed to is unreadable
 * anyway, and an index under the retired key would never match again.
 */
interface Indexpflege
{
    /** The table whose rows this handler is called for. */
    public function tabelle(): string;

    /**
     * Every blind index column ("table.column") this handler keeps -
     * checked by a test against the migrations, so a new `_bi` column
     * cannot be forgotten.
     *
     * @return list<string>
     */
    public function spalten(): array;

    /**
     * @param array<string, mixed> $zeile the locked row, before re-sealing
     * @param Vault $tresor the key ring: newest generation plus $alteVersion
     */
    public function pflege(int $id, array $zeile, Vault $tresor, int $alteVersion, \DateTimeImmutable $now): void;

    /**
     * Clears the row's blind indexes instead - when pflege() failed on the
     * row's data. An index under the retired key would never match again;
     * no index is the honest state.
     */
    public function verwerfen(int $id, \DateTimeImmutable $now): void;
}
