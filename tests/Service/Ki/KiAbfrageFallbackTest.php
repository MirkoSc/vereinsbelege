<?php

declare(strict_types=1);

namespace App\Tests\Service\Ki;

use App\Service\Ki\KiAbfrage;
use App\Service\Ki\KiAnbieter;
use App\Service\Ki\KiAufrufStatus;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Ki\KiPrompts;
use App\Service\Ki\KiVerbindungsfehler;
use App\Service\Ki\LlmAnfrage;
use App\Service\Ki\LlmFehler;
use App\Tests\Support\FakeChatHttp;
use App\Tests\Support\FakeLlmClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fallback profile of the AI client (M7-2b, issue #180, docs/spec/
 * 03-erfassung-und-ki.md section 6 "Client"): when the default profile is
 * out of reach (connection, timeout, HTTP 429/5xx) the client asks the
 * optional fallback profile ONCE - as a step of its own, never after a
 * failed repair call, never a second fallback - and the call log names the
 * profile that was actually used. Recorded answers only (FakeLlmClient).
 */
final class KiAbfrageFallbackTest extends TestCase
{
    private const string KEY = 'sk-test-0000-nicht-echt-AbCdWxYz';

    private const string FALLBACK_KEY = 'sk-test-9999-nicht-echt-FaLlBaCk';

    private const string TIMEOUT = 'timeout';

    private const string VERBINDUNG = 'verbindung';

