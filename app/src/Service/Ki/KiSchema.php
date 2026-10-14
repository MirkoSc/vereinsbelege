<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * A JSON schema an AI answer has to follow (docs/spec/03-erfassung-und-ki.md
 * section 6: "Validierung gegen das Schema in PHP"), e.g.
 * app/prompts/extract-v1.schema.json, and its validator.
 *
 * Own code instead of a library (none at runtime so far, composer.json):
 * only the subset of JSON Schema that OpenAI's strict structured outputs
 * understand, so what the provider enforces and what PHP checks are the
 * same rules - `type` (also a list, for `null`), `properties`, `required`,
 * `additionalProperties` (boolean), `items`, `enum`, `pattern`,
 * `minimum`/`maximum`, `minItems`/`maxItems` and `description`. Any other
 * keyword is refused when the schema is loaded: a rule that is written down
 * but not checked would be worse than none.
 *
 * Errors name the place (`/supplier/iban`) and the rule, never the value:
 * they go back to the model in the repair call and may end up in a log or
 * on a page, the values are business data.
 */
final readonly class KiSchema
{
    public const int FEHLER_MAX = 20;

    private const array SCHLUESSELWOERTER = [
        'type', 'properties', 'required', 'additionalProperties', 'items', 'enum',
        'pattern', 'minimum', 'maximum', 'minItems', 'maxItems', 'description',
    ];

    private const array TYPEN = ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'];

    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(public string $name, public array $schema)
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1) {
            throw new \LogicException(sprintf('Invalid schema name "%s".', $name));
        }
        self::pruefeSchema($schema, '');
    }

    /**
     * The schema as JSON - for the prompt of a profile without `json_schema`.
     */
    public function alsJson(): string
    {
        return json_encode($this->schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param mixed $wert decoded with json_decode() WITHOUT `associative`,
     *        so an object (stdClass) and an array (list) stay apart
     *
     * @return list<string> at most FEHLER_MAX, empty when the value fits
     */
    public function pruefe(mixed $wert): array
    {
        $fehler = [];
        self::pruefeWert($this->schema, $wert, '', $fehler);

        return array_slice($fehler, 0, self::FEHLER_MAX);
    }

    /**
     * @param array<mixed> $schema
     */
    private static function pruefeSchema(array $schema, string $pfad): void
    {
        $ort = $pfad === '' ? '/' : $pfad;
        foreach (array_keys($schema) as $schluessel) {
            if (!in_array($schluessel, self::SCHLUESSELWOERTER, true)) {
                throw new \LogicException(sprintf('Unsupported schema keyword "%s" at %s.', $schluessel, $ort));
            }
        }

        $typen = self::typen($schema);
        if ($typen === [] || array_diff($typen, self::TYPEN) !== []) {
            throw new \LogicException(sprintf('Schema at %s needs a known "type".', $ort));
        }
        if (array_key_exists('additionalProperties', $schema) && !is_bool($schema['additionalProperties'])) {
            throw new \LogicException(sprintf('"additionalProperties" at %s must be a boolean.', $ort));
        }
        if (isset($schema['pattern']) && (!is_string($schema['pattern']) || @preg_match(self::regex($schema['pattern']), '') === false)) {
            throw new \LogicException(sprintf('Invalid "pattern" at %s.', $ort));
        }
        if (isset($schema['enum']) && (!is_array($schema['enum']) || !array_is_list($schema['enum']) || $schema['enum'] === [])) {
            throw new \LogicException(sprintf('"enum" at %s must be a non-empty list.', $ort));
        }

        $eigenschaften = $schema['properties'] ?? [];
        if (!is_array($eigenschaften)) {
            throw new \LogicException(sprintf('"properties" at %s must be an object.', $ort));
        }
        foreach ($eigenschaften as $name => $teil) {
            if (!is_array($teil)) {
                throw new \LogicException(sprintf('Property "%s" at %s needs a schema.', $name, $ort));
            }
            self::pruefeSchema($teil, $pfad . '/' . $name);
        }
        $pflicht = $schema['required'] ?? [];
        if (!is_array($pflicht) || array_diff($pflicht, array_map('strval', array_keys($eigenschaften))) !== []) {
            throw new \LogicException(sprintf('"required" at %s names properties that do not exist.', $ort));
        }
        if (isset($schema['items'])) {
            if (!is_array($schema['items'])) {
                throw new \LogicException(sprintf('"items" at %s needs a schema.', $ort));
            }
            self::pruefeSchema($schema['items'], $pfad . '/*');
        }
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $fehler
     */
    private static function pruefeWert(array $schema, mixed $wert, string $pfad, array &$fehler): void
    {
        if (count($fehler) >= self::FEHLER_MAX) {
            return;
        }
        $ort = $pfad === '' ? '/' : $pfad;

        $typen = self::typen($schema);
        $typ = self::typVon($wert);
        if (!in_array($typ, $typen, true) && !($typ === 'integer' && in_array('number', $typen, true))) {
            $fehler[] = sprintf('%s: erwartet %s', $ort, implode(' oder ', $typen));

            return;
        }

        if (isset($schema['enum']) && !in_array($wert, $schema['enum'], true)) {
            $fehler[] = sprintf(
                '%s: nicht erlaubter Wert, erlaubt: %s',
                $ort,
                implode(', ', array_map(static fn(mixed $e): string => json_encode($e, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $schema['enum'])),
            );

            return;
        }

        match (true) {
            is_string($wert) => self::pruefeText($schema, $wert, $ort, $fehler),
            is_int($wert), is_float($wert) => self::pruefeZahl($schema, $wert, $ort, $fehler),
            $wert instanceof \stdClass => self::pruefeObjekt($schema, $wert, $pfad, $fehler),
            is_array($wert) => self::pruefeListe($schema, $wert, $pfad, $fehler),
            default => null,
        };
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $fehler
     */
    private static function pruefeText(array $schema, string $wert, string $ort, array &$fehler): void
    {
        if (isset($schema['pattern']) && preg_match(self::regex($schema['pattern']), $wert) !== 1) {
            $fehler[] = sprintf('%s: entspricht nicht dem Muster %s', $ort, $schema['pattern']);
        }
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $fehler
     */
    private static function pruefeZahl(array $schema, int|float $wert, string $ort, array &$fehler): void
    {
        if (isset($schema['minimum']) && $wert < $schema['minimum']) {
            $fehler[] = sprintf('%s: kleiner als %s', $ort, json_encode($schema['minimum']));
        }
        if (isset($schema['maximum']) && $wert > $schema['maximum']) {
            $fehler[] = sprintf('%s: größer als %s', $ort, json_encode($schema['maximum']));
        }
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $fehler
     */
    private static function pruefeObjekt(array $schema, \stdClass $wert, string $pfad, array &$fehler): void
    {
        $eigenschaften = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $vorhanden = get_object_vars($wert);

        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $pflicht) {
            if (!array_key_exists($pflicht, $vorhanden)) {
                $fehler[] = sprintf('%s/%s: Pflichtfeld fehlt', $pfad, $pflicht);
            }
        }
        foreach ($vorhanden as $name => $teil) {
            $name = (string) $name;
            if (isset($eigenschaften[$name]) && is_array($eigenschaften[$name])) {
                self::pruefeWert($eigenschaften[$name], $teil, $pfad . '/' . $name, $fehler);
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                // The model chose this name: shown only when it looks like
                // a field name, not like content.
                $fehler[] = preg_match('/^[A-Za-z0-9_]{1,40}$/', $name) === 1
                    ? sprintf('%s/%s: Feld ist nicht vorgesehen', $pfad, $name)
                    : sprintf('%s: enthält ein nicht vorgesehenes Feld', $pfad === '' ? '/' : $pfad);
            }
        }
    }

    /**
     * @param array<mixed> $schema
     * @param array<mixed> $wert
     * @param list<string> $fehler
     */
    private static function pruefeListe(array $schema, array $wert, string $pfad, array &$fehler): void
    {
        $ort = $pfad === '' ? '/' : $pfad;
        if (isset($schema['minItems']) && count($wert) < $schema['minItems']) {
            $fehler[] = sprintf('%s: weniger als %d Einträge', $ort, $schema['minItems']);
        }
        if (isset($schema['maxItems']) && count($wert) > $schema['maxItems']) {
            $fehler[] = sprintf('%s: mehr als %d Einträge', $ort, $schema['maxItems']);
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach (array_values($wert) as $i => $teil) {
                self::pruefeWert($schema['items'], $teil, $pfad . '/' . $i, $fehler);
            }
        }
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<string>
     */
    private static function typen(array $schema): array
    {
        $typ = $schema['type'] ?? null;

        return match (true) {
            is_string($typ) => [$typ],
            is_array($typ) && array_is_list($typ) && array_filter($typ, 'is_string') === $typ => $typ,
            default => [],
        };
    }

    /**
     * The JSON type of a value decoded without `associative`; a float with
     * no fraction counts as an integer, as in JSON Schema (`7.0` is `7`).
     */
    private static function typVon(mixed $wert): string
    {
        return match (true) {
            $wert === null => 'null',
            is_bool($wert) => 'boolean',
            is_int($wert) => 'integer',
            is_float($wert) => is_finite($wert) && floor($wert) === $wert ? 'integer' : 'number',
            is_string($wert) => 'string',
            $wert instanceof \stdClass => 'object',
            is_array($wert) => 'array',
            default => 'unbekannt',
        };
    }

    private static function regex(string $muster): string
    {
        return '/' . str_replace('/', '\/', $muster) . '/u';
    }
}
