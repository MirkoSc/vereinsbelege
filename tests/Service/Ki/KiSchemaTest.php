<?php

declare(strict_types=1);

namespace App\Tests\Service\Ki;

use App\Domain\InvoiceType;
use App\Service\Ki\KiPrompts;
use App\Service\Ki\KiSchema;
use App\Service\Processing\ERechnung\ERechnung;
use App\Service\Processing\ERechnung\ERechnungSyntax;
use App\Tests\Support\FakeLlmClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The schema validator of the AI client (M7-2, issue #44,
 * docs/spec/03-erfassung-und-ki.md section 6) and the formal schema
 * app/prompts/extract-v1.schema.json.
 */
final class KiSchemaTest extends TestCase
{
    public function testAValueThatFitsHasNoErrors(): void
    {
        $schema = self::schema();

        self::assertSame([], $schema->pruefe(self::json('{"name":"A","betrag":"12.50","anzahl":3,"anteil":0.5,"aktiv":true,"datum":null,"posten":[{"art":"x"}],"art":"bar"}')));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function verstoesse(): iterable
    {
        $gueltig = ['name' => 'A', 'betrag' => '12.50', 'anzahl' => 3, 'anteil' => 0.5, 'aktiv' => true, 'datum' => null, 'posten' => [['art' => 'x']], 'art' => 'bar'];
        $mit = static fn(array $aenderung): string => json_encode(array_merge($gueltig, $aenderung), JSON_THROW_ON_ERROR);
        $ohne = static function (string $feld) use ($gueltig): string {
            unset($gueltig[$feld]);

            return json_encode($gueltig, JSON_THROW_ON_ERROR);
        };

        yield 'not an object' => ['[1,2]', ['/: erwartet object']];
        yield 'empty list is no object' => ['[]', ['/: erwartet object']];
        yield 'required missing' => [$ohne('name'), ['/name: Pflichtfeld fehlt']];
        yield 'wrong type' => [$mit(['name' => 5]), ['/name: erwartet string']];
        yield 'null where not allowed' => [$mit(['name' => null]), ['/name: erwartet string']];
        yield 'nullable with wrong type' => [$mit(['datum' => 20260912]), ['/datum: erwartet string oder null']];
        yield 'pattern' => [$mit(['betrag' => '1.234,56']), ['/betrag: entspricht nicht dem Muster ^-?[0-9]+(\.[0-9]{1,2})?$']];
        yield 'pattern of a nullable string' => [$mit(['datum' => '12.09.2026']), ['/datum: entspricht nicht dem Muster ^[0-9]{4}-[0-9]{2}-[0-9]{2}$']];
        yield 'integer, not a fraction' => [$mit(['anzahl' => 2.5]), ['/anzahl: erwartet integer']];
        yield 'integer as string' => [$mit(['anzahl' => '3']), ['/anzahl: erwartet integer']];
        yield 'minimum' => [$mit(['anteil' => -0.1]), ['/anteil: kleiner als 0']];
        yield 'maximum' => [$mit(['anteil' => 1.5]), ['/anteil: größer als 1']];
        yield 'boolean' => [$mit(['aktiv' => 'ja']), ['/aktiv: erwartet boolean']];
        yield 'enum' => [$mit(['art' => 'scheck']), ['/art: nicht erlaubter Wert, erlaubt: "bar", "karte", null']];
        yield 'nested required' => [$mit(['posten' => [['art' => 'x'], new \stdClass()]]), ['/posten/1/art: Pflichtfeld fehlt']];
        yield 'list item type' => [$mit(['posten' => ['x']]), ['/posten/0: erwartet object']];
        yield 'object is no list' => [$mit(['posten' => ['a' => ['art' => 'x']]]), ['/posten: erwartet array']];
        yield 'maxItems' => [$mit(['posten' => [['art' => 'a'], ['art' => 'b'], ['art' => 'c']]]), ['/posten: mehr als 2 Einträge']];
        yield 'minItems' => [$mit(['posten' => []]), ['/posten: weniger als 1 Einträge']];
        yield 'additional field' => [$mit(['extra' => 1]), ['/extra: Feld ist nicht vorgesehen']];
        yield 'additional field shaped like content' => [$mit(['Sporthaus Beispiel GmbH' => 1]), ['/: enthält ein nicht vorgesehenes Feld']];
        yield 'several at once' => [$mit(['betrag' => 12, 'aktiv' => null]), ['/betrag: erwartet string', '/aktiv: erwartet boolean']];
    }

    /**
     * @param list<string> $erwartet
     */
    #[DataProvider('verstoesse')]
    public function testViolationsNameThePlaceAndTheRule(string $json, array $erwartet): void
    {
        self::assertSame($erwartet, self::schema()->pruefe(self::json($json)));
    }

    public function testAnIntegralFloatCountsAsInteger(): void
    {
        self::assertSame([], self::schema()->pruefe(self::json('{"name":"A","betrag":"1","anzahl":3.0,"anteil":1,"aktiv":false,"datum":"2026-01-31","posten":[{"art":"x"}],"art":null}')));
    }

    public function testErrorsNeverCarryTheValue(): void
    {
        $fehler = self::schema()->pruefe(self::json('{"name":["Sporthaus Beispiel GmbH"],"betrag":"DE89370400440532013000","anzahl":"geheim","anteil":0.5,"aktiv":"Trainingsbälle","datum":"Vereinsheim","posten":[{"art":7}],"art":"Kassenwart"}'));

        self::assertNotSame([], $fehler);
        $text = implode("\n", $fehler);
        foreach (['Sporthaus', 'DE8937', 'geheim', 'Trainingsbälle', 'Vereinsheim', 'Kassenwart'] as $inhalt) {
            self::assertStringNotContainsString($inhalt, $text);
        }
    }

    public function testTheNumberOfErrorsIsCapped(): void
    {
        $liste = json_encode(['name' => 'A', 'betrag' => '1', 'anzahl' => 1, 'anteil' => 0, 'aktiv' => true, 'datum' => null, 'art' => 'bar', 'posten' => array_fill(0, 2, ['art' => 1])], JSON_THROW_ON_ERROR);
        $schema = new KiSchema('liste', [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ]);

        self::assertCount(KiSchema::FEHLER_MAX, $schema->pruefe(array_fill(0, 50, 1)));
        self::assertCount(2, self::schema()->pruefe(self::json($liste)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function kaputteSchemata(): iterable
    {
        yield 'unknown keyword' => [['type' => 'string', 'format' => 'date']];
        yield 'nested unknown keyword' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3]]]];
        yield 'anyOf is not supported' => [['type' => 'object', 'anyOf' => []]];
        yield 'no type' => [['properties' => []]];
        yield 'unknown type' => [['type' => 'datum']];
        yield 'additionalProperties as schema' => [['type' => 'object', 'additionalProperties' => ['type' => 'string']]];
        yield 'broken pattern' => [['type' => 'string', 'pattern' => '([0-9']];
        yield 'required names nothing' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['b']]];
        yield 'empty enum' => [['type' => 'string', 'enum' => []]];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('kaputteSchemata')]
    public function testASchemaWithRulesThatCannotBeCheckedIsRefused(array $schema): void
    {
        $this->expectException(\LogicException::class);

        new KiSchema('kaputt', $schema);
    }

    public function testTheNameMustFitResponseFormat(): void
    {
        $this->expectException(\LogicException::class);

        new KiSchema('extract v1', ['type' => 'object']);
    }

    public function testExtractV1IsStrictModeCompatible(): void
    {
        $schema = self::extractV1();

        self::assertSame('extract-v1', $schema->name);
        self::assertSame([], self::strictVerstoesse($schema->schema, ''), 'OpenAI strict mode: every object lists all its properties as required and allows no others');
    }

    public function testTheValidExampleAnswerFitsAndUnreadableDateAndAmountMayBeNull(): void
    {
        $schema = self::extractV1();
        $daten = self::inhalt('gueltig.json');

        self::assertSame([], $schema->pruefe($daten));

        $daten->invoice_date = null;
        $daten->total_gross = null;
        self::assertSame([], $schema->pruefe($daten), 'null instead of a guess (issue #44)');
    }

    public function testTheInvalidExampleAnswerIsCaughtWithEveryError(): void
    {
        self::assertSame([
            '/invoice_date: Pflichtfeld fehlt',
            '/supplier/vat_id: erwartet string',
            '/total_gross: entspricht nicht dem Muster ^-?[0-9]+(\.[0-9]{1,2})?$',
            '/payment/method: nicht erlaubter Wert, erlaubt: "bar", "karte", "lastschrift", "ueberweisung", "paypal", "unbekannt"',
        ], self::extractV1()->pruefe(self::inhalt('ungueltig-schema.json')));
    }

    public function testAnEInvoiceHasTheSameShapeAsTheAiAnswer(): void
    {
        $rechnung = new ERechnung(
            syntax: ERechnungSyntax::Cii,
            profil: 'urn:cen.eu:en16931:2017',
            typ: InvoiceType::Gutschrift,
            nummer: 'GS-1',
            datum: '2026-09-12',
            faellig: null,
            leistungVon: '2026-08-01',
            leistungBis: '2026-08-31',
            waehrung: 'EUR',
            brutto: -11900,
            netto: -10000,
            steuern: [['rate' => '19', 'amount' => -1900], ['rate' => '7.5', 'amount' => 0]],
            lieferantName: 'Sporthaus Beispiel GmbH',
            lieferantAnschrift: 'Musterstraße 1',
            ustId: 'DE123456789',
            steuernummer: '',
            email: '',
            iban: 'DE89370400440532013000',
            bic: '',
            glaeubigerId: '',
            mandatsreferenz: '',
            kundennummer: '',
            vertragsnummer: '',
            zahlart: 'unbekannt',
            bezahlt: false,
            warnungen: ['Summe weicht ab'],
        );

        $daten = json_decode(json_encode($rechnung->alsExtraktion(), JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);

        self::assertSame([], self::extractV1()->pruefe($daten), 'the review page fills its form from either the same way');
    }

    private static function schema(): KiSchema
    {
        return new KiSchema('test', [
            'type' => 'object',
            'description' => 'Testschema',
            'properties' => [
                'name' => ['type' => 'string'],
                'betrag' => ['type' => 'string', 'pattern' => '^-?[0-9]+(\.[0-9]{1,2})?$'],
                'anzahl' => ['type' => 'integer'],
                'anteil' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'aktiv' => ['type' => 'boolean'],
                'datum' => ['type' => ['string', 'null'], 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
                'posten' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 2,
                    'items' => [
                        'type' => 'object',
                        'properties' => ['art' => ['type' => 'string']],
                        'required' => ['art'],
                        'additionalProperties' => false,
                    ],
                ],
                'art' => ['type' => ['string', 'null'], 'enum' => ['bar', 'karte', null]],
            ],
            'required' => ['name', 'betrag', 'anzahl', 'anteil', 'aktiv', 'datum', 'posten', 'art'],
            'additionalProperties' => false,
        ]);
    }

    private static function extractV1(): KiSchema
    {
        return new KiPrompts(dirname(__DIR__, 3) . '/app/prompts')->schema('extract-v1');
    }

    private static function json(string $json): mixed
    {
        return json_decode($json, false, flags: JSON_THROW_ON_ERROR);
    }

    private static function inhalt(string $fixture): \stdClass
    {
        $daten = json_decode(FakeLlmClient::fixture('extract-v1/' . $fixture)->inhalt, false, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $daten);

        return $daten;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<string>
     */
    private static function strictVerstoesse(array $schema, string $pfad): array
    {
        $verstoesse = [];
        $typen = (array) $schema['type'];
        if (in_array('object', $typen, true)) {
            $namen = array_keys($schema['properties'] ?? []);
            if (($schema['required'] ?? []) != $namen) {
                $verstoesse[] = $pfad . ': required != properties';
            }
            if (($schema['additionalProperties'] ?? null) !== false) {
                $verstoesse[] = $pfad . ': additionalProperties not false';
            }
            foreach ($schema['properties'] ?? [] as $name => $teil) {
                array_push($verstoesse, ...self::strictVerstoesse($teil, $pfad . '/' . $name));
            }
        }
        if (isset($schema['items'])) {
            array_push($verstoesse, ...self::strictVerstoesse($schema['items'], $pfad . '/*'));
        }
        if (isset($schema['enum']) && in_array('null', $typen, true) !== in_array(null, $schema['enum'], true)) {
            $verstoesse[] = $pfad . ': nullable enum without null (or the other way round)';
        }

        return $verstoesse;
    }
}
