<?php

declare(strict_types=1);

namespace App\Tests\Service\Ki;

use App\Service\Ki\KiAnbieterService;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Ki\KiVorlage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parts of the provider profile rules that need no database (M7-1,
 * issue #41): the base URL and the capabilities' storage form. The rest is
 * tests/Integration/KiAnbieterFlowTest.php.
 */
final class KiAnbieterRegelnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function baseUrls(): iterable
    {
        yield 'openai' => ['https://api.openai.com/v1', 'https://api.openai.com/v1'];
        yield 'trailing slash and blanks' => ['  https://api.openai.com/v1/ ', 'https://api.openai.com/v1'];
        yield 'own proxy with port' => ['https://ki.verein.example:8443/v1', 'https://ki.verein.example:8443/v1'];
        yield 'upper-case scheme' => ['HTTPS://api.openai.com/v1', 'HTTPS://api.openai.com/v1'];
        yield 'plain http' => ['http://api.openai.com/v1', null];
        yield 'no scheme' => ['api.openai.com/v1', null];
        yield 'credentials' => ['https://user:pw@api.openai.com/v1', null];
        yield 'query' => ['https://api.openai.com/v1?key=abc', null];
        yield 'fragment' => ['https://api.openai.com/v1#x', null];
        yield 'full endpoint' => ['https://api.openai.com/v1/chat/completions', null];
        yield 'line break' => ["https://api.openai.com/v1\nX-Evil: 1", null];
        yield 'empty' => ['', null];
        yield 'other scheme' => ['file:///etc/passwd', null];
        yield 'too long' => ['https://example.org/' . str_repeat('a', 250), null];
    }

    #[DataProvider('baseUrls')]
    public function testBaseUrlIsHttpsOnlyWithoutExtras(string $eingabe, ?string $erwartet): void
    {
        self::assertSame($erwartet, KiAnbieterService::normalisiereBaseUrl($eingabe));
    }

    public function testCapabilitiesRoundTripThroughJson(): void
    {
        $faehigkeiten = new KiFaehigkeiten(vision: true, jsonSchema: false, maxImages: 3, maxTokens: 1500);

        self::assertSame('{"vision":true,"json_schema":false,"max_images":3,"max_tokens":1500}', $faehigkeiten->toJson());
        self::assertEquals($faehigkeiten, KiFaehigkeiten::fromJson($faehigkeiten->toJson()));
    }

    public function testDamagedCapabilitiesClaimNothing(): void
    {
        self::assertEquals(new KiFaehigkeiten(false, false, 0, 0), KiFaehigkeiten::fromJson('kaputt'));
        self::assertEquals(
            new KiFaehigkeiten(false, false, 0, 0),
            KiFaehigkeiten::fromJson('{"vision":"ja","json_schema":1,"max_images":"4"}'),
        );
    }

    public function testTheTemplatesFollowTheOrderOfE08(): void
    {
        self::assertSame([KiVorlage::OpenAi, KiVorlage::Anthropic, KiVorlage::LlamaCpp], KiVorlage::cases(), 'E-08: OpenAI, then Anthropic, then llama.cpp');
    }

    public function testTheLlamaCppTemplateIsASelfHostedVisionProfileWithJsonSchema(): void
    {
        $vorlage = KiVorlage::LlamaCpp;

        self::assertSame('llama.cpp-Server', $vorlage->label());
        // The application only talks https: the server sits behind a reverse
        // proxy, and the placeholder must be a URL the profile form accepts.
        self::assertStringStartsWith('https://', $vorlage->baseUrl());
        self::assertSame($vorlage->baseUrl(), KiAnbieterService::normalisiereBaseUrl($vorlage->baseUrl()));
        self::assertSame('default', $vorlage->modell());
        // llama-server turns response_format into a grammar; the pages are
        // few because a local vision model has a small context.
        self::assertEquals(new KiFaehigkeiten(vision: true, jsonSchema: true, maxImages: 2, maxTokens: 4000), $vorlage->faehigkeiten());
        self::assertSame(180, $vorlage->timeoutS());
        self::assertGreaterThanOrEqual(KiAnbieterService::TIMEOUT_MIN, $vorlage->timeoutS());
        self::assertLessThanOrEqual(KiAnbieterService::TIMEOUT_MAX, $vorlage->timeoutS());
        self::assertSame('llamacpp', $vorlage->value);
        self::assertSame($vorlage, KiVorlage::tryFrom('llamacpp'));
        self::assertSame(
            'https://llm.example.org/v1/chat/completions',
            rtrim($vorlage->baseUrl(), '/') . '/chat/completions',
        );
    }

    public function testTheAnthropicTemplateUsesTheCompatibleEndpointAndStartsWithoutJsonSchema(): void
    {
        $vorlage = KiVorlage::Anthropic;

        self::assertSame('Anthropic', $vorlage->label());
        self::assertSame('https://api.anthropic.com/v1', $vorlage->baseUrl());
        self::assertSame('https://api.anthropic.com/v1/chat/completions', rtrim($vorlage->baseUrl(), '/') . '/chat/completions');
        self::assertSame('claude-sonnet-5-5', $vorlage->modell());
        // response_format is ignored there: the schema goes into the prompt
        // until "Verbindung testen" says otherwise (03 section 6).
        self::assertEquals(new KiFaehigkeiten(vision: true, jsonSchema: false, maxImages: 4, maxTokens: 4000), $vorlage->faehigkeiten());
        self::assertSame(60, $vorlage->timeoutS());
        self::assertSame('anthropic', $vorlage->value);
        self::assertSame($vorlage, KiVorlage::tryFrom('anthropic'));
    }

    public function testTheOpenAiTemplateIsTheFirstAndAcceptsItsOwnUrl(): void
    {
        self::assertSame(KiVorlage::OpenAi, KiVorlage::cases()[0], 'E-08: OpenAI first');
        foreach (KiVorlage::cases() as $vorlage) {
            self::assertSame($vorlage->baseUrl(), KiAnbieterService::normalisiereBaseUrl($vorlage->baseUrl()), $vorlage->label());
            self::assertTrue($vorlage->faehigkeiten()->maxImages <= KiFaehigkeiten::MAX_IMAGES_GRENZE);
        }
    }
}