    public function testATimeoutOfTheDefaultFallsBackOnceAndTheLogNamesTheFallback(): void
    {
        $client = new FakeLlmClient([self::fehler(self::TIMEOUT), FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1', self::fallback(), self::FALLBACK_KEY);

        self::assertTrue($ergebnis->gueltig());
        self::assertCount(2, $client->anfragen);
        self::assertSame(3, $client->anfragen[0]['profil']->id);
        self::assertSame(self::KEY, $client->anfragen[0]['apiKey']);
        self::assertSame(7, $client->anfragen[1]['profil']->id, 'the second call goes to the fallback profile ...');
        self::assertSame(self::FALLBACK_KEY, $client->anfragen[1]['apiKey'], '... with its own key');
        self::assertEquals($client->anfragen[0]['anfrage'], $client->anfragen[1]['anfrage'], 'the same request, from scratch');

        [$erster, $zweiter] = $ergebnis->aufrufe();
        self::assertSame(3, $erster->anbieterId);
        self::assertSame(KiAufrufStatus::Zeitueberschreitung, $erster->status);
        self::assertFalse($erster->fallback);
        self::assertSame(7, $zweiter->anbieterId, 'the log names the profile actually used');
        self::assertSame('Ausweichprofil', $zweiter->anbieterName);
        self::assertSame(KiAufrufStatus::Gueltig, $zweiter->status);
        self::assertTrue($zweiter->fallback);
        self::assertFalse($zweiter->reparatur);
        self::assertSame('KI-Antwort gültig. Das Fallback-Profil wurde verwendet.', $ergebnis->meldung());
    }

    /**
     * @return iterable<string, array{\Throwable|string, bool}>
     */
    public static function ausfaelle(): iterable
    {
        yield 'timeout' => [self::TIMEOUT, true];
        yield 'no connection' => [self::VERBINDUNG, true];
        yield 'HTTP 429 rate limit' => [429, true];
        yield 'HTTP 500' => [500, true];
        yield 'HTTP 502 proxy' => [502, true];
        yield 'HTTP 503' => [503, true];
        yield 'HTTP 599' => [599, true];
        yield 'HTTP 400 request is wrong' => [400, false];
        yield 'HTTP 401 key is wrong' => [401, false];
        yield 'HTTP 403' => [403, false];
        yield 'HTTP 404 model' => [404, false];
        yield 'HTTP 499' => [499, false];
    }

    #[DataProvider('ausfaelle')]
    public function testOnlyAnOutageOfTheDefaultFallsBack(int|string $fehler, bool $faelltZurueck): void
    {
        $client = new FakeLlmClient([self::fehler($fehler), FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1', self::fallback(), self::FALLBACK_KEY);

        self::assertSame($faelltZurueck, $ergebnis->gueltig());
        self::assertCount($faelltZurueck ? 2 : 1, $client->anfragen);
    }

    public function testAnAnswerThatIsNoChatCompletionDoesNotFallBack(): void
    {
        $client = new FakeLlmClient([LlmFehler::unerwarteteAntwort('Die Antwort enthält keinen Text.'), FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1', self::fallback(), self::FALLBACK_KEY);

        self::assertFalse($ergebnis->gueltig());
        self::assertCount(1, $client->anfragen, 'the provider answered; asking another one is not the fallback');
    }

    public function testAnAnswerThatDoesNotFitIsRepairedAtTheDefaultNotSentToTheFallback(): void
    {
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/kein-json.json'), FakeLlmClient::fixture('extract-v1/gueltig-repariert.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1', self::fallback(), self::FALLBACK_KEY);

        self::assertTrue($ergebnis->gueltig());
        self::assertSame([3, 3], array_map(static fn(array $a): int => $a['profil']->id, $client->anfragen));
        self::assertSame('KI-Antwort nach einem Korrekturversuch gültig.', $ergebnis->meldung());
    }

    public function testAFailedRepairCallOfTheDefaultStaysAFailureWithoutFallback(): void
    {
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/kein-json.json'), self::fehler(self::TIMEOUT), FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1', self::fallback(), self::FALLBACK_KEY);

        self::assertFalse($ergebnis->gueltig());
        self::assertCount(2, $client->anfragen, 'no third call, no fallback after a repair call');
        self::assertFalse($ergebnis->versuche[1]->fallbackMoeglich());
    }

    public function testWhenTheFallbackIsOutTooTheResultIsAFailureAndNothingIsAskedThird(): void
    {
        $client = new FakeLlmClient([self::fehler(self::TIMEOUT), self::fehler(503), FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1', self::fallback(), self::FALLBACK_KEY);

        self::assertFalse($ergebnis->gueltig(), 'KI fehlgeschlagen - nothing is guessed');
        self::assertCount(2, $client->anfragen, 'one fallback attempt, never a second');
        self::assertSame(KiAufrufStatus::HttpFehler, $ergebnis->letzter()->status);
        self::assertSame(7, $ergebnis->letzter()->aufruf->anbieterId, 'the log names the fallback that failed');
        self::assertFalse($ergebnis->letzter()->fallbackMoeglich(), 'a fallback attempt does not fall back');
        self::assertSame('Der KI-Anbieter hat die Anfrage abgelehnt. Das Fallback-Profil wurde verwendet.', $ergebnis->meldung());
    }

    public function testTheFallbacksAnswerThatDoesNotFitGetsItsOwnRepairCallAtTheFallback(): void
    {
        $client = new FakeLlmClient([
            self::fehler(429),
            FakeLlmClient::fixture('extract-v1/ungueltig-schema.json'),
            FakeLlmClient::fixture('extract-v1/gueltig-repariert.json'),
            FakeLlmClient::fixture('extract-v1/gueltig.json'),
        ]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1', self::fallback(), self::FALLBACK_KEY);

        self::assertTrue($ergebnis->gueltig());
        self::assertCount(3, $client->anfragen, 'at most three calls');
        self::assertSame([3, 7, 7], array_map(static fn(array $a): int => $a['profil']->id, $client->anfragen));
        self::assertSame(self::FALLBACK_KEY, $client->anfragen[2]['apiKey']);
        $aufrufe = $ergebnis->aufrufe();
        self::assertSame([false, true, true], array_map(static fn($a): bool => $a->fallback, $aufrufe));
        self::assertSame([false, false, true], array_map(static fn($a): bool => $a->reparatur, $aufrufe));
        self::assertSame('KI-Antwort nach einem Korrekturversuch gültig. Das Fallback-Profil wurde verwendet.', $ergebnis->meldung());
    }

    public function testWithoutAFallbackProfileNothingChanges(): void
    {
        $client = new FakeLlmClient([self::fehler(self::TIMEOUT), FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertFalse($ergebnis->gueltig());
        self::assertCount(1, $client->anfragen);
        self::assertSame('Der KI-Anbieter hat nicht rechtzeitig geantwortet.', $ergebnis->meldung());
    }

    public function testAFallbackThatIsTheSameOrNotUsableIsNotAsked(): void
    {
        $unbrauchbar = [
            'the profile itself' => [self::profil(), self::KEY],
            'inactive' => [self::fallback(active: false), self::FALLBACK_KEY],
            'without key' => [self::fallback(keyGesetzt: false), self::FALLBACK_KEY],
            'key not readable' => [self::fallback(), null],
        ];

        foreach ($unbrauchbar as $fall => [$profil, $key]) {
            $client = new FakeLlmClient([self::fehler(self::TIMEOUT), FakeLlmClient::fixture('extract-v1/gueltig.json')]);

            $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1', $profil, $key);

            self::assertFalse($ergebnis->gueltig(), $fall);
            self::assertCount(1, $client->anfragen, $fall);
        }
    }

    public function testTheFallbackIsAStepOfItsOwnForTheJobChain(): void
    {
        $client = new FakeLlmClient([self::fehler(self::VERBINDUNG), FakeLlmClient::fixture('extract-v1/gueltig.json')]);
        $abfrage = self::abfrage($client);
        $anfrage = self::anfrage();

        $erster = $abfrage->fragen(self::profil(), self::KEY, $anfrage, 'extract-v1');
        self::assertCount(1, $client->anfragen, 'fragen() never calls two providers in one go');
        self::assertTrue($erster->fallbackMoeglich());
        $zweiter = $abfrage->fallbackFragen(self::fallback(), self::FALLBACK_KEY, $anfrage, $erster, 'extract-v1');

        self::assertCount(2, $client->anfragen);
        self::assertTrue($zweiter->gueltig());
        self::assertTrue($zweiter->aufruf->fallback);
        self::assertFalse($zweiter->fallbackMoeglich());
    }

    public function testFallbackFragenRefusesWhatIsNotAnOutageOfAFirstCall(): void
    {
        $client = new FakeLlmClient([
            FakeLlmClient::fixture('extract-v1/gueltig.json'),
            self::fehler(401),
            FakeLlmClient::fixture('extract-v1/kein-json.json'),
            FakeLlmClient::fixture('extract-v1/kein-json.json'),
            self::fehler(self::TIMEOUT),
            self::fehler(self::TIMEOUT),
        ]);
        $abfrage = self::abfrage($client);
        $anfrage = self::anfrage();

        $gueltig = $abfrage->fragen(self::profil(), self::KEY, $anfrage, 'extract-v1');
        $abgelehnt = $abfrage->fragen(self::profil(), self::KEY, $anfrage, 'extract-v1');
        $repariert = $abfrage->reparieren(self::profil(), self::KEY, $anfrage, $abfrage->fragen(self::profil(), self::KEY, $anfrage, 'extract-v1'), 'extract-v1');
        $ausfall = $abfrage->fragen(self::profil(), self::KEY, $anfrage, 'extract-v1');
        $imFallback = $abfrage->fallbackFragen(self::fallback(), self::FALLBACK_KEY, $anfrage, $ausfall, 'extract-v1');

        foreach (['valid' => $gueltig, 'HTTP 401' => $abgelehnt, 'repair call' => $repariert, 'fallback itself' => $imFallback] as $fall => $versuch) {
            try {
                $abfrage->fallbackFragen(self::fallback(id: 8, name: 'Noch eins'), self::FALLBACK_KEY, $anfrage, $versuch, 'extract-v1');
                self::fail('LogicException expected: ' . $fall);
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }

        foreach (['the same profile' => self::profil(), 'not usable' => self::fallback(active: false)] as $fall => $profil) {
            try {
                $abfrage->fallbackFragen($profil, self::FALLBACK_KEY, $anfrage, $ausfall, 'extract-v1');
                self::fail('LogicException expected: ' . $fall);
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertCount(6, $client->anfragen, 'a refused fallback makes no call');
    }

    /**
     * @return iterable<string, array{KiAufrufStatus, ?int, bool}>
     */
    public static function statusFaelle(): iterable
    {
        yield 'timeout' => [KiAufrufStatus::Zeitueberschreitung, null, true];
        yield 'connection' => [KiAufrufStatus::Verbindungsfehler, null, true];
        yield '429' => [KiAufrufStatus::HttpFehler, 429, true];
        yield '500' => [KiAufrufStatus::HttpFehler, 500, true];
        yield '599' => [KiAufrufStatus::HttpFehler, 599, true];
        yield '400' => [KiAufrufStatus::HttpFehler, 400, false];
        yield '408 is not in the list' => [KiAufrufStatus::HttpFehler, 408, false];
        yield '600' => [KiAufrufStatus::HttpFehler, 600, false];
        yield 'HTTP error without status' => [KiAufrufStatus::HttpFehler, null, false];
        yield 'no chat completion' => [KiAufrufStatus::UnerwarteteAntwort, null, false];
        yield 'no JSON' => [KiAufrufStatus::KeinJson, null, false];
        yield 'schema violated' => [KiAufrufStatus::SchemaVerletzt, null, false];
        yield 'valid' => [KiAufrufStatus::Gueltig, null, false];
    }

    #[DataProvider('statusFaelle')]
    public function testWhichStatusIsAnOutage(KiAufrufStatus $status, ?int $httpStatus, bool $erwartet): void
    {
        self::assertSame($erwartet, $status->ausfall($httpStatus));
    }

    public function testNoKeyAndNoBusinessDataInTheMessage(): void
    {
        $client = new FakeLlmClient([self::fehler(self::TIMEOUT), FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1', self::fallback(), self::FALLBACK_KEY);

        foreach ([self::KEY, self::FALLBACK_KEY, 'Sporthaus', 'DE89370400440532013000'] as $geheim) {
            self::assertStringNotContainsString($geheim, $ergebnis->meldung());
        }
    }

    private static function fehler(int|string $art): LlmFehler
    {
        return match (true) {
            $art === self::TIMEOUT => LlmFehler::ausVerbindungsfehler(KiVerbindungsfehler::zeitueberschreitung(45), self::KEY),
            $art === self::VERBINDUNG => LlmFehler::ausVerbindungsfehler(KiVerbindungsfehler::verbindung('Could not resolve host'), self::KEY),
            default => LlmFehler::http((int) $art, FakeChatHttp::fixture('verbindungstest/429-limit.json')->body, self::KEY),
        };
    }

    private static function abfrage(FakeLlmClient $client): KiAbfrage
    {
        return new KiAbfrage($client, self::prompts());
    }

    private static function anfrage(): LlmAnfrage
    {
        return new LlmAnfrage(
            'Du liest Belege eines Sportvereins aus.',
            'Seite 1: Rechnung RE-2026-0815 …',
            ["\xFF\xD8\xFF\xE0seite1\xFF\xD9"],
            self::prompts()->schema('extract-v1'),
        );
    }

    private static function profil(): KiAnbieter
    {
        return new KiAnbieter(
            id: 3,
            name: 'Testprofil',
            baseUrl: 'https://api.example.org/v1',
            model: 'gpt-6-luna',
            faehigkeiten: new KiFaehigkeiten(true, true, 4, 4000),
            timeoutS: 45,
            active: true,
            isDefault: true,
            keyGesetzt: true,
        );
    }

    private static function fallback(int $id = 7, string $name = 'Ausweichprofil', bool $active = true, bool $keyGesetzt = true): KiAnbieter
    {
        return new KiAnbieter(
            id: $id,
            name: $name,
            baseUrl: 'https://ki.verein.example/v1',
            model: 'llava-1.6',
            faehigkeiten: new KiFaehigkeiten(true, false, 2, 3000),
            timeoutS: 120,
            active: $active,
            isDefault: false,
            keyGesetzt: $keyGesetzt,
            isFallback: true,
        );
    }

    private static function prompts(): KiPrompts
    {
        return new KiPrompts(dirname(__DIR__, 3) . '/app/prompts');
    }
}
