<?php

declare(strict_types=1);

namespace App\Service\Export\Nextcloud;

/**
 * The club's Nextcloud folder the export is filed into (issue #95,
 * docs/spec/05-auswertung-und-export.md section 2 "Nextcloud-Ablage"): the
 * WebDAV address of the folder, the user and an app password. Kept in the
 * vault (App\Service\Export\Nextcloud\NextcloudZielSpeicher) - the password
 * opens the club's cloud.
 *
 * pruefen() holds the rules:
 * - The address is https:// only (the password goes with every request),
 *   without credentials, query or fragment, at most URL_MAX characters; a
 *   trailing "/" is dropped. It names the folder itself, e.g.
 *   https://cloud.example.org/remote.php/dav/files/kasse/Belege.
 * - User and password are required, without control characters; the user
 *   without ":" (HTTP Basic splits there).
 */
final readonly class NextcloudZiel
{
    public const int URL_MAX = 500;

    public const int BENUTZER_MAX = 200;

    public const int PASSWORT_MAX = 200;

    private function __construct(
        public string $url,
        public string $benutzer,
        public string $passwort,
    ) {
    }

    /**
     * @throws NextcloudZielFehler with a German message for the form
     */
    public static function pruefen(string $url, string $benutzer, #[\SensitiveParameter] string $passwort): self
    {
        $url = rtrim(trim($url), '/');
        $benutzer = trim($benutzer);

        if ($url === '') {
            throw new NextcloudZielFehler('Bitte die WebDAV-Adresse des Ordners angeben.');
        }
        if (mb_strlen($url) > self::URL_MAX || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new NextcloudZielFehler('Die Adresse ist zu lang oder enthält Leer- bzw. Steuerzeichen.');
        }
        $teile = parse_url($url);
        if ($teile === false || strtolower($teile['scheme'] ?? '') !== 'https' || ($teile['host'] ?? '') === '') {
            throw new NextcloudZielFehler('Die Adresse muss mit https:// beginnen – das Passwort wird bei jedem Zugriff mitgeschickt.');
        }
        if (isset($teile['user']) || isset($teile['pass']) || isset($teile['query']) || isset($teile['fragment'])) {
            throw new NextcloudZielFehler('Die Adresse darf keine Zugangsdaten, kein „?“ und kein „#“ enthalten.');
        }

        if ($benutzer === '' || mb_strlen($benutzer) > self::BENUTZER_MAX || str_contains($benutzer, ':') || self::steuerzeichen($benutzer)) {
            throw new NextcloudZielFehler('Bitte den Nextcloud-Benutzer angeben (ohne „:“, höchstens ' . self::BENUTZER_MAX . ' Zeichen).');
        }
        if ($passwort === '' || mb_strlen($passwort) > self::PASSWORT_MAX || self::steuerzeichen($passwort)) {
            throw new NextcloudZielFehler('Bitte ein App-Passwort angeben (höchstens ' . self::PASSWORT_MAX . ' Zeichen).');
        }

        return new self($url, $benutzer, $passwort);
    }

    /**
     * The address of a file or folder below the target: every segment
     * percent-encoded on its own, so a name never opens a level of its own.
     */
    public function adresse(string $pfad): string
    {
        if ($pfad === '') {
            return $this->url . '/';
        }

        return $this->url . '/' . implode('/', array_map(rawurlencode(...), explode('/', $pfad)));
    }

    /** Host only, for the page - never the user or the password. */
    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }

    /** @return array{url: string, benutzer: string, passwort: string} */
    public function alsDaten(): array
    {
        return ['url' => $this->url, 'benutzer' => $this->benutzer, 'passwort' => $this->passwort];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'benutzer' => $this->benutzer, 'passwort' => '***'];
    }

    private static function steuerzeichen(string $wert): bool
    {
        return preg_match('/[\x00-\x1f\x7f]/', $wert) === 1;
    }
}
