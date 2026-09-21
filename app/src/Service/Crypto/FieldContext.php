<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Where an encrypted field lives: table, row id, column. Becomes the
 * associated data of the field cipher, "tabelle|id|spalte"
 * (docs/spec/01-sicherheit.md section 2).
 *
 * The AAD is what binds a ciphertext to its place: a value copied from
 * another row, another column or another table no longer decrypts, even
 * though all of them are encrypted under keys of the same vault.
 */
final readonly class FieldContext
{
    /**
     * Identifiers are restricted so that no part can contain the separator -
     * without that, ("a|b", "c") and ("a", "b|c") would produce the same AAD
     * and the binding could be sidestepped.
     */
    private const string IDENTIFIER = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        public string $table,
        public int $id,
        public string $column,
    ) {
        self::assertIdentifier($table, 'table');
        self::assertIdentifier($column, 'column');

        if ($id < 1) {
            throw new CryptoException(sprintf('A row id is positive, got %d.', $id));
        }
    }

    public function aad(): string
    {
        return $this->table . '|' . $this->id . '|' . $this->column;
    }

    private static function assertIdentifier(string $value, string $what): void
    {
        if (preg_match(self::IDENTIFIER, $value) !== 1) {
            throw new CryptoException(sprintf('Not a usable %s name: "%s".', $what, $value));
        }
    }
}
