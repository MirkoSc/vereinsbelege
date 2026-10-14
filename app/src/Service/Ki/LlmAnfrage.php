<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * What one chat call asks, independent of the provider: a system prompt,
 * one user message (text plus page images) and, optionally, the JSON schema
 * the answer has to follow. How that becomes a request - images only for
 * `vision`, `response_format` or the schema in the prompt - is the
 * client's business (OpenAiCompatibleClient), because it depends on the
 * profile.
 *
 * Holds business data (the receipt's text and pages): never log it, never
 * put it into an exception message.
 */
final readonly class LlmAnfrage
{
    /**
     * @param list<string> $bilder JPEG bytes, in page order
     * @param list<array{role: 'assistant'|'user', content: string}> $nachtrag
     *        the conversation after the first user message - the repair
     *        call appends the old answer and the list of errors
     */
    public function __construct(
        public string $system,
        public string $text,
        public array $bilder = [],
        public ?KiSchema $schema = null,
        public array $nachtrag = [],
    ) {
        foreach ($bilder as $bild) {
            if (!str_starts_with($bild, "\xFF\xD8\xFF")) {
                throw new \InvalidArgumentException('Only JPEG images can be sent to the AI provider.');
            }
        }
    }

    /**
     * The same request, continued: the model's last answer and a further
     * user message.
     */
    public function mitNachtrag(string $antwort, string $nachfrage): self
    {
        return new self($this->system, $this->text, $this->bilder, $this->schema, [
            ...$this->nachtrag,
            ['role' => 'assistant', 'content' => $antwort],
            ['role' => 'user', 'content' => $nachfrage],
        ]);
    }
}
