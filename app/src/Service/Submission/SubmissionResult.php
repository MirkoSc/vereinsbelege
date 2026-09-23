<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * The outcome of App\Service\Submission\SubmissionService::einreichen():
 * either field errors for the form, or the reference number the submitter
 * gets to see (docs/spec/03-erfassung-und-ki.md section 1: "Referenznummer
 * anzeigen").
 */
final readonly class SubmissionResult
{
    /**
     * @param array<string, string> $fehler field name => German message
     */
    private function __construct(
        public array $fehler,
        public ?string $referenz,
    ) {
    }

    public static function erfolg(string $referenz): self
    {
        return new self([], $referenz);
    }

    /**
     * @param array<string, string> $fehler
     */
    public static function fehler(array $fehler): self
    {
        return new self($fehler, null);
    }

    public function istErfolg(): bool
    {
        return $this->referenz !== null;
    }
}
