<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * The vault public key of the generation everything new would be sealed to
 * does not carry a valid anchor (issue #240, docs/spec/01-sicherheit.md
 * section 2 "Anker des Tresor-Schlüssels"): nothing is sealed. The message
 * names only the generation - never a key.
 */
final class TresorAnkerException extends \RuntimeException
{
    public function __construct(public readonly int $version, string $message)
    {
        parent::__construct($message);
    }

    public static function abweichung(int $version): self
    {
        return new self($version, sprintf('The vault public key of generation %d does not match its anchor; nothing is sealed.', $version));
    }
}
