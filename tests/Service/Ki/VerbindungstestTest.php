<?php

declare(strict_types=1);

namespace App\Tests\Service\Ki;

use App\Service\Ki\ChatHttpAntwort;
use App\Service\Ki\CurlChatHttp;
use App\Service\Ki\KiAnbieter;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Ki\KiVerbindungsfehler;
use App\Service\Ki\Verbindungstest;
use App\Tests\Support\FakeChatHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Verbindung testen" (M7-1, issue #41, docs/spec/03-erfassung-und-ki.md
 * section 6) against recorded answers (tests/fixtures/llm/verbindungstest/):
 * valid, invalid, HTTP errors, timeout, no JSON - and never the key in what
 * comes back.
 */
final class VerbindungstestTest extends TestCase
{
    private const string KEY = 'sk-test-0000-nicht-echt-AbCdWxYz';

    public function testOneCallChecksPictureAndSchemaWhenTheProfileClaimsBoth(): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('verbindungstest/ok-bild-schema.json')]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(vision: true, jsonSchema: true, timeoutS: 60), self::KEY);

        self::assertTrue($ergebnis->ok, $ergebnis->text());
        self::assertSame('Verbindung erfolgreich.', $ergebnis->meldung);
        self::assertContains('Modell: gpt-6-luna-2026-08-01', $ergebnis->details);
        self::assertContains('Tokens: 127', $ergebnis->details);
        self::assertContains('Testbild erkannt', $ergebnis->details);
        self::assertContains('JSON-Schema eingehalten', $ergebnis->details);
        self::assertNotNull($ergebnis->dauerMs);

        self::assertCount(1, $http->anfragen, 'one call only - no request may run long');
        $anfrage = $http->anfragen[0];
        self::assertSame('https://api.example.org/v1/chat/completions', $anfrage['url']);
        self::assertSame(self::KEY, $anfrage['apiKey'], 'the key goes to the transport as Bearer');
        self::assertSame(Verbindungstest::MAX_TIMEOUT_S, $anfrage['timeoutS'], 'capped below the request limit');

        $body = $anfrage['body'];
        self::assertSame('gpt-6-luna', $body['model']);
        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertArrayNotHasKey('temperature', $body);
        $inhalt = $body['messages'][0]['content'];
        self::assertIsArray($inhalt);
        self::assertSame('text', $inhalt[0]['type']);
        self::assertSame('image_url', $inhalt[1]['type']);
        $url = $inhalt[1]['image_url']['url'];
        self::assertStringStartsWith('data:image/jpeg;base64,', $url);
        $jpeg = base64_decode(substr($url, strlen('data:image/jpeg;base64,')), true);
        self::assertIsString($jpeg);
        self::assertStringStartsWith("\xFF\xD8\xFF", $jpeg, 'a real JPEG');

        self::assertSame('json_schema', $body['response_format']['type']);
        self::assertTrue($body['response_format']['json_schema']['strict']);
        self::assertSame(['ok', 'farbe'], $body['response_format']['json_schema']['schema']['required']);
    }

    public function testWithoutCapabilitiesTheRequestIsPlainTextAndProseAroundTheJsonIsAccepted(): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('verbindungstest/ok-text-prosa.json')]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(vision: false, jsonSchema: false, timeoutS: 7), self::KEY);

        self::assertTrue($ergebnis->ok, $ergebnis->text());
        self::assertNotContains('Testbild erkannt', $ergebnis->details);
        self::assertNotContains('JSON-Schema eingehalten', $ergebnis->details);

        $anfrage = $http->anfragen[0];
        self::assertSame(7, $anfrage['timeoutS'], "the profile's own, shorter timeout");
        self::assertIsString($anfrage['body']['messages'][0]['content'], 'no picture');
        self::assertArrayNotHasKey('response_format', $anfrage['body']);
    }

    public function testAWrongKeyIsReportedWithoutEverShowingTheKey(): void
    {
        $body = (string) FakeChatHttp::fixture('verbindungstest/401-key-falsch.json')->body;
        // Some servers echo the full key - the result must not.
        $body = str_replace('sk-test-AbCd****************************WxYz', self::KEY, $body);
        $http = new FakeChatHttp([new ChatHttpAntwort(401, $body), FakeChatHttp::fixture('verbindungstest/401-key-falsch.json', 401)]);
        $test = new Verbindungstest($http);

        foreach ([$test->pruefe(self::profil(), self::KEY), $test->pruefe(self::profil(), self::KEY)] as $ergebnis) {
            self::assertFalse($ergebnis->ok);
            self::assertStringContainsString('Zugriff verweigert (HTTP 401) – API-Key prüfen.', $ergebnis->text());
            self::assertStringContainsString('Incorrect API key provided: ***', $ergebnis->text());
            self::assertStringNotContainsString(self::KEY, $ergebnis->text());
            self::assertStringNotContainsString('sk-test', $ergebnis->text(), 'not even the masked echo');
            self::assertStringNotContainsString('WxYz', $ergebnis->text());
        }
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function httpFehler(): iterable
    {
        yield 'picture not supported' => ['400-bild.json', 400, 'Anfrage abgelehnt (HTTP 400) – Modell und Fähigkeiten (Bilder, JSON-Schema) prüfen.', 'image_url is only supported'];
        yield 'unknown model' => ['404-modell.json', 404, 'Adresse oder Modell nicht gefunden (HTTP 404)', 'gpt-gibt-es-nicht'];
        yield 'quota' => ['429-limit.json', 429, 'Anfragelimit oder Guthaben erschöpft (HTTP 429).', 'exceeded your current quota'];
        yield 'proxy page' => ['502-proxy.html', 502, 'Der Anbieter meldet HTTP 502.', '502 Bad Gateway 502 Bad Gateway nginx'];
        yield 'redirect' => ['ok-bild-schema.json', 301, 'Weiterleitung (HTTP 301)', ''];
    }

    #[DataProvider('httpFehler')]
    public function testHttpErrorsAreNamedWithTheProvidersOwnText(string $fixture, int $status, string $meldung, string $anbietertext): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('verbindungstest/' . $fixture, $status)]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(), self::KEY);

        self::assertFalse($ergebnis->ok);
        self::assertStringContainsString($meldung, $ergebnis->meldung);
        if ($anbietertext !== '') {
            self::assertStringContainsString($anbietertext, $ergebnis->text());
        }
        self::assertStringNotContainsString('<', $ergebnis->text(), 'no markup');
    }

    public function testATimeoutIsAFailureWithItsOwnMessage(): void
    {
        $http = new FakeChatHttp([KiVerbindungsfehler::zeitueberschreitung(Verbindungstest::MAX_TIMEOUT_S)]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(), self::KEY);

        self::assertFalse($ergebnis->ok);
        self::assertSame('Keine Antwort innerhalb von 20 Sekunden (Zeitüberschreitung).', $ergebnis->meldung);
    }

    public function testAnAnswerWithoutJsonFailsAndPointsAtTheSchemaCapability(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/kein-json.json'),
            FakeChatHttp::fixture('verbindungstest/kein-json.json'),
        ]);
        $test = new Verbindungstest($http);

        $mitSchema = $test->pruefe(self::profil(vision: false, jsonSchema: true), self::KEY);
        self::assertFalse($mitSchema->ok);
        self::assertStringContainsString('kein gültiges JSON – Fähigkeit „JSON-Schema“ prüfen', $mitSchema->meldung);

        $ohneSchema = $test->pruefe(self::profil(vision: false, jsonSchema: false), self::KEY);
        self::assertFalse($ohneSchema->ok);
        self::assertStringContainsString('enthielt kein gültiges JSON', $ohneSchema->meldung);
    }

    public function testWithASchemaProseAroundTheJsonIsNotAccepted(): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('verbindungstest/ok-text-prosa.json')]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(vision: false, jsonSchema: true), self::KEY);

        self::assertFalse($ergebnis->ok, 'json_schema promises bare JSON');
    }

    public function testSomethingThatIsNoChatCompletionFails(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/502-proxy.html', 200),
            new ChatHttpAntwort(200, '{"choices":[]}'),
        ]);
        $test = new Verbindungstest($http);

        foreach ([$test->pruefe(self::profil(), self::KEY), $test->pruefe(self::profil(), self::KEY)] as $ergebnis) {
            self::assertFalse($ergebnis->ok);
            self::assertSame('Die Antwort ist keine Chat-Completions-Antwort – Basis-URL prüfen.', $ergebnis->meldung);
        }
    }

    public function testAPictureTheModelDidNotSeeFailsTheVisionCapability(): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('verbindungstest/farbe-falsch.json')]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(vision: true), self::KEY);

        self::assertFalse($ergebnis->ok);
        self::assertSame(
            'Verbindung steht, aber das Testbild wurde nicht erkannt (Antwort: „Ich sehe kein Bild“) – Fähigkeit „Bilder“ prüfen.',
            $ergebnis->meldung,
        );
    }

    public function testOkFalseIsNotASuccess(): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('verbindungstest/ok-false.json')]);

        self::assertFalse(new Verbindungstest($http)->pruefe(self::profil(), self::KEY)->ok);
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        $http = new FakeChatHttp([]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(), '');

        self::assertFalse($ergebnis->ok);
        self::assertSame('Für dieses Profil ist kein API-Key hinterlegt.', $ergebnis->meldung);
        self::assertSame([], $http->anfragen);
    }

    public function testLongProviderTextIsCutToOneShortLine(): void
    {
        $lang = json_encode(['error' => ['message' => "Zeile eins\n\n" . str_repeat('sehr lang ', 100)]], JSON_THROW_ON_ERROR);
        $http = new FakeChatHttp([new ChatHttpAntwort(500, $lang)]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(), self::KEY);

        self::assertStringNotContainsString("\n", $ergebnis->text());
        self::assertStringContainsString('Zeile eins sehr lang', $ergebnis->text());
        self::assertStringContainsString('…', $ergebnis->text());
        self::assertLessThan(320, mb_strlen($ergebnis->text()));
    }

    public function testTheCurlTransportRefusesPlainHttpBeforeSendingAnything(): void
    {
        $this->expectException(KiVerbindungsfehler::class);
        $this->expectExceptionMessage('nur https:// ist erlaubt');

        new CurlChatHttp()->post('http://api.example.org/v1/chat/completions', self::KEY, '{}', 5);
    }

    public function testTheCurlTransportReportsAnUnreachableHostAsConnectionError(): void
    {
        // Port 1 on the loopback: refused at once, no network involved.
        $ergebnis = new Verbindungstest(new CurlChatHttp())->pruefe(self::profil(baseUrl: 'https://127.0.0.1:1/v1'), self::KEY);

        self::assertFalse($ergebnis->ok);
        self::assertStringStartsWith('Verbindung fehlgeschlagen: ', $ergebnis->meldung);
        self::assertStringNotContainsString(self::KEY, $ergebnis->text());
    }

    private static function profil(
        bool $vision = true,
        bool $jsonSchema = true,
        int $timeoutS = 60,
        string $baseUrl = 'https://api.example.org/v1',
    ): KiAnbieter {
        return new KiAnbieter(
            id: 1,
            name: 'Test',
            baseUrl: $baseUrl,
            model: 'gpt-6-luna',
            faehigkeiten: new KiFaehigkeiten($vision, $jsonSchema, 4, 4000),
            timeoutS: $timeoutS,
            active: true,
            isDefault: true,
            keyGesetzt: true,
        );
    }
}
