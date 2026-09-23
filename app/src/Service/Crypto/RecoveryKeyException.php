<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * A recovery key (`RecoveryKey::parse()`/`openVault()`) was rejected -
 * carries {@see RecoveryKeyProblem} so a caller can show a specific German
 * message instead of matching on the English `getMessage()` text
 * (issue #22/M3-9).
 *
 * Extends `CryptoException` so code that only catches the base class (there
 * is none outside `RecoveryKey` itself today) keeps working unchanged.
 */
final class RecoveryKeyException extends CryptoException
{
    public function __construct(string $message, public readonly RecoveryKeyProblem $problem)
    {
        parent::__construct($message);
    }
}
