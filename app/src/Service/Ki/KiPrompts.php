<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * The versioned prompts and schemas under app/prompts/
 * (docs/spec/03-erfassung-und-ki.md section 6: "Prompts liegen versioniert
 * unter app/prompts/*.md, nicht im Code"). A prompt is Markdown with
 * `{{platzhalter}}`; every placeholder has to be filled and every value has
 * to have its placeholder, so a renamed one fails loudly instead of sending
 * a half-filled prompt.
 */
final readonly class KiPrompts
{
    public function __construct(private string $verzeichnis)
    {
    }

    /**
     * @param array<string, string> $werte placeholder name => text
     */
    public function text(string $name, array $werte = []): string
    {
        $vorlage = $this->datei($name . '.md');
        $vorhanden = self::platzhalter($vorlage);
        $ersetzungen = [];
        foreach ($werte as $platzhalter => $wert) {
            if (!in_array($platzhalter, $vorhanden, true)) {
                throw new \LogicException(sprintf('Prompt "%s" has no placeholder {{%s}}.', $name, $platzhalter));
            }
            $ersetzungen['{{' . $platzhalter . '}}'] = $wert;
        }
        $offen = array_diff($vorhanden, array_keys($werte));
        if ($offen !== []) {
            throw new \LogicException(sprintf('Prompt "%s" needs a value for {{%s}}.', $name, implode('}}, {{', $offen)));
        }

        // All at once: braces inside a value (a schema, an old answer) are
        // never taken for a placeholder.
        return trim(strtr($vorlage, $ersetzungen));
    }

    public function schema(string $name): KiSchema
    {
        $schema = json_decode($this->datei($name . '.schema.json'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($schema)) {
            throw new \LogicException(sprintf('Schema "%s" is not a JSON object.', $name));
        }

        return new KiSchema($name, $schema);
    }

    /**
     * @return list<string>
     */
    private static function platzhalter(string $vorlage): array
    {
        preg_match_all('/\{\{([a-z_]+)\}\}/', $vorlage, $treffer);

        return array_values(array_unique($treffer[1]));
    }

    private function datei(string $dateiname): string
    {
        if (preg_match('/^[a-z0-9-]+(\.schema\.json|\.md)$/', $dateiname) !== 1) {
            throw new \LogicException(sprintf('Invalid prompt name "%s".', $dateiname));
        }
        $pfad = $this->verzeichnis . '/' . $dateiname;
        $inhalt = is_file($pfad) ? file_get_contents($pfad) : false;
        if ($inhalt === false) {
            throw new \LogicException(sprintf('Missing prompt file "%s".', $dateiname));
        }

        return $inhalt;
    }
}
