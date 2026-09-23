<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * The outcome of App\Service\Submission\InterneErfassung::erfassen() (issue
 * #28/M4-6): either errors - keyed "<receipt index>.<field>", or "belege"
 * for the pass as a whole - or one reference number per receipt, in the
 * order they were sent.
 */
final readonly class InterneErfassungErgebnis
{
    /**
     * @param array<string, string> $fehler
     * @param list<string> $referenzen
     */
    private function __construct(
        public array $fehler,
        public array $referenzen,
    ) {
    }

    /**
     * @param list<string> $referenzen
     */
    public static function erfolg(array $referenzen): self
    {
        return new self([], $referenzen);
    }

    /**
     * @param array<string, string> $fehler
     */
    public static function fehler(array $fehler): self
    {
        return new self($fehler, []);
    }

    public function istErfolg(): bool
    {
        return $this->fehler === [];
    }
}
