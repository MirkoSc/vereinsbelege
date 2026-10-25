<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Where the worker's tools get their input and leave their output: the only
 * place plaintext of a receipt ever touches a file (docs/spec/07-worker.md
 * section 3: "ausschließlich in einem tmpfs (RAM) ... kein Klartext auf der
 * SD-Karte").
 *
 *   - The base directory must be on tmpfs/ramfs - checked against the
 *     kernel's mount table at start; anything else is refused, there is no
 *     switch to allow a disk.
 *   - Every job gets its own directory (0700) below it, removed in a
 *     `finally` when the job ends, whatever way it ends.
 *   - At start, leftovers of a worker that was killed (SIGKILL, power cut)
 *     are removed - on tmpfs a power cut has removed them anyway.
 */
final readonly class Arbeitsverzeichnis
{
    /** File systems that live in memory only. */
    private const array RAM_DATEISYSTEME = ['tmpfs', 'ramfs'];

    private const string PRAEFIX = 'job-';

    private string $basis;

    /**
     * @param string $mountTabelle the content of /proc/self/mounts (tests pass their own)
     *
     * @throws \RuntimeException when the directory is not in memory
     */
    public function __construct(string $basis, string $mountTabelle)
    {
        if (!is_dir($basis) && !@mkdir($basis, 0700, true) && !is_dir($basis)) {
            throw new \RuntimeException('Arbeitsverzeichnis kann nicht angelegt werden.');
        }
        $echt = realpath($basis);
        if ($echt === false) {
            throw new \RuntimeException('Arbeitsverzeichnis nicht gefunden.');
        }
        $typ = self::dateisystem($echt, $mountTabelle);
        if (!in_array($typ, self::RAM_DATEISYSTEME, true)) {
            throw new \RuntimeException(sprintf(
                'Das Arbeitsverzeichnis %s liegt nicht im Arbeitsspeicher (Dateisystem: %s). Bitte ein tmpfs einhängen (docker compose: tmpfs).',
                $echt,
                $typ ?? 'unbekannt',
            ));
        }
        $this->basis = $echt;
    }

    public static function ausSystem(string $basis): self
    {
        $tabelle = @file_get_contents('/proc/self/mounts');

        return new self($basis, is_string($tabelle) ? $tabelle : '');
    }

    public function basis(): string
    {
        return $this->basis;
    }

    /** Removes job directories a killed worker left behind. Returns how many. */
    public function aufraeumen(): int
    {
        $anzahl = 0;
        foreach (scandir($this->basis) ?: [] as $eintrag) {
            if (str_starts_with($eintrag, self::PRAEFIX)) {
                self::entfernen($this->basis . '/' . $eintrag);
                ++$anzahl;
            }
        }

        return $anzahl;
    }

    /**
     * Runs $arbeit with a fresh, private directory and removes it afterwards.
     *
     * @template T
     *
     * @param \Closure(string): T $arbeit
     *
     * @return T
     */
    public function mitJob(\Closure $arbeit): mixed
    {
        $verzeichnis = $this->basis . '/' . self::PRAEFIX . bin2hex(random_bytes(8));
        if (!mkdir($verzeichnis, 0700)) {
            throw new \RuntimeException('Job-Verzeichnis kann nicht angelegt werden.');
        }
        try {
            return $arbeit($verzeichnis);
        } finally {
            self::entfernen($verzeichnis);
        }
    }

    /**
     * The file system type the longest matching mount point of $pfad has,
     * from a /proc/self/mounts table ("device mountpoint type options 0 0",
     * spaces in names escaped as \040).
     */
    public static function dateisystem(string $pfad, string $mountTabelle): ?string
    {
        $bester = null;
        $laenge = -1;
        foreach (explode("\n", $mountTabelle) as $zeile) {
            $teile = preg_split('/\s+/', trim($zeile));
            if ($teile === false || count($teile) < 3) {
                continue;
            }
            $punkt = stripcslashes($teile[1]);
            $passt = $punkt === '/' || $pfad === $punkt || str_starts_with($pfad, rtrim($punkt, '/') . '/');
            // ">=": a later mount over the same point hides the earlier one.
            if ($passt && strlen($punkt) >= $laenge) {
                $bester = $teile[2];
                $laenge = strlen($punkt);
            }
        }

        return $bester;
    }

    private static function entfernen(string $pfad): void
    {
        if (is_dir($pfad) && !is_link($pfad)) {
            foreach (scandir($pfad) ?: [] as $eintrag) {
                if ($eintrag !== '.' && $eintrag !== '..') {
                    self::entfernen($pfad . '/' . $eintrag);
                }
            }
            @rmdir($pfad);
        } elseif (file_exists($pfad) || is_link($pfad)) {
            @unlink($pfad);
        }
    }
}
