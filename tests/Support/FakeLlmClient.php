<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ki\KiAnbieter;
use App\Service\Ki\LlmAnfrage;
use App\Service\Ki\LlmAntwort;
use App\Service\Ki\LlmClient;

/**
 * An LlmClient double (CLAUDE.md section 6): answers recorded under
 * tests/fixtures/llm/ - read the way the real client reads a provider's
 * body, LlmAntwort::ausChatCompletion() - or exceptions (LlmFehler), in
 * order, and records every request as the caller built it. No test talks
 * to a real AI provider.
 */
final class FakeLlmClient implements LlmClient
{
    /** @var list<array{profil: KiAnbieter, apiKey: string, anfrage: LlmAnfrage}> */
    public array $anfragen = [];

    /**
     * @param list<LlmAntwort|\Throwable> $antworten
     */
    public function __construct(private array $antworten)
    {
    }

    /**
     * A recorded chat completion body under tests/fixtures/llm/.
     */
    public static function fixture(string $pfad, string $profilModell = 'gpt-6-luna', int $dauerMs = 1200): LlmAntwort
    {
        return LlmAntwort::ausChatCompletion(FakeChatHttp::fixture($pfad)->body, $profilModell, $dauerMs);
    }

    public function chat(KiAnbieter $profil, #[\SensitiveParameter] string $apiKey, LlmAnfrage $anfrage): LlmAntwort
    {
        $this->anfragen[] = ['profil' => $profil, 'apiKey' => $apiKey, 'anfrage' => $anfrage];

        $antwort = array_shift($this->antworten) ?? throw new \LogicException('FakeLlmClient: no answer left.');
        if ($antwort instanceof \Throwable) {
            throw $antwort;
        }

        return $antwort;
    }
}
