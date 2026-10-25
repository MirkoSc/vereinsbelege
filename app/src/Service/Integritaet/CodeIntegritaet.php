<?php

declare(strict_types=1);

namespace App\Service\Integritaet;

use App\Service\Update\ReleaseDownloader;
use App\Service\Update\ReleaseSignatur;
use App\Service\Update\ReleaseSwitcher;

/**
 * Code integrity check (docs/spec/01-sicherheit.md section 8, issue
 * #79/M13-2, #241): compares every file of the active release (current/)
 * with the per-file checksums of the installed release and lists what
 * differs - changed, missing, and files that are not part of the release at
 * all (a dropped-in script is the typical crude manipulation). A last step
 * looks at what lies outside current/ but runs with every request: the
 * docroot web/ (shim, .htaccess, .user.ini, foreign files) and the
 * permissions and structure of shared/config.php (kind `umgebung`).
 *
 * The list lies in the release directory itself (current/checksums.txt,
 * issue #241) and moves with every switch and rollback; an older
 * installation's shared/release_checksums.txt is the fallback. Once a
 * release signing key is fixed (ReleaseSignatur::PUBLIC_KEY) the list must
 * carry a valid signature, so whoever can change files over FTP can no
 * longer rewrite the list to match. Before that the check catches crude
 * tampering and accidental edits only.
 *
 * A step chain of short requests (CLAUDE.md section 1): each schritt()
 * hashes at most PORTION_DATEIEN files or PORTION_SEKUNDEN seconds, the last
 * one walks the tree for additional files. State in `integritaet.json`
 * under shared/var (paths and counts only - never business data, the
 * release holds none), guarded by an flock so two tabs cannot advance at
 * once. Nothing is written into current/, so a chain that dies halfway is
 * simply continued or started again.
 */
