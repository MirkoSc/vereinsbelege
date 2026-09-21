<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Thrown when a ciphertext does not decrypt: wrong key, wrong AAD, a
 * truncated or manipulated value, an unknown format version.
 *
 * Messages carry only structural information (table, column, row id) - never
 * a key, never a plaintext (CLAUDE.md section 4).
 */
final class CryptoException extends \RuntimeException
{
}
