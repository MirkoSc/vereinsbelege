<?php

declare(strict_types=1);

namespace App\Service\Ki;

use App\Repository\AiProviderRepository;

/**
 * Creating, changing, deleting AI provider profiles and choosing the
 * default (M7-1, issue #41, docs/spec/03-erfassung-und-ki.md section 6) -
 * the rules, not the SQL:
 *
 * - Name required, unique, at most NAME_MAX characters; model required.
 * - The base URL is HTTPS only (the key travels with every call; for the
 *   llama.cpp server that means the reverse proxy with TLS of E-08), with
 *   no credentials, query or fragment, and without the
 *   `/chat/completions` the client appends itself.
 * - Numbers within fixed bounds; a profile with `vision` sends at least one
 *   picture per call.
 * - The API key is printable ASCII without spaces: it ends up in an HTTP
 *   header, and a line break there would be header injection.
 * - The default profile cannot be deleted, and only an active profile can
 *   become the default. There is no fallback profile yet - it arrives with
 *   the client that uses it (M7-2).
 */
final readonly class KiAnbieterService
{
    public const int NAME_MAX = 100;

    public const int MODEL_MAX = 100;

    public const int BASE_URL_MAX = 255;

    public const int KEY_MAX = 500;

    public const int TIMEOUT_MIN = 5;

    public const int TIMEOUT_MAX = 300;

    public function __construct(private AiProviderRepository $anbieter)
    {
    }

    /**
     * @param array{name: string, base_url: string, model: string, vision: bool, json_schema: bool, max_images: string, max_tokens: string, timeout_s: string, active: bool} $felder
     *
     * @throws KiAnbieterRegelverstoss
     */
    public function anlegen(array $felder, #[\SensitiveParameter] ?string $apiKey): int
    {
        $name = $this->pruefeName($felder['name'], null);
        [$baseUrl, $model, $faehigkeiten, $timeout] = self::pruefe($felder);

        return $this->anbieter->insert($name, $baseUrl, $model, $faehigkeiten, $timeout, $felder['active'], self::pruefeKey($apiKey));
    }

    /**
     * @param array{name: string, base_url: string, model: string, vision: bool, json_schema: bool, max_images: string, max_tokens: string, timeout_s: string, active: bool} $felder
     * @param ?string $neuerKey null keeps the stored key, '' removes it
     *
     * @throws KiAnbieterRegelverstoss
     */
    public function aendern(int $id, array $felder, #[\SensitiveParameter] ?string $neuerKey): void
    {
        $this->profil($id);
        $name = $this->pruefeName($felder['name'], $id);
        [$baseUrl, $model, $faehigkeiten, $timeout] = self::pruefe($felder);

        $this->anbieter->update($id, $name, $baseUrl, $model, $faehigkeiten, $timeout, $felder['active'], self::pruefeKey($neuerKey));
    }

    /**
     * @throws KiAnbieterRegelverstoss
     */
    public function loeschen(int $id): void
    {
        if ($this->profil($id)->isDefault) {
            throw new KiAnbieterRegelverstoss('Das Standardprofil lässt sich nicht löschen – zuerst ein anderes Profil zum Standard machen.');
        }

        $this->anbieter->delete($id);
    }

    /**
     * @throws KiAnbieterRegelverstoss
     */
    public function alsStandard(int $id): void
    {
        if (!$this->profil($id)->active) {
            throw new KiAnbieterRegelverstoss('Ein deaktiviertes Profil kann nicht Standard werden.');
        }

        $this->anbieter->setDefault($id);
    }

    /**
     * @return ?string the normalised base URL (no trailing slash), or null
     *         when it is not acceptable
     */
    public static function normalisiereBaseUrl(string $eingabe): ?string
    {
        $url = rtrim(trim($eingabe), '/');
        if ($url === '' || strlen($url) > self::BASE_URL_MAX || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $teile = parse_url($url);
        if (
            $teile === false
            || strtolower($teile['scheme'] ?? '') !== 'https'
            || ($teile['host'] ?? '') === ''
            || isset($teile['user'])
            || isset($teile['pass'])
            || isset($teile['query'])
            || isset($teile['fragment'])
            || str_ends_with(strtolower($teile['path'] ?? ''), '/chat/completions')
        ) {
            return null;
        }

        return $url;
    }

    /**
     * @param array{name: string, base_url: string, model: string, vision: bool, json_schema: bool, max_images: string, max_tokens: string, timeout_s: string, active: bool} $felder
     *
     * @return array{string, string, KiFaehigkeiten, int}
     */
    private static function pruefe(array $felder): array
    {
        $baseUrl = self::normalisiereBaseUrl($felder['base_url']);
        if ($baseUrl === null) {
            throw new KiAnbieterRegelverstoss(
                'Die Basis-URL muss mit https:// beginnen und darf weder Zugangsdaten, Parameter noch „/chat/completions“ enthalten (z. B. https://api.openai.com/v1).',
            );
        }

        $model = trim($felder['model']);
        if ($model === '') {
            throw new KiAnbieterRegelverstoss('Bitte ein Modell angeben.');
        }
        if (mb_strlen($model) > self::MODEL_MAX) {
            throw new KiAnbieterRegelverstoss(sprintf('Der Modellname darf höchstens %d Zeichen lang sein.', self::MODEL_MAX));
        }

        $maxImages = self::zahl($felder['max_images'], 0, KiFaehigkeiten::MAX_IMAGES_GRENZE, 'Bilder je Aufruf');
        if ($felder['vision'] && $maxImages === 0) {
            throw new KiAnbieterRegelverstoss('Wenn das Modell Bilder verarbeitet, muss mindestens ein Bild je Aufruf erlaubt sein.');
        }
        $maxTokens = self::zahl($felder['max_tokens'], 1, KiFaehigkeiten::MAX_TOKENS_GRENZE, 'Max. Tokens');
        $timeout = self::zahl($felder['timeout_s'], self::TIMEOUT_MIN, self::TIMEOUT_MAX, 'Zeitlimit');

        return [
            $baseUrl,
            $model,
            new KiFaehigkeiten($felder['vision'], $felder['json_schema'], $maxImages, $maxTokens),
            $timeout,
        ];
    }

    private static function zahl(string $eingabe, int $min, int $max, string $feld): int
    {
        $wert = filter_var(trim($eingabe), FILTER_VALIDATE_INT);
        if ($wert === false || $wert < $min || $wert > $max) {
            throw new KiAnbieterRegelverstoss(sprintf('„%s“ muss eine ganze Zahl von %d bis %d sein.', $feld, $min, $max));
        }

        return $wert;
    }

    private static function pruefeKey(#[\SensitiveParameter] ?string $key): ?string
    {
        if ($key === null || $key === '') {
            return $key;
        }
        if (strlen($key) > self::KEY_MAX || preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
            throw new KiAnbieterRegelverstoss('Der API-Key darf nur sichtbare ASCII-Zeichen ohne Leerzeichen enthalten.');
        }

        return $key;
    }

    private function pruefeName(string $eingabe, ?int $eigeneId): string
    {
        $name = trim($eingabe);
        if ($name === '') {
            throw new KiAnbieterRegelverstoss('Bitte einen Namen angeben.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new KiAnbieterRegelverstoss(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::NAME_MAX));
        }
        $vorhanden = $this->anbieter->findByName($name);
        if ($vorhanden !== null && $vorhanden->id !== $eigeneId) {
            throw new KiAnbieterRegelverstoss(sprintf('Ein Profil „%s“ gibt es schon.', $name));
        }

        return $name;
    }

    private function profil(int $id): KiAnbieter
    {
        return $this->anbieter->find($id) ?? throw new KiAnbieterRegelverstoss('Dieses Profil gibt es nicht (mehr).');
    }
}
