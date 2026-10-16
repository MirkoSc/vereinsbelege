<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * Outcome of a connection test, worded for the admin page. Holds no API
 * key and no business data - the test sends a fixed prompt and a generated
 * picture, never a receipt.
 */
final readonly class VerbindungstestErgebnis
{
    /**
     * @param list<string> $details what was checked and what came back
     *        (model, tokens, picture, schema)
     * @param ?KiFaehigkeiten $erkannt what the probes found out - only on
     *        success, null when nothing could be determined (the profile
     *        stays as it is then)
     */
    public function __construct(
        public bool $ok,
        public string $meldung,
        public array $details = [],
        public ?int $dauerMs = null,
        public ?KiFaehigkeiten $erkannt = null,
    ) {
    }

    /**
     * One line for the flash message of the admin page.
     */
    public function text(): string
    {
        $teile = [$this->meldung, ...$this->details];
        if ($this->dauerMs !== null) {
            $teile[] = sprintf('Dauer: %s s', number_format($this->dauerMs / 1000, 1, ',', '.'));
        }

        return implode(' · ', $teile);
    }
}
