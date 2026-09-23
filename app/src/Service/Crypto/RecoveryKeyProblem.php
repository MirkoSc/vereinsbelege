<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Why a recovery key (`App\Service\Crypto\RecoveryKey`) was rejected -
 * distinguished so a caller can show a specific German message without
 * matching on `RecoveryKeyException::getMessage()` (issue #22/M3-9,
 * docs/spec/01-sicherheit.md section 2).
 *
 * Backed with the string `App\Service\Account\VaultRecovery` stores as the
 * audit log's `grund` detail - never the key itself.
 */
enum RecoveryKeyProblem: string
{
    /** Not 56 characters after normalisation. */
    case Laenge = 'laenge';

    /** A character outside the Crockford alphabet. */
    case Zeichen = 'zeichen';

    /** The leading version byte is not one this code understands. */
    case Version = 'version';

    /** Right shape, wrong checksum - a mistyped or swapped character. */
    case Pruefsumme = 'pruefsumme';

    /** Decodes and checksums fine, but does not match this vault. */
    case FremderTresor = 'fremder_tresor';
}
