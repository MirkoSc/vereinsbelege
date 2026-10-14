<?php

declare(strict_types=1);

namespace App\Tests\Service\Ki;

use App\Service\Ki\ChatHttpAntwort;
use App\Service\Ki\KiAbfrage;
use App\Service\Ki\KiAnbieter;
use App\Service\Ki\KiAufrufStatus;
use App\Service\Ki\KiErgebnis;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Ki\KiPrompts;
use App\Service\Ki\KiVerbindungsfehler;
use App\Service\Ki\LlmAnfrage;
use App\Service\Ki\LlmAntwort;
use App\Service\Ki\LlmFehler;
use App\Service\Ki\OpenAiCompatibleClient;
use App\Tests\Support\FakeChatHttp;
use App\Tests\Support\FakeLlmClient;
use PHPUnit\Framework\TestCase;

/**
 * The AI client with recorded answers (M7-2, issue #44, the mandatory
 * tests of docs/spec/03-erfassung-und-ki.md: valid, invalid + repair,
 * timeout, HTTP error, no JSON): JSON is accepted only when it fits the
 * schema, there is exactly one repair call, and nothing is guessed.
 */
final class KiAbfrageTest extends TestCase
{
    private const string KEY = 'sk-test-0000-nicht-echt-AbCdWxYz';

    /** Business data of the recorded receipt - never in a message or an error. */
    private const array INHALTE = ['Sporthaus', 'DE89370400440532013000', 'RE-2026-0815', 'Trainingsbälle', '1.234,56', '238.00'];

    public function testAValidAnswerNeedsOneCall(): void
    {
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertTrue($ergebnis->gueltig());
        self::assertCount(1, $client->anfragen);
        self::assertSame('KI-Antwort gültig.', $ergebnis->meldung());

        $daten = $ergebnis->daten();
        self::assertIsArray($daten);
        self::assertSame('Sporthaus Beispiel GmbH', $daten['supplier']['name']);
        self::assertSame('238.00', $daten['total_gross']);
        self::assertSame(7, $daten['category_id']);
        self::assertNull($daten['sphere']);
        self::assertSame([['rate' => '19', 'amount' => '38.00']], $daten['taxes']);
        self::assertSame([], $daten['warnings'], 'an empty list stays a list');

        [$aufruf] = $ergebnis->aufrufe();
        self::assertSame(3, $aufruf->anbieterId);
        self::assertSame('Testprofil', $aufruf->anbieterName);
        self::assertSame('gpt-6-luna-2026-08-01', $aufruf->modell);
        self::assertSame('extract-v1', $aufruf->promptVersion);
        self::assertFalse($aufruf->reparatur);
        self::assertSame(KiAufrufStatus::Gueltig, $aufruf->status);
        self::assertSame(1850, $aufruf->promptTokens);
        self::assertSame(240, $aufruf->antwortTokens);
        self::assertSame(2090, $aufruf->tokensGesamt);
        self::assertSame(1200, $aufruf->dauerMs);
        self::assertSame(FakeChatHttp::fixture('extract-v1/gueltig.json')->body, $aufruf->rohantwort, 'kept for the encrypted store of M7-5');
    }

    public function testAnInvalidAnswerGetsExactlyOneRepairCallWithTheErrors(): void
    {
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/ungueltig-schema.json'), FakeLlmClient::fixture('extract-v1/gueltig-repariert.json')]);
        $anfrage = self::anfrage();

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, $anfrage, 'extract-v1');

        self::assertTrue($ergebnis->gueltig());
        self::assertSame('KI-Antwort nach einem Korrekturversuch gültig.', $ergebnis->meldung());
        $daten = $ergebnis->daten();
        self::assertIsArray($daten);
        self::assertArrayHasKey('invoice_date', $daten);
        self::assertNull($daten['invoice_date'], 'unreadable is null, not a guess');
        self::assertSame(['Belegdatum nicht lesbar'], $ergebnis->daten()['warnings'] ?? null);

