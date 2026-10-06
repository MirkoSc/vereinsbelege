<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ki\ChatHttp;
use App\Service\Ki\ChatHttpAntwort;

/**
 * A ChatHttp double: answers from tests/fixtures/llm/ (recorded provider
 * answers) or exceptions, in order, and records what was sent - so no test
 * ever talks to a real AI provider (CLAUDE.md section 6).
 */
final class FakeChatHttp implements ChatHttp
{
    /** @var list<array{url: string, apiKey: string, body: array<mixed>, timeoutS: int}> */
    public array $anfragen = [];

    /**
     * @param list<ChatHttpAntwort|\Throwable> $antworten
     */
    public function __construct(private array $antworten)
    {
    }

    /**
     * An answer from a recorded body under tests/fixtures/llm/.
     */
    public static function fixture(string $pfad, int $status = 200): ChatHttpAntwort
    {
        $datei = dirname(__DIR__) . '/fixtures/llm/' . $pfad;
        $body = file_get_contents($datei);
        if ($body === false) {
            throw new \LogicException('Missing fixture: ' . $pfad);
        }

        return new ChatHttpAntwort($status, $body);
    }

    public function post(string $url, #[\SensitiveParameter] string $apiKey, string $jsonBody, int $timeoutS): ChatHttpAntwort
    {
        $body = json_decode($jsonBody, true, flags: JSON_THROW_ON_ERROR);
        $this->anfragen[] = ['url' => $url, 'apiKey' => $apiKey, 'body' => is_array($body) ? $body : [], 'timeoutS' => $timeoutS];

        $antwort = array_shift($this->antworten) ?? throw new \LogicException('FakeChatHttp: no answer left.');
        if ($antwort instanceof \Throwable) {
            throw $antwort;
        }

        return $antwort;
    }
}
