<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * What the newest `ai_extract` run left at a document (issue #47/M7-5),
 * decrypted for the review page - the AI's counterpart of
 * App\Service\Document\ERechnungAuszug. Business data: lives only in the
 * request that opened it.
 *
 * $extraktion is the suggestion in the shape of `extract-v1` after the
 * server's checks, null when the AI failed (the document then stands at
 * `ki_fehler` and $meldung says why).
 */
final readonly class KiAuszug
{
    /**
     * @param ?array<string, mixed> $extraktion
     * @param list<string> $hinweise the server's findings (Auslesung::auswerten())
     * @param list<string> $unsicher `confidence` keys below the threshold
     */
    public function __construct(
        public string $meldung,
        public ?array $extraktion,
        public array $hinweise,
        public array $unsicher,
        public string $anbieter,
        public string $modell,
        public bool $fallback,
    ) {
    }

    public function gueltig(): bool
    {
        return $this->extraktion !== null;
    }

    /**
     * The model's own `warnings`, then the server's findings - what the
     * review page shows above the form.
     *
     * @return list<string>
     */
    public function warnungen(): array
    {
        $warnungen = is_array($this->extraktion['warnings'] ?? null) ? $this->extraktion['warnings'] : [];

        return [
            ...array_values(array_filter($warnungen, static fn(mixed $w): bool => is_string($w) && trim($w) !== '')),
            ...$this->hinweise,
        ];
    }
}
