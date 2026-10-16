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
 * "Verbindung testen" (M7-1, issue #41, M7-1b, issue #42,
 * docs/spec/03-erfassung-und-ki.md section 6) against recorded answers
 * (tests/fixtures/llm/verbindungstest/): the probes that find out pictures
 * and JSON schema, HTTP errors, timeout, no chat completion - and never the
 * key in what comes back.
 */
final class VerbindungstestTest extends TestCase
{
    private const string KEY = 'sk-test-0000-nicht-echt-AbCdWxYz';

    public function testAServerThatEnforcesTheSchemaAndSeesPicturesIsRecognisedWithTwoCalls(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/schema-erzwungen.json'),
            FakeChatHttp::fixture('verbindungstest/bild-rot.json'),
        ]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(vision: false, jsonSchema: false, timeoutS: 60), self::KEY);

        self::assertTrue($ergebnis->ok, $ergebnis->text());
        self::assertSame('Verbindung erfolgreich.', $ergebnis->meldung);
        self::assertContains('Modell: gpt-6-luna-2026-08-01', $ergebnis->details);
        self::assertContains('Tokens: 70', $ergebnis->details, 'of the first call');
        self::assertContains('Bilder: ja', $ergebnis->details);
        self::assertContains('JSON-Schema: ja', $ergebnis->details);
        self::assertNotNull($ergebnis->dauerMs);
        self::assertEquals(new KiFaehigkeiten(true, true, 4, 4000), $ergebnis->erkannt, 'whatever the profile claimed');

        self::assertCount(2, $http->anfragen, 'two calls - no request may run long');
        foreach ($http->anfragen as $anfrage) {
            self::assertSame('https://api.example.org/v1/chat/completions', $anfrage['url']);
            self::assertSame(self::KEY, $anfrage['apiKey'], 'the key goes to the transport as Bearer');
            self::assertSame(Verbindungstest::MAX_TIMEOUT_S, $anfrage['timeoutS'], 'capped below the request limit');
            self::assertSame('gpt-6-luna', $anfrage['body']['model']);
            self::assertArrayNotHasKey('max_tokens', $anfrage['body']);
            self::assertArrayNotHasKey('temperature', $anfrage['body']);
        }

        $text = $http->anfragen[0]['body'];
        self::assertIsString($text['messages'][0]['content'], 'probe 1 has no picture');
        self::assertStringContainsString('in einem kurzen deutschen Satz', $text['messages'][0]['content'], 'a sentence, so bare JSON proves enforcement');
        self::assertSame('json_schema', $text['response_format']['type']);
        self::assertTrue($text['response_format']['json_schema']['strict']);
        self::assertSame(['ok', 'farbe'], $text['response_format']['json_schema']['schema']['required']);

        $bild = $http->anfragen[1]['body'];
        self::assertArrayNotHasKey('response_format', $bild, 'probe 2 asks about the picture only');
        $inhalt = $bild['messages'][0]['content'];
        self::assertIsArray($inhalt);
        self::assertSame('text', $inhalt[0]['type']);
        self::assertSame('image_url', $inhalt[1]['type']);
        $url = $inhalt[1]['image_url']['url'];
        self::assertStringStartsWith('data:image/jpeg;base64,', $url);
        $jpeg = base64_decode(substr($url, strlen('data:image/jpeg;base64,')), true);
        self::assertIsString($jpeg);
        self::assertStringStartsWith("\xFF\xD8\xFF", $jpeg, 'a real JPEG');
    }

    /**
     * Anthropic's compatible endpoint takes `response_format` without an
     * error and ignores it: the answer is a sentence.
     */
    public function testAServerThatIgnoresTheSchemaAnswersWithASentenceAndHasNoJsonSchema(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/schema-ignoriert-satz.json'),
            FakeChatHttp::fixture('verbindungstest/bild-rot.json'),
        ]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(vision: true, jsonSchema: true), self::KEY);

        self::assertTrue($ergebnis->ok, $ergebnis->text());
        self::assertContains('Modell: claude-sonnet-5-5', $ergebnis->details);
        self::assertContains('JSON-Schema: nein', $ergebnis->details);
        self::assertEquals(new KiFaehigkeiten(true, false, 4, 4000), $ergebnis->erkannt, 'the claim is replaced');
        self::assertCount(2, $http->anfragen, 'the schema was accepted, no retry');
    }

    public function testAServerThatRejectsSchemaAndPictureGetsTheQuestionOnceWithoutTheSchema(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/400-bild.json', 400),
            FakeChatHttp::fixture('verbindungstest/schema-ignoriert-satz.json'),
            FakeChatHttp::fixture('verbindungstest/400-bild.json', 422),
        ]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(vision: true, jsonSchema: true), self::KEY);

        self::assertTrue($ergebnis->ok, $ergebnis->text());
        self::assertEquals(new KiFaehigkeiten(false, false, 4, 4000), $ergebnis->erkannt);
        self::assertContains('Bilder: nein', $ergebnis->details);
        self::assertCount(3, $http->anfragen, 'never more than three calls');
        self::assertArrayHasKey('response_format', $http->anfragen[0]['body']);
        self::assertArrayNotHasKey('response_format', $http->anfragen[1]['body'], 'the retry without the parameter');
        self::assertIsString($http->anfragen[1]['body']['messages'][0]['content']);
    }

    public function testAPictureTheModelDidNotSeeIsNoVision(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/schema-erzwungen.json'),
            FakeChatHttp::fixture('verbindungstest/bild-kein-bild.json'),
        ]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(vision: true, jsonSchema: true), self::KEY);

        self::assertTrue($ergebnis->ok, 'the connection works, the model just cannot read pictures');
        self::assertEquals(new KiFaehigkeiten(false, true, 4, 4000), $ergebnis->erkannt);
    }

    public function testAProfileWithPicturesSendsAtLeastOneAndOtherwiseKeepsItsNumbers(): void
    {
        $mitBild = [
            FakeChatHttp::fixture('verbindungstest/schema-erzwungen.json'),
            FakeChatHttp::fixture('verbindungstest/bild-rot.json'),
        ];
        $ohneBild = [
            FakeChatHttp::fixture('verbindungstest/schema-erzwungen.json'),
            FakeChatHttp::fixture('verbindungstest/bild-kein-bild.json'),
        ];

        $erkannt = new Verbindungstest(new FakeChatHttp($mitBild))->pruefe(self::profil(vision: false, maxImages: 0, maxTokens: 1500), self::KEY)->erkannt;
        self::assertEquals(new KiFaehigkeiten(true, true, 1, 1500), $erkannt);

        $erkannt = new Verbindungstest(new FakeChatHttp($ohneBild))->pruefe(self::profil(vision: true, maxImages: 6, maxTokens: 1500), self::KEY)->erkannt;
        self::assertEquals(new KiFaehigkeiten(false, true, 6, 1500), $erkannt);
    }

    public function testTheProfilesShorterTimeoutAppliesToEveryCall(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/schema-erzwungen.json'),
            FakeChatHttp::fixture('verbindungstest/bild-rot.json'),
        ]);

        new Verbindungstest($http)->pruefe(self::profil(timeoutS: 7), self::KEY);

        self::assertSame([7, 7], array_column($http->anfragen, 'timeoutS'));
    }

    public function testAllCallsTogetherStayBelowTheRequestLimit(): void
    {
        self::assertLessThan(30, Verbindungstest::GESAMT_S, 'max_execution_time=30 (CLAUDE.md section 8)');
        self::assertGreaterThanOrEqual(Verbindungstest::MAX_TIMEOUT_S, Verbindungstest::GESAMT_S);
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
        yield 'unknown model' => ['404-modell.json', 404, 'Adresse oder Modell nicht gefunden (HTTP 404)', 'gpt-gibt-es-nicht'];
        yield 'quota' => ['429-limit.json', 429, 'Anfragelimit oder Guthaben erschöpft (HTTP 429).', 'exceeded your current quota'];
        yield 'proxy page' => ['502-proxy.html', 502, 'Der Anbieter meldet HTTP 502.', '502 Bad Gateway 502 Bad Gateway nginx'];
        yield 'redirect' => ['schema-erzwungen.json', 301, 'Weiterleitung (HTTP 301)', ''];
    }

    #[DataProvider('httpFehler')]
    public function testHttpErrorsAreNamedWithTheProvidersOwnText(string $fixture, int $status, string $meldung, string $anbietertext): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('verbindungstest/' . $fixture, $status)]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(), self::KEY);

        self::assertFalse($ergebnis->ok);
        self::assertNull($ergebnis->erkannt, 'no result - the profile stays as it is');
        self::assertCount(1, $http->anfragen, 'a failure on the first call ends the test');
        self::assertStringContainsString($meldung, $ergebnis->meldung);
        if ($anbietertext !== '') {
            self::assertStringContainsString($anbietertext, $ergebnis->text());
        }
        self::assertStringNotContainsString('<', $ergebnis->text(), 'no markup');
    }

    public function testARequestTheServerRejectsEvenWithoutTheSchemaIsAnError(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/400-bild.json', 400),
            FakeChatHttp::fixture('verbindungstest/400-bild.json', 400),
        ]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(), self::KEY);

        self::assertFalse($ergebnis->ok);
        self::assertNull($ergebnis->erkannt);
        self::assertSame('Anfrage abgelehnt (HTTP 400) – Modell und Fähigkeiten (Bilder, JSON-Schema) prüfen.', $ergebnis->meldung);
        self::assertStringContainsString('image_url is only supported', $ergebnis->text());
        self::assertCount(2, $http->anfragen);
    }

    public function testAFailureOfTheSecondProbeKeepsTheProfileAndNamesTheProvidersText(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/schema-erzwungen.json'),
            FakeChatHttp::fixture('verbindungstest/429-limit.json', 429),
        ]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(), self::KEY);

        self::assertFalse($ergebnis->ok);
        self::assertNull($ergebnis->erkannt, 'quota is no statement about pictures');
        self::assertSame('Anfragelimit oder Guthaben erschöpft (HTTP 429).', $ergebnis->meldung);
        self::assertContains('Modell: gpt-6-luna-2026-08-01', $ergebnis->details);
        self::assertStringContainsString('exceeded your current quota', $ergebnis->text());
    }

    public function testATimeoutOfTheSecondProbeKeepsTheProfile(): void
    {
        $http = new FakeChatHttp([
            FakeChatHttp::fixture('verbindungstest/schema-erzwungen.json'),
            KiVerbindungsfehler::zeitueberschreitung(Verbindungstest::MAX_TIMEOUT_S),
        ]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(), self::KEY);

        self::assertFalse($ergebnis->ok);
        self::assertNull($ergebnis->erkannt);
        self::assertSame('Keine Antwort innerhalb von 20 Sekunden (Zeitüberschreitung).', $ergebnis->meldung);
    }

    public function testATimeoutIsAFailureWithItsOwnMessage(): void
    {
        $http = new FakeChatHttp([KiVerbindungsfehler::zeitueberschreitung(Verbindungstest::MAX_TIMEOUT_S)]);

        $ergebnis = new Verbindungstest($http)->pruefe(self::profil(), self::KEY);

        self::assertFalse($ergebnis->ok);
        self::assertSame('Keine Antwort innerhalb von 20 Sekunden (Zeitüberschreitung).', $ergebnis->meldung);
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
        int $maxImages = 4,
        int $maxTokens = 4000,
    ): KiAnbieter {
        return new KiAnbieter(
            id: 1,
            name: 'Test',
            baseUrl: $baseUrl,
            model: 'gpt-6-luna',
            faehigkeiten: new KiFaehigkeiten($vision, $jsonSchema, $maxImages, $maxTokens),
            timeoutS: $timeoutS,
            active: true,
            isDefault: true,
            keyGesetzt: true,
        );
    }
}
