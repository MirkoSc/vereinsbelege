<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Thrown when a ciphertext does not decrypt: wrong key, wrong AAD, a
 * truncated or manipulated value, an unknown format version.
 *
 * Messages carry only structural information (table, column, row id) - never
 * a key, never a plaintext (CLAUDE.md section 4).
 *
 * Not `final`: {@see RecoveryKeyException} extends it so existing callers
 * that catch this base class keep working unchanged.
 */
class CryptoException extends \RuntimeException
{
}
