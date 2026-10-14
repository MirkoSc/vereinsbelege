<?php

declare(strict_types=1);

namespace App\Tests\Service\Ki;

use App\Service\Ki\ChatHttpAntwort;
use App\Service\Ki\KiAnbieter;
use App\Service\Ki\KiAufrufStatus;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Ki\KiPrompts;
use App\Service\Ki\KiVerbindungsfehler;
use App\Service\Ki\LlmAnfrage;
use App\Service\Ki\LlmFehler;
use App\Service\Ki\OpenAiCompatibleClient;
use App\Tests\Support\FakeChatHttp;
use PHPUnit\Framework\TestCase;

/**
 * The one client for every provider (M7-2, issue #44,
 * docs/spec/03-erfassung-und-ki.md section 6 "Client") against recorded
 * answers (tests/fixtures/llm/): what goes on the wire for each set of
 * capabilities, and how answers and errors come back - never with the key.
 */
final class OpenAiCompatibleClientTest extends TestCase
{
    private const string KEY = 'sk-test-0000-nicht-echt-AbCdWxYz';

    public function testWithVisionAndJsonSchemaPagesGoAsImagesAndTheSchemaAsStrictResponseFormat(): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('extract-v1/gueltig.json')]);
        $bilder = [self::jpeg('a'), self::jpeg('b'), self::jpeg('c')];
        $prompts = self::prompts();
        $anfrage = new LlmAnfrage('System: lies den Beleg.', 'Beleg mit drei Seiten.', $bilder, $prompts->schema('extract-v1'));

        $antwort = new OpenAiCompatibleClient($http, $prompts)->chat(self::profil(vision: true, jsonSchema: true, maxImages: 2), self::KEY, $anfrage);

        self::assertCount(1, $http->anfragen);
        $request = $http->anfragen[0];
        self::assertSame('https://api.example.org/v1/chat/completions', $request['url']);
        self::assertSame(self::KEY, $request['apiKey'], 'Bearer');
        self::assertSame(45, $request['timeoutS'], "the profile's timeout");

        $body = $request['body'];
        self::assertSame('gpt-6-luna', $body['model']);
        self::assertSame(4000, $body['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertArrayNotHasKey('temperature', $body, 'newer OpenAI models refuse it');
        self::assertSame(['role' => 'system', 'content' => 'System: lies den Beleg.'], $body['messages'][0], 'no schema in the prompt when the provider enforces it');
        self::assertCount(2, $body['messages']);

        $inhalt = $body['messages'][1]['content'];
        self::assertSame('user', $body['messages'][1]['role']);
        self::assertSame(['type' => 'text', 'text' => 'Beleg mit drei Seiten.'], $inhalt[0]);
        self::assertCount(3, $inhalt, 'text + max_images pictures, the third page only as text');
        self::assertSame('data:image/jpeg;base64,' . base64_encode($bilder[0]), $inhalt[1]['image_url']['url']);
        self::assertSame('data:image/jpeg;base64,' . base64_encode($bilder[1]), $inhalt[2]['image_url']['url']);

        self::assertSame('json_schema', $body['response_format']['type']);
        self::assertSame('extract-v1', $body['response_format']['json_schema']['name']);
        self::assertTrue($body['response_format']['json_schema']['strict']);
        self::assertSame($prompts->schema('extract-v1')->schema, $body['response_format']['json_schema']['schema']);

        self::assertStringStartsWith('{"document_type":"rechnung"', $antwort->inhalt);
        self::assertSame('gpt-6-luna-2026-08-01', $antwort->modell, 'the model that answered');
        self::assertSame(1850, $antwort->promptTokens);
        self::assertSame(240, $antwort->antwortTokens);
        self::assertSame(2090, $antwort->tokensGesamt);
        self::assertSame('stop', $antwort->abbruchGrund);
        self::assertGreaterThanOrEqual(0, $antwort->dauerMs);
        self::assertSame(FakeChatHttp::fixture('extract-v1/gueltig.json')->body, $antwort->roh);
    }

    public function testWithoutCapabilitiesTheRequestIsTextOnlyAndTheSchemaGoesIntoTheSystemPrompt(): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('extract-v1/prosa-mit-json.json')]);
        $prompts = self::prompts();
        $schema = $prompts->schema('extract-v1');

        $antwort = new OpenAiCompatibleClient($http, $prompts)->chat(
            self::profil(vision: false, jsonSchema: false, maxImages: 0),
            self::KEY,
            new LlmAnfrage('Lies den Beleg.', 'Seitentext', [self::jpeg('a')], $schema),
        );

        $body = $http->anfragen[0]['body'];
        self::assertArrayNotHasKey('response_format', $body);
        self::assertSame('Seitentext', $body['messages'][1]['content'], 'no picture without vision');
        $system = $body['messages'][0]['content'];
        self::assertStringStartsWith("Lies den Beleg.\n\nAntworte ausschließlich mit einem einzigen JSON-Objekt", $system);
        self::assertStringContainsString($schema->alsJson(), $system);

        self::assertSame('claude-test-model', $antwort->modell);
        self::assertNull($antwort->promptTokens, 'not every provider reports usage');
        self::assertNull($antwort->tokensGesamt);
    }

    public function testWithoutSchemaNothingAboutJsonIsAddedAndTheRepairConversationFollowsTheFirstMessage(): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('extract-v1/kein-json.json')]);
        $anfrage = new LlmAnfrage('', 'Hallo')->mitNachtrag('alte Antwort', 'Bitte korrigieren');

        new OpenAiCompatibleClient($http, self::prompts())->chat(self::profil(vision: true, jsonSchema: true, maxImages: 2), self::KEY, $anfrage);

        $body = $http->anfragen[0]['body'];
        self::assertArrayNotHasKey('response_format', $body);
        self::assertSame([
            ['role' => 'user', 'content' => 'Hallo'],
            ['role' => 'assistant', 'content' => 'alte Antwort'],
            ['role' => 'user', 'content' => 'Bitte korrigieren'],
        ], $body['messages'], 'no empty system message');
    }

    public function testAnHttpErrorBecomesLlmFehlerWithoutTheKeyAndWithoutTheProvidersText(): void
    {
        $body = str_replace('sk-test-AbCd****************************WxYz', self::KEY, FakeChatHttp::fixture('verbindungstest/401-key-falsch.json')->body);
        $http = new FakeChatHttp([new ChatHttpAntwort(401, $body)]);

        $fehler = self::fehler(static fn() => new OpenAiCompatibleClient($http, self::prompts())->chat(self::profil(), self::KEY, new LlmAnfrage('', 'x')));

        self::assertSame(KiAufrufStatus::HttpFehler, $fehler->status);
        self::assertSame(401, $fehler->httpStatus);
        self::assertSame('Der KI-Anbieter hat die Anfrage abgelehnt. Zugriff verweigert (HTTP 401) – API-Key prüfen.', $fehler->getMessage());
        self::assertStringContainsString('Incorrect API key provided: ***', $fehler->anbieterText);
        self::assertStringNotContainsString(self::KEY, $fehler->anbieterText);
        self::assertStringNotContainsString('WxYz', $fehler->anbieterText);
    }

    public function testAProxyErrorPageIsAnHttpError(): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('verbindungstest/502-proxy.html', 502)]);

        $fehler = self::fehler(static fn() => new OpenAiCompatibleClient($http, self::prompts())->chat(self::profil(), self::KEY, new LlmAnfrage('', 'x')));

        self::assertSame(KiAufrufStatus::HttpFehler, $fehler->status);
        self::assertSame(502, $fehler->httpStatus);
        self::assertStringContainsString('HTTP 502', $fehler->getMessage());
    }

    public function testTimeoutAndConnectionFailureAreToldApart(): void
    {
        $http = new FakeChatHttp([KiVerbindungsfehler::zeitueberschreitung(45), KiVerbindungsfehler::verbindung('Could not resolve host')]);
        $client = new OpenAiCompatibleClient($http, self::prompts());

        $zeit = self::fehler(static fn() => $client->chat(self::profil(), self::KEY, new LlmAnfrage('', 'x')));
        $netz = self::fehler(static fn() => $client->chat(self::profil(), self::KEY, new LlmAnfrage('', 'x')));

        self::assertSame(KiAufrufStatus::Zeitueberschreitung, $zeit->status);
        self::assertSame('Der KI-Anbieter hat nicht rechtzeitig geantwortet.', $zeit->getMessage());
        self::assertNull($zeit->httpStatus);
        self::assertSame(KiAufrufStatus::Verbindungsfehler, $netz->status);
        self::assertSame('KI-Anbieter nicht erreichbar.', $netz->getMessage());
        self::assertStringContainsString('Could not resolve host', $netz->anbieterText);
    }

    public function testA2xxBodyThatIsNoChatCompletionIsAnUnexpectedAnswer(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/502-proxy.html', 200),
            new ChatHttpAntwort(200, '{"data":[]}'),
            FakeChatHttp::fixture('extract-v1/verweigert.json'),
        ]);
        $client = new OpenAiCompatibleClient($http, self::prompts());

        foreach (['Basis-URL prüfen', 'Basis-URL prüfen', 'Das Modell hat die Antwort verweigert.'] as $grund) {
            $fehler = self::fehler(static fn() => $client->chat(self::profil(), self::KEY, new LlmAnfrage('', 'x')));
            self::assertSame(KiAufrufStatus::UnerwarteteAntwort, $fehler->status);
            self::assertStringContainsString($grund, $fehler->getMessage());
            self::assertStringNotContainsString("can't help", $fehler->getMessage(), "the model's words stay out");
        }
    }

    public function testOnlyJpegPagesAreAccepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LlmAnfrage('', 'x', ["\x89PNG\r\n\x1a\n"]);
    }

    private static function profil(bool $vision = false, bool $jsonSchema = false, int $maxImages = 0): KiAnbieter
    {
        return new KiAnbieter(
            id: 3,
            name: 'Testprofil',
            baseUrl: 'https://api.example.org/v1',
            model: 'gpt-6-luna',
            faehigkeiten: new KiFaehigkeiten($vision, $jsonSchema, $maxImages, 4000),
            timeoutS: 45,
            active: true,
            isDefault: true,
            keyGesetzt: true,
        );
    }

    private static function prompts(): KiPrompts
    {
        return new KiPrompts(dirname(__DIR__, 3) . '/app/prompts');
    }

    private static function jpeg(string $marke): string
    {
        return "\xFF\xD8\xFF\xE0" . $marke . "\xFF\xD9";
    }

    private static function fehler(\Closure $aufruf): LlmFehler
    {
        try {
            $aufruf();
        } catch (LlmFehler $e) {
            return $e;
        }
        self::fail('LlmFehler expected');
    }
}
