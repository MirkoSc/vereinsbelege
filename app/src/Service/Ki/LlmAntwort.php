<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * The answer of one chat call: the model's text and what the call log
 * needs (docs/spec/03-erfassung-und-ki.md section 6: model, tokens,
 * duration). $roh is the whole body - business data, kept only so the
 * caller can store it encrypted (M7-5); never log it.
 */
final readonly class LlmAntwort
{
    public function __construct(
        public string $inhalt,
        public string $modell,
        public ?int $promptTokens,
        public ?int $antwortTokens,
        public ?int $tokensGesamt,
        public ?string $abbruchGrund,
        public int $dauerMs,
        public string $roh,
    ) {
    }

    /**
     * A 2xx body of `POST /chat/completions` (OpenAI chat completion
     * object). Shared by the real client and the test double, so both read
     * recorded answers the same way.
     *
     * @param string $profilModell the model of the profile, for providers
     *        that do not name the one that answered
     *
     * @throws LlmFehler when the body is no chat completion with text - a
     *         wrong base URL, or the model refused (`message.refusal`)
     */
    public static function ausChatCompletion(string $body, string $profilModell, int $dauerMs): self
    {
        $json = json_decode($body, true);
        if (!is_array($json) || !is_array($json['choices'][0]['message'] ?? null)) {
            throw LlmFehler::unerwarteteAntwort('Die Antwort ist keine Chat-Completions-Antwort – Basis-URL prüfen.');
        }
        $nachricht = $json['choices'][0]['message'];
        $inhalt = $nachricht['content'] ?? null;
        if (!is_string($inhalt) || trim($inhalt) === '') {
            throw LlmFehler::unerwarteteAntwort(
                is_string($nachricht['refusal'] ?? null) && $nachricht['refusal'] !== ''
                    ? 'Das Modell hat die Antwort verweigert.'
                    : 'Die Antwort enthält keinen Text.',
            );
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $zahl = static fn(mixed $wert): ?int => is_int($wert) && $wert >= 0 ? $wert : null;
        $grund = $json['choices'][0]['finish_reason'] ?? null;

        return new self(
            inhalt: $inhalt,
            modell: is_string($json['model'] ?? null) && $json['model'] !== '' ? $json['model'] : $profilModell,
            promptTokens: $zahl($usage['prompt_tokens'] ?? null),
            antwortTokens: $zahl($usage['completion_tokens'] ?? null),
            tokensGesamt: $zahl($usage['total_tokens'] ?? null),
            abbruchGrund: is_string($grund) ? $grund : null,
            dauerMs: $dauerMs,
            roh: $body,
        );
    }
}
