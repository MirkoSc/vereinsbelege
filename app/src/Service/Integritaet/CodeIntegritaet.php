<?php

declare(strict_types=1);

namespace App\Service\Integritaet;

/**
 * Code integrity check (docs/spec/01-sicherheit.md section 8, issue
 * #79/M13-2): compares every file of the active release (current/) with the
 * per-file checksums of the installed release in shared/release_checksums.txt
 * and lists what differs - changed, missing, and files that are not part of
 * the release at all (a dropped-in script is the typical crude
 * manipulation).
 *
 * Not a defence against a skilled attacker - whoever can change files in
 * current/ can change the list in shared/ as well. It catches crude
 * tampering and accidental edits over FTP.
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
    public const string PHASE_FERTIG = 'fertig';

    /** The three kinds of finding, in the order the page shows them. */
    public const array ARTEN = ['geaendert', 'fehlend', 'zusaetzlich'];

    private const string STATUS = 'integritaet.json';

    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const string SPERRE = 'integritaet.lock';

    /**
     * @param string $releaseRoot current/ - the files under test
     * @param string $listenDatei shared/release_checksums.txt
     * @param string $statusDir where the chain keeps its state (shared/var)
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private string $releaseRoot,
        private string $listenDatei,
        private string $statusDir,
        private ?\Closure $clock = null,
        private int $portionDateien = self::PORTION_DATEIEN,
        private float $portionSekunden = self::PORTION_SEKUNDEN,
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
     * version than current/VERSION. After a rollback or an update stopped
     * before the switch (the updater stores the list at the download step)
     * most files then show as different. Never a reason to refuse: a
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
            'Die Prüfsummenliste gehört zu Version %s, current/VERSION nennt %s. Das passiert nach einem '
            . 'Rollback oder einem vor dem Umschalten abgebrochenen Update – dann weichen viele Dateien ab, '
            . 'bis das nächste Update vollständig durchläuft. Ohne einen solchen Grund ist die VERSION-Datei '
            . 'selbst verändert worden.',
            $liste->version,
            $installiert ?? '(keine)',
        );
    }

    private function hindernisFuer(?Pruefsummenliste $liste): ?string
    {
        if ($liste === null) {
            return 'Für diese Installation liegt keine Prüfsummenliste vor (shared/release_checksums.txt). '
                . 'Sie entsteht bei der Installation mit setup.php und bei jedem Update; '
                . 'in der Entwicklungsumgebung gibt es sie nicht.';
        }
        if ($liste->dateien === []) {
            return 'Die Prüfsummenliste des installierten Releases enthält noch keine Prüfsummen je Datei – '
                . 'die liefern erst neuere Releases mit. Nach dem nächsten Update ist die Prüfung möglich.';
        }

        return null;
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
        $stand['phase'] = self::PHASE_FERTIG;

        return $stand;
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
        if (!is_file($this->listenDatei)) {
            return null;
        }
        $inhalt = file_get_contents($this->listenDatei);

        return $inhalt === false ? null : Pruefsummenliste::aus($inhalt);
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