        [$erster, $zweiter] = $ergebnis->versuche;
        self::assertSame(KiAufrufStatus::SchemaVerletzt, $erster->status);
        self::assertNull($erster->daten, 'nothing of an invalid answer is used');
        self::assertContains('/invoice_date: Pflichtfeld fehlt', $erster->fehler);
        self::assertTrue($zweiter->reparatur);
        self::assertSame([false, true], array_map(static fn($a) => $a->reparatur, $ergebnis->aufrufe()));
        self::assertSame([KiAufrufStatus::SchemaVerletzt, KiAufrufStatus::Gueltig], array_map(static fn($a) => $a->status, $ergebnis->aufrufe()));
        self::assertSame(2371, $ergebnis->aufrufe()[1]->tokensGesamt);

        self::assertCount(2, $client->anfragen);
        $reparatur = $client->anfragen[1]['anfrage'];
        self::assertSame($anfrage->system, $reparatur->system, 'the same conversation');
        self::assertSame($anfrage->text, $reparatur->text);
        self::assertSame($anfrage->bilder, $reparatur->bilder, 'the model may look at the pages again');
        self::assertSame($anfrage->schema, $reparatur->schema, 'response_format again');
        self::assertCount(2, $reparatur->nachtrag);
        self::assertSame(['role' => 'assistant', 'content' => $erster->inhalt], $reparatur->nachtrag[0], 'its own answer');
        self::assertSame('user', $reparatur->nachtrag[1]['role']);
        $nachfrage = $reparatur->nachtrag[1]['content'];
        self::assertStringStartsWith('Deine Antwort entspricht nicht dem geforderten JSON-Schema.', $nachfrage);
        foreach ($erster->fehler as $fehler) {
            self::assertStringContainsString('- ' . $fehler, $nachfrage);
        }
        self::assertStringContainsString('Rate keine Werte.', $nachfrage);
    }

    public function testStillInvalidAfterTheRepairIsAFailureAndNeverAThirdCall(): void
    {
        $client = new FakeLlmClient([
            FakeLlmClient::fixture('extract-v1/ungueltig-schema.json'),
            FakeLlmClient::fixture('extract-v1/ungueltig-schema.json'),
            FakeLlmClient::fixture('extract-v1/gueltig.json'),
        ]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertFalse($ergebnis->gueltig());
        self::assertNull($ergebnis->daten());
        self::assertCount(2, $client->anfragen);
        self::assertSame('Die KI-Antwort entsprach nicht dem Schema. Auch der Korrekturversuch ist gescheitert.', $ergebnis->meldung());
        self::assertFalse($ergebnis->letzter()->reparaturMoeglich());
        self::assertKeinInhalt($ergebnis);
    }

    public function testNoJsonAtAllIsRepairedLikeASchemaViolation(): void
    {
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/kein-json.json'), FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertTrue($ergebnis->gueltig());
        self::assertSame(KiAufrufStatus::KeinJson, $ergebnis->versuche[0]->status);
        self::assertSame(['/: Die Antwort enthält kein JSON-Objekt.'], $ergebnis->versuche[0]->fehler);
        self::assertStringContainsString('- /: Die Antwort enthält kein JSON-Objekt.', $client->anfragen[1]['anfrage']->nachtrag[1]['content']);
    }

    public function testNoJsonTwiceIsAFailure(): void
    {
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/kein-json.json'), FakeLlmClient::fixture('extract-v1/kein-json.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertFalse($ergebnis->gueltig());
        self::assertSame('Die KI-Antwort enthielt kein JSON-Objekt. Auch der Korrekturversuch ist gescheitert.', $ergebnis->meldung());
        self::assertKeinInhalt($ergebnis);
    }

    public function testJsonInProseAndACodeFenceIsFoundWithoutARepair(): void
    {
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/prosa-mit-json.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(jsonSchema: false), self::KEY, self::anfrage(), 'extract-v1');

        self::assertTrue($ergebnis->gueltig(), implode("\n", $ergebnis->letzter()->fehler));
        self::assertCount(1, $client->anfragen, 'the "{laut Vorgabe}" in the prose is skipped, the fenced object found');
        self::assertSame('RE-2026-0815', $ergebnis->daten()['invoice_number'] ?? null);
        self::assertNull($ergebnis->aufrufe()[0]->tokensGesamt, 'no usage reported');
    }

    public function testBracesInStringsCountNotAndNoObjectIsTakenFromInsideABrokenOne(): void
    {
        $gueltig = json_decode(FakeLlmClient::fixture('extract-v1/gueltig.json')->inhalt, true, flags: JSON_THROW_ON_ERROR);
        $gueltig['purpose_short'] = 'Bälle } und { Netze "Profi"';
        $gueltig['category_id'] = 7.0;
        $json = json_encode($gueltig, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $kaputt = '{"document_type":"rechnung",, "supplier":{"name":"x"}}';
        $client = new FakeLlmClient([
            self::antwort("Ergebnis:\n" . $json . "\nEnde {"),
            self::antwort($kaputt . ' Mehr habe ich nicht.'),
            self::antwort($kaputt . "\n" . $json),
        ]);
        $abfrage = self::abfrage($client);

        $mitKlammern = $abfrage->fragen(self::profil(), self::KEY, self::anfrage(), 'extract-v1');
        $nurKaputt = $abfrage->fragen(self::profil(), self::KEY, self::anfrage(), 'extract-v1');
        $kaputtDannGut = $abfrage->fragen(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertTrue($mitKlammern->gueltig(), implode("\n", $mitKlammern->fehler));
        self::assertSame('Bälle } und { Netze "Profi"', $mitKlammern->daten['purpose_short'] ?? null);
        self::assertSame(7, $mitKlammern->daten['category_id'] ?? null);
        self::assertSame(KiAufrufStatus::KeinJson, $nurKaputt->status, 'not the `supplier` object inside the broken one');
        self::assertTrue($kaputtDannGut->gueltig(), 'the next top-level object after a broken one');
    }

    public function testAnAnswerCutOffAtTheTokenLimitSaysSo(): void
    {
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/abgeschnitten.json'), FakeLlmClient::fixture('extract-v1/abgeschnitten.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertFalse($ergebnis->gueltig());
        self::assertSame(KiAufrufStatus::KeinJson, $ergebnis->versuche[0]->status, 'not the complete `supplier` object inside the cut-off one');
        self::assertSame(['/: Die Antwort enthält kein JSON-Objekt.', '/: Die Antwort wurde an der Token-Grenze abgeschnitten.'], $ergebnis->versuche[0]->fehler);
        self::assertCount(2, $client->anfragen);
    }

    public function testATimeoutIsNotRepairedButReported(): void
    {
        $client = new FakeLlmClient([LlmFehler::ausVerbindungsfehler(KiVerbindungsfehler::zeitueberschreitung(45), self::KEY), FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertFalse($ergebnis->gueltig());
        self::assertCount(1, $client->anfragen, 'asking again does not cure a timeout');
        self::assertSame('Der KI-Anbieter hat nicht rechtzeitig geantwortet.', $ergebnis->meldung());
        $aufruf = $ergebnis->aufrufe()[0];
        self::assertSame(KiAufrufStatus::Zeitueberschreitung, $aufruf->status);
        self::assertSame('gpt-6-luna', $aufruf->modell, "the profile's model when nobody answered");
        self::assertNull($aufruf->dauerMs);
        self::assertNull($aufruf->rohantwort);
    }

    public function testAnHttpErrorIsNotRepairedButReported(): void
    {
        $client = new FakeLlmClient([LlmFehler::http(429, FakeChatHttp::fixture('verbindungstest/429-limit.json')->body, self::KEY)]);

        $ergebnis = self::abfrage($client)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertFalse($ergebnis->gueltig());
        self::assertCount(1, $client->anfragen);
        self::assertSame('Der KI-Anbieter hat die Anfrage abgelehnt.', $ergebnis->meldung());
        self::assertSame(KiAufrufStatus::HttpFehler, $ergebnis->aufrufe()[0]->status);
        self::assertSame(429, $ergebnis->aufrufe()[0]->httpStatus);
    }

    public function testEndToEndOverTheWireWithRepair(): void
    {
        $http = new FakeChatHttp([FakeChatHttp::fixture('extract-v1/ungueltig-schema.json'), FakeChatHttp::fixture('extract-v1/gueltig-repariert.json')]);
        $prompts = self::prompts();
        $abfrage = new KiAbfrage(new OpenAiCompatibleClient($http, $prompts), $prompts);

        $ergebnis = $abfrage->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertTrue($ergebnis->gueltig());
        self::assertCount(2, $http->anfragen);
        $messages = $http->anfragen[1]['body']['messages'];
        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        self::assertSame($ergebnis->versuche[0]->inhalt, $messages[2]['content']);
        self::assertArrayHasKey('response_format', $http->anfragen[1]['body']);
        self::assertCount(2, $messages[1]['content'], 'text and the page');
    }

    public function testEndToEndOverTheWireWithAnHttpError(): void
    {
        $http = new FakeChatHttp([new ChatHttpAntwort(500, '{"error":{"message":"The server had an error"}}')]);
        $prompts = self::prompts();

        $ergebnis = new KiAbfrage(new OpenAiCompatibleClient($http, $prompts), $prompts)->ausfuehren(self::profil(), self::KEY, self::anfrage(), 'extract-v1');

        self::assertFalse($ergebnis->gueltig());
        self::assertSame(500, $ergebnis->aufrufe()[0]->httpStatus);
        self::assertCount(1, $http->anfragen);
    }

    public function testTheTwoStepsCanRunApartAsTheJobChainNeeds(): void
    {
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/ungueltig-schema.json'), FakeLlmClient::fixture('extract-v1/gueltig-repariert.json')]);
        $abfrage = self::abfrage($client);
        $anfrage = self::anfrage();

        $erster = $abfrage->fragen(self::profil(), self::KEY, $anfrage, 'extract-v1');
        self::assertTrue($erster->reparaturMoeglich());
        $zweiter = $abfrage->reparieren(self::profil(), self::KEY, $anfrage, $erster, 'extract-v1');

        self::assertTrue(new KiErgebnis([$erster, $zweiter])->gueltig());
        self::assertFalse($zweiter->reparaturMoeglich(), 'one repair only');
    }

    public function testOnlyAnAnswerThatDoesNotFitCanBeRepaired(): void
    {
        $client = new FakeLlmClient([
            FakeLlmClient::fixture('extract-v1/gueltig.json'),
            LlmFehler::ausVerbindungsfehler(KiVerbindungsfehler::verbindung('x'), self::KEY),
            FakeLlmClient::fixture('extract-v1/kein-json.json'),
            FakeLlmClient::fixture('extract-v1/kein-json.json'),
        ]);
        $abfrage = self::abfrage($client);
        $anfrage = self::anfrage();

        $gueltig = $abfrage->fragen(self::profil(), self::KEY, $anfrage, 'extract-v1');
        $ohneAntwort = $abfrage->fragen(self::profil(), self::KEY, $anfrage, 'extract-v1');
        $repariert = $abfrage->reparieren(self::profil(), self::KEY, $anfrage, $abfrage->fragen(self::profil(), self::KEY, $anfrage, 'extract-v1'), 'extract-v1');

        foreach ([$gueltig, $ohneAntwort, $repariert] as $versuch) {
            try {
                $abfrage->reparieren(self::profil(), self::KEY, $anfrage, $versuch, 'extract-v1');
                self::fail('LogicException expected');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertCount(4, $client->anfragen);
    }

    public function testARequestWithoutSchemaIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        self::abfrage(new FakeLlmClient([]))->fragen(self::profil(), self::KEY, new LlmAnfrage('', 'x'), 'extract-v1');
    }

    private static function assertKeinInhalt(KiErgebnis $ergebnis): void
    {
        $texte = [$ergebnis->meldung()];
        foreach ($ergebnis->versuche as $versuch) {
            array_push($texte, ...$versuch->fehler);
            $texte[] = $versuch->status->meldung();
        }
        $text = implode("\n", $texte);
        foreach (self::INHALTE as $inhalt) {
            self::assertStringNotContainsString($inhalt, $text, 'business data stays out of messages and errors');
        }
    }

    private static function antwort(string $inhalt): LlmAntwort
    {
        return new LlmAntwort($inhalt, 'gpt-6-luna', null, null, null, 'stop', 900, '{}');
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

    private static function profil(bool $jsonSchema = true): KiAnbieter
    {
        return new KiAnbieter(
            id: 3,
            name: 'Testprofil',
            baseUrl: 'https://api.example.org/v1',
            model: 'gpt-6-luna',
            faehigkeiten: new KiFaehigkeiten(true, $jsonSchema, 4, 4000),
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
}