final readonly class CodeIntegritaet
{
    public const int PORTION_DATEIEN = 500;

    /** Seconds of hashing per step - well inside max_execution_time = 30. */
    public const float PORTION_SEKUNDEN = 3.0;

    /**
     * Paths kept per kind of finding. The counts are always complete; the
     * lists are capped so a release whose every file differs (e.g. line
     * endings converted by an FTP client) keeps a small state file.
     */
    public const int MAX_PFADE = 200;

    public const string PHASE_DATEIEN = 'dateien';
    public const string PHASE_ZUSAETZLICH = 'zusaetzlich';
    public const string PHASE_UMGEBUNG = 'umgebung';
    public const string PHASE_FERTIG = 'fertig';

    /** The kinds of finding, in the order the page shows them. */
    public const array ARTEN = ['geaendert', 'fehlend', 'zusaetzlich', 'umgebung'];

    /** Files the docroot holds by design (docs/spec/06-betrieb.md section 1). */
    public const array DOCROOT_DATEIEN = ['index.php', '.htaccess', '.user.ini'];

    /**
     * Permission bits shared/config.php must not have: group write and
     * anything for others. 0600 (what the installer sets) and 0640 pass.
     */
    public const int CONFIG_VERBOTENE_RECHTE = 0o027;

    private const string STATUS = 'integritaet.json';

    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const string SPERRE = 'integritaet.lock';

    /**
     * @param string $releaseRoot current/ - the files under test
     * @param string $listenDatei shared/release_checksums.txt - fallback when
     *        current/ has no list of its own (installed before issue #241)
     * @param string $statusDir where the chain keeps its state (shared/var)
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     * @param string|null $webDir the docroot; null skips its checks
     * @param string|null $configDatei shared/config.php; null skips its checks
     * @param string $publicKey ReleaseSignatur::PUBLIC_KEY; tests pass their own
     */
    public function __construct(
        private string $releaseRoot,
        private string $listenDatei,
        private string $statusDir,
        private ?\Closure $clock = null,
        private int $portionDateien = self::PORTION_DATEIEN,
        private float $portionSekunden = self::PORTION_SEKUNDEN,
        private ?string $webDir = null,
        private ?string $configDatei = null,
        private string $publicKey = ReleaseSignatur::PUBLIC_KEY,
    ) {
    }

    /**
     * Why no check can run right now, or null when it can. A sentence for
     * the admin page.
     */
    public function hindernis(): ?string
    {
        return $this->hindernisFuer($this->liste());
    }

    /**
     * A warning that does not stop the check: the list names another
     * version than current/VERSION. Since issue #241 the list moves with the
     * release directory, so this only happens with the shared/ list of an
     * older installation (after a rollback or an update stopped before the
     * switch - the older updater stored the list at the download step) - or
     * when current/VERSION itself was changed. Never a reason to refuse: a
     * tampered VERSION file must not switch the check off - it is reported
     * as a changed file like any other.
     */
    public function warnung(): ?string
    {
        $liste = $this->liste();
        $installiert = $this->installierteVersion();
        if ($liste === null || $liste->version === null || $liste->version === $installiert) {
            return null;
        }

        return sprintf(
            'Die Prüfsummenliste gehört zu Version %s, current/VERSION nennt %s. Bei Installationen aus der '
            . 'Zeit vor der Liste im Release passiert das nach einem Rollback oder einem vor dem Umschalten '
            . 'abgebrochenen Update – dann weichen viele Dateien ab, bis das nächste Update vollständig '
            . 'durchläuft. Ohne einen solchen Grund ist die VERSION-Datei selbst verändert worden.',
            $liste->version,
            $installiert ?? '(keine)',
        );
    }

    /**
     * Why the list cannot be trusted although a signing key is fixed:
     * signature missing or wrong. Null when it is valid or no key is fixed
     * yet. A reason not to run the check - its result would rest on a list
     * an attacker may have rewritten - and an alarm of its own.
     */
    public function echtheitsfehler(): ?string
    {
        if (!ReleaseSignatur::erzwungen($this->publicKey)) {
            return null;
        }
        $datei = $this->listenPfad();
        if ($datei === null) {
            // Every installation since issue #241 has a list in its release;
            // a release without one (current/VERSION present - the
            // development environment has none) lost it. Deleting the list
            // must not quietly switch the check off.
            return $this->installierteVersion() === null
                ? null
                : 'Die Prüfsummenliste des installierten Releases fehlt (current/checksums.txt). Sie wurde '
                    . 'entfernt – Zugangsdaten zum Webspace ändern und das Release über „Update“ bzw. setup.php '
                    . 'neu einspielen.';
        }
        $signaturDatei = $this->releaseRoot . '/' . ReleaseSignatur::ASSET;
        $signatur = $datei !== $this->listenDatei && is_file($signaturDatei)
            ? (string) file_get_contents($signaturDatei)
            : null;
        try {
            ReleaseSignatur::pruefe((string) file_get_contents($datei), $signatur, $this->publicKey);
        } catch (\RuntimeException) {
            return $signatur === null
                ? 'Die Prüfsummenliste ist nicht signiert, obwohl diese Version einen Signaturschlüssel kennt. '
                    . 'Ihr kann nicht vertraut werden. Nach dem ersten Update auf eine signierte Version ist die '
                    . 'Prüfung wieder möglich; geschieht das danach erneut, wurde die Liste entfernt oder ersetzt – '
                    . 'Zugangsdaten zum Webspace ändern und das Release neu einspielen.'
                : 'Die Signatur der Prüfsummenliste ist ungültig – die Liste oder ihre Signatur wurde verändert. '
                    . 'Zugangsdaten zum Webspace ändern und das Release über „Update“ bzw. setup.php neu einspielen.';
        }

        return null;
    }

    /**
     * A note when the list is not protected by a signature because no key
     * is fixed yet - the page says what the check can and cannot catch.
     */
    public function signaturHinweis(): ?string
    {
        if ($this->listenPfad() === null || ReleaseSignatur::erzwungen($this->publicKey)) {
            return null;
        }

        return 'Die Prüfsummenliste ist (noch) nicht durch eine Signatur geschützt: wer Dateien auf dem Webspace '
            . 'ändern kann, könnte auch die Liste anpassen.';
    }

    private function hindernisFuer(?Pruefsummenliste $liste): ?string
    {
        if ($liste === null) {
            return $this->echtheitsfehler() ?? 'Für diese Installation liegt keine Prüfsummenliste vor (current/checksums.txt). '
                . 'Sie entsteht bei der Installation mit setup.php und bei jedem Update; '
                . 'in der Entwicklungsumgebung gibt es sie nicht.';
        }
        if ($liste->dateien === []) {
            return 'Die Prüfsummenliste des installierten Releases enthält noch keine Prüfsummen je Datei – '
                . 'die liefern erst neuere Releases mit. Nach dem nächsten Update ist die Prüfung möglich.';
        }

        return $this->echtheitsfehler();
    }

    /**
     * The current or last chain, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function stand(): ?array
    {
        $pfad = $this->statusDir . '/' . self::STATUS;
        if (!is_file($pfad)) {
            return null;
        }
        $daten = json_decode((string) file_get_contents($pfad), true);

        return is_array($daten) && isset($daten['phase'], $daten['anzahl']) ? $daten : null;
    }

    /**
     * Starts a new check; a previous result is replaced.
     *
     * @return array<string, mixed>
     *
     * @throws IntegritaetPruefungNichtMoeglich when hindernis() names a reason
     * @throws IntegritaetPruefungLaeuftBereits when another step holds the lock
     */
    public function starten(): array
    {
        return $this->gesperrt(function (): array {
            $liste = $this->liste();
            $hindernis = $this->hindernisFuer($liste);
            if ($hindernis !== null || $liste === null) {
                throw new IntegritaetPruefungNichtMoeglich($hindernis ?? 'Keine Prüfsummenliste vorhanden.');
            }

            $jetzt = $this->jetzt();
            $stand = [
                'phase' => self::PHASE_DATEIEN,
                'version' => $liste->version ?? $this->installierteVersion(),
                'installiert' => $this->installierteVersion(),
                'liste' => $liste->fingerabdruck,
                'position' => 0,
                'gesamt' => count($liste->dateien),
                'anzahl' => array_fill_keys(self::ARTEN, 0),
                'pfade' => array_fill_keys(self::ARTEN, []),
                'gestartet' => $jetzt->format('Y-m-d H:i:s'),
                'beendet' => null,
            ];
            $this->speichern($stand);

            return [...$stand, 'gerade_fertig' => false];
        });
    }

    /**
     * One step of the chain.
     *
     * @return array<string, mixed> the state after the step; `gerade_fertig`
     *         is true only in the answer of the one step that finished the
     *         check - decided under the lock, so two tabs cannot both report
     *         (and audit) the same result.
     *
     * @param (\Closure(array<string, mixed>): void)|null $abschluss runs
     *        under the lock in the step that finishes the check, BEFORE the
     *        finished state is stored - the audit row. If it throws, the
     *        state stays unfinished and the next step tries again, so a
     *        finished check is never left without its row.
     *
     * @throws IntegritaetPruefungLaeuftBereits when another step holds the lock
     * @throws IntegritaetPruefungNichtMoeglich when the list or the installed
     *         version changed since the start
     */
    public function schritt(?\Closure $abschluss = null): array
    {
        return $this->gesperrt(function () use ($abschluss): array {
            $stand = $this->stand();
            if ($stand === null) {
                throw new \RuntimeException('Es läuft keine Integritätsprüfung.');
            }
            if ($stand['phase'] === self::PHASE_FERTIG) {
                return [...$stand, 'gerade_fertig' => false];
            }

            $liste = $this->liste();
            if ($liste === null || $liste->fingerabdruck !== $stand['liste']) {
                throw new IntegritaetPruefungNichtMoeglich(
                    'Die Prüfsummenliste hat sich während der Prüfung geändert – bitte die Prüfung neu starten.',
                );
            }
            // A rollback or switch between two steps swaps current/ under
            // the chain while the list stays - the result would mix two
            // releases.
            if ($this->installierteVersion() !== ($stand['installiert'] ?? null)) {
                throw new IntegritaetPruefungNichtMoeglich(
                    'Das installierte Release hat sich während der Prüfung geändert – bitte die Prüfung neu starten.',
                );
            }

            $stand = match ($stand['phase']) {
                self::PHASE_DATEIEN => $this->dateien($stand, $liste),
                self::PHASE_ZUSAETZLICH => $this->zusaetzlich($stand, $liste),
                self::PHASE_UMGEBUNG => $this->umgebung($stand),
                default => throw new \RuntimeException('Unbekannter Schritt der Integritätsprüfung.'),
            };
            $fertig = $stand['phase'] === self::PHASE_FERTIG;
            if ($fertig) {
                $stand['beendet'] = $this->jetzt()->format('Y-m-d H:i:s');
                if ($abschluss !== null) {
                    $abschluss($stand);
                }
            }
            $this->speichern($stand);

            return [...$stand, 'gerade_fertig' => $fertig];
        });
    }

    /**
     * Whether a finished check found no difference at all.
     *
     * @param array<string, mixed> $stand
     */
    public static function unveraendert(array $stand): bool
    {
        return array_sum(array_map('intval', (array) $stand['anzahl'])) === 0;
    }

    /**
     * Hashes the next portion of listed files.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function dateien(array $stand, Pruefsummenliste $liste): array
    {
        $pfade = array_keys($liste->dateien);
        $gesamt = count($pfade);
        $position = (int) $stand['position'];
        $beginn = hrtime(true);
        $anzahl = 0;

        while ($position < $gesamt
            && ($anzahl === 0
                || ($anzahl < $this->portionDateien && (hrtime(true) - $beginn) / 1e9 < $this->portionSekunden))) {
            $pfad = (string) $pfade[$position];
            $voll = $this->releaseRoot . '/' . $pfad;
            if (is_link($voll)) {
                // Release files are never links; one in their place may
                // point anywhere, outside current/ too - not followed.
                $stand = self::befund($stand, 'geaendert', $pfad);
            } elseif (!is_file($voll)) {
                $stand = self::befund($stand, 'fehlend', $pfad);
            } else {
                // Unreadable counts as changed: it cannot be confirmed.
                $ist = @hash_file('sha256', $voll);
                if ($ist === false || !hash_equals($liste->dateien[$pfad], $ist)) {
                    $stand = self::befund($stand, 'geaendert', $pfad);
                }
            }
            $position++;
            $anzahl++;
        }

        $stand['position'] = $position;
        if ($position >= $gesamt) {
            $stand['phase'] = self::PHASE_ZUSAETZLICH;
        }

        return $stand;
    }

    /**
     * Walks current/ once for files the release does not contain. Listing a
     * few thousand directory entries is fast (no file is read), so this is a
     * single step.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function zusaetzlich(array $stand, Pruefsummenliste $liste): array
    {
        $gefunden = [];
        $unlesbar = [];
        $this->durchsuchen($this->releaseRoot, '', $liste, $gefunden, $unlesbar);
        sort($gefunden, SORT_STRING);
        sort($unlesbar, SORT_STRING);
        // A directory that cannot be listed may hide anything; its files
        // already counted as missing, the directory itself as changed.
        foreach ($unlesbar as $pfad) {
            $stand = self::befund($stand, 'geaendert', $pfad);
        }
        foreach ($gefunden as $pfad) {
            $stand = self::befund($stand, 'zusaetzlich', $pfad);
        }
        $stand['phase'] = self::PHASE_UMGEBUNG;

        return $stand;
    }

    /**
     * What runs with every request but lies outside current/ (issue #241,
     * N-03): the docroot and shared/config.php. One short step - a handful
     * of small files.
     *
     * - web/index.php must be ReleaseSwitcher::SHIM byte for byte;
     * - web/.htaccess and web/.user.ini must match the templates of the
     *   installed release (current/docker/web/). A hand-edited file is
     *   allowed by the updater (it is kept) but reported here, so the admin
     *   sees it and can tell an intended edit from a foreign one;
     * - every other entry in web/ is foreign (setup.php included: the
     *   installer deletes it);
     * - config.php: a regular file, no permissions for others and no group
     *   write (CONFIG_VERBOTENE_RECHTE), and nothing but `return <array of
     *   literals>;` - its content is individual and is never compared,
     *   but code in it would run with every request.
     *
     * A legacy state from before #241 has no `umgebung` kind yet; it is
     * added here.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function umgebung(array $stand): array
    {
        $stand['anzahl']['umgebung'] = (int) ($stand['anzahl']['umgebung'] ?? 0);
        $stand['pfade']['umgebung'] = (array) ($stand['pfade']['umgebung'] ?? []);

        foreach ($this->webBefunde() as $befund) {
            $stand = self::befund($stand, 'umgebung', $befund);
        }
        foreach ($this->configBefunde() as $befund) {
            $stand = self::befund($stand, 'umgebung', $befund);
        }
        $stand['phase'] = self::PHASE_FERTIG;

        return $stand;
    }

    /**
     * @return list<string>
     */
    private function webBefunde(): array
    {
        if ($this->webDir === null) {
            return [];
        }
        if (!is_dir($this->webDir)) {
            return ['web/ – Verzeichnis nicht gefunden'];
        }

        $befunde = [];
        $vorlagen = ['index.php' => ReleaseSwitcher::SHIM];
        foreach (ReleaseSwitcher::DOCROOT_FILES as $datei) {
            $vorlage = $this->releaseRoot . '/docker/web/' . $datei;
            $vorlagen[$datei] = is_file($vorlage) ? (string) file_get_contents($vorlage) : null;
        }
        foreach ($vorlagen as $datei => $soll) {
            $voll = $this->webDir . '/' . $datei;
            if (is_link($voll)) {
                $befunde[] = 'web/' . $datei . ' – Verweis (Symlink) statt Datei';
            } elseif (!is_file($voll)) {
                $befunde[] = 'web/' . $datei . ' – fehlt';
            } elseif ($soll !== null && @file_get_contents($voll) !== $soll) {
                $befunde[] = $datei === 'index.php'
                    ? 'web/index.php – weicht vom Shim des Releases ab'
                    : 'web/' . $datei . ' – weicht von der Vorlage des Releases ab (von Hand geändert?)';
            }
        }

        $namen = @scandir($this->webDir);
        if ($namen === false) {
            $befunde[] = 'web/ – nicht auflistbar';

            return $befunde;
        }
        $fremd = [];
        foreach ($namen as $name) {
            if ($name === '.' || $name === '..' || in_array($name, self::DOCROOT_DATEIEN, true)) {
                continue;
            }
            $voll = $this->webDir . '/' . $name;
            $fremd[] = 'web/' . $name . (!is_link($voll) && is_dir($voll) ? '/' : '') . ' – nicht Teil der Installation';
        }
        sort($fremd, SORT_STRING);

        return [...$befunde, ...$fremd];
    }

    /**
     * @return list<string>
     */
    private function configBefunde(): array
    {
        if ($this->configDatei === null) {
            return [];
        }
        $name = 'shared/config.php';
        if (is_link($this->configDatei)) {
            return [$name . ' – Verweis (Symlink) statt Datei'];
        }
        if (!is_file($this->configDatei)) {
            return [$name . ' – fehlt'];
        }

        $befunde = [];
        $rechte = @fileperms($this->configDatei);
        if ($rechte !== false && ($rechte & self::CONFIG_VERBOTENE_RECHTE) !== 0) {
            $befunde[] = sprintf(
                '%s – Rechte %04o, erwartet 0600 (nur der Besitzer darf lesen und schreiben)',
                $name,
                $rechte & 0o777,
            );
        }
        $inhalt = @file_get_contents($this->configDatei);
        if ($inhalt === false) {
            $befunde[] = $name . ' – nicht lesbar';
        } elseif (!self::nurWerte($inhalt)) {
            $befunde[] = $name . ' – enthält mehr als die Einstellungen (Code oder Ausgabe außerhalb des Werte-Arrays)';
        }

        return $befunde;
    }

    /**
     * Whether $php is exactly what ConfigWriter writes in shape:
     * `<?php return <array of literals>;` plus comments and whitespace. Any
     * call, variable, include, eval, backtick or output outside the PHP
     * block fails. Without ext-tokenizer the structure cannot be read and
     * counts as fine - the permissions are still checked.
     */
    public static function nurWerte(string $php): bool
    {
        if (!function_exists('token_get_all')) {
            return true;
        }
        $erlaubt = [
            T_ARRAY, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW,
        ];
        $zeichen = ['(', ')', '[', ']', ',', '.', '-', '+'];
        $phase = 'start';
        foreach (token_get_all($php) as $token) {
            [$art, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
            if ($art === T_WHITESPACE || $art === T_COMMENT || $art === T_DOC_COMMENT) {
                continue;
            }
            $ok = match ($phase) {
                'start' => $art === T_OPEN_TAG,
                'return' => $art === T_RETURN,
                'werte' => $text === ';'
                    || in_array($art, $erlaubt, true)
                    || ($art === null && in_array($text, $zeichen, true))
                    || ($art === T_STRING && in_array(strtolower($text), ['true', 'false', 'null'], true)),
                'ende' => $art === T_CLOSE_TAG,
                default => $art === T_INLINE_HTML && trim($text) === '',
            };
            if (!$ok) {
                return false;
            }
            $phase = match ($phase) {
                'start' => 'return',
                'return' => 'werte',
                'werte' => $text === ';' ? 'ende' : 'werte',
                default => 'nachher',
            };
        }

        return $phase === 'ende' || $phase === 'nachher';
    }

    /**
     * Depth-first walk without following symlinks: a real directory is no
     * finding of its own (its files are), a symlink is, whatever it points
     * to. Hidden files count like any other.
     *
     * @param list<string> $gefunden paths not in the list
     * @param list<string> $unlesbar directories that could not be listed, with a trailing "/"
     */
    private function durchsuchen(string $verzeichnis, string $praefix, Pruefsummenliste $liste, array &$gefunden, array &$unlesbar): void
    {
        $namen = @scandir($verzeichnis);
        if ($namen === false) {
            $unlesbar[] = $praefix === '' ? './' : $praefix;

            return;
        }
        foreach ($namen as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $voll = $verzeichnis . '/' . $name;
            $pfad = $praefix . $name;
            // The list and its signature are put into the release root by
            // setup.php and the updater - they cannot list themselves.
            if ($praefix === '' && ($name === ReleaseDownloader::LISTE || $name === ReleaseSignatur::ASSET)
                && !is_link($voll) && is_file($voll)) {
                continue;
            }
            if (!is_link($voll) && is_dir($voll)) {
                $this->durchsuchen($voll, $pfad . '/', $liste, $gefunden, $unlesbar);
            } elseif (!isset($liste->dateien[$pfad])) {
                $gefunden[] = $pfad;
            }
        }
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private static function befund(array $stand, string $art, string $pfad): array
    {
        $stand['anzahl'][$art] = (int) $stand['anzahl'][$art] + 1;
        if (count($stand['pfade'][$art]) < self::MAX_PFADE) {
            // A name that is not UTF-8 (an old FTP client) is kept with
            // U+FFFD in place of the broken bytes: still a finding, and the
            // state stays valid JSON.
            $stand['pfade'][$art][] = mb_check_encoding($pfad, 'UTF-8')
                ? $pfad
                : (string) json_decode(json_encode($pfad, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        }

        return $stand;
    }

    private function liste(): ?Pruefsummenliste
    {
        $datei = $this->listenPfad();
        if ($datei === null) {
            return null;
        }
        $inhalt = file_get_contents($datei);

        return $inhalt === false ? null : Pruefsummenliste::aus($inhalt);
    }

    /**
     * The list in the release (issue #241), else the one in shared/ an
     * installation from before kept. Never a symlink: it may point anywhere.
     */
    private function listenPfad(): ?string
    {
        foreach ([$this->releaseRoot . '/' . ReleaseDownloader::LISTE, $this->listenDatei] as $datei) {
            if (!is_link($datei) && is_file($datei)) {
                return $datei;
            }
        }

        return null;
    }

    private function installierteVersion(): ?string
    {
        $datei = $this->releaseRoot . '/VERSION';
        if (!is_file($datei)) {
            return null;
        }
        $version = trim((string) file_get_contents($datei));

        return $version === '' ? null : $version;
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function speichern(array $stand): void
    {
        $pfad = $this->statusDir . '/' . self::STATUS;
        $tmp = $pfad . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($stand, self::JSON)) === false
            || !rename($tmp, $pfad)) {
            @unlink($tmp);
            throw new \RuntimeException('Stand der Integritätsprüfung kann nicht gespeichert werden.');
        }
    }

    /**
     * @template T
     * @param \Closure(): T $arbeit
     * @return T
     */
    private function gesperrt(\Closure $arbeit): mixed
    {
        if (!is_dir($this->statusDir) && !@mkdir($this->statusDir, 0775, true) && !is_dir($this->statusDir)) {
            throw new \RuntimeException('Verzeichnis für die Integritätsprüfung kann nicht angelegt werden.');
        }
        $sperre = fopen($this->statusDir . '/' . self::SPERRE, 'c');
        if ($sperre === false) {
            throw new \RuntimeException('Verzeichnis für die Integritätsprüfung ist nicht beschreibbar.');
        }
        try {
            if (!flock($sperre, LOCK_EX | LOCK_NB)) {
                throw new IntegritaetPruefungLaeuftBereits('Ein Schritt der Integritätsprüfung läuft gerade.');
            }

            return $arbeit();
        } finally {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }
    }

    private function jetzt(): \DateTimeImmutable
    {
        return $this->clock === null ? new \DateTimeImmutable() : ($this->clock)();
    }
}
