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

    public function testTheOpenAiTemplateIsTheFirstAndAcceptsItsOwnUrl(): void
    {
        self::assertSame(KiVorlage::OpenAi, KiVorlage::cases()[0], 'E-08: OpenAI first');
        foreach (KiVorlage::cases() as $vorlage) {
            self::assertSame($vorlage->baseUrl(), KiAnbieterService::normalisiereBaseUrl($vorlage->baseUrl()), $vorlage->label());
            self::assertTrue($vorlage->faehigkeiten()->maxImages <= KiFaehigkeiten::MAX_IMAGES_GRENZE);
        }
    }
}
