<?php

declare(strict_types=1);

namespace App\Service\Suche;

/**
 * Where a session's full-text index lies while the session lasts (issue
 * #94): `shared/var/tmp/suche/<index id>/<teil>.bin`. Only ciphertext
 * reaches this directory - each part is encrypted under the index key that
 * exists nowhere but sealed in the PHP session (App\Service\Suche\
 * SitzungsSuchindex), so the files alone, a backup of them or the server's
 * session files alone open nothing (CLAUDE.md section 2: var/tmp holds
 * only ciphertext and is cleaned up).
 *
 * The index is a cache: a missing or broken part is rebuilt, never
 * repaired. Logout removes the directory; aufraeumen() catches every
 * session that simply ran out (App\Service\Cron\SuchindexCleanupTask) - by
 * file times only, no database, no key.
 */
final readonly class SuchindexSpeicher
{
    private const string ID_PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(private string $verzeichnis)
    {
    }

    public static function neueId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function schreibe(string $id, int $teil, string $chiffrat): void
    {
        $ordner = $this->ordner($id);
        if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
            throw new \RuntimeException('Suchindex-Verzeichnis lässt sich nicht anlegen.');
        }

        // Write next to the target, then rename: a reader never sees half a
        // part, and a step cut off halfway leaves no broken file behind.
        $ziel = $this->datei($id, $teil);
        $temp = $ziel . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $chiffrat, LOCK_EX) !== strlen($chiffrat)) {
            @unlink($temp);
            throw new \RuntimeException('Suchindex-Teil lässt sich nicht schreiben.');
        }
        @chmod($temp, 0600);
        if (!rename($temp, $ziel)) {
            @unlink($temp);
            throw new \RuntimeException('Suchindex-Teil lässt sich nicht ablegen.');
        }
    }

    /** null when the part is gone (cleaned up, never written). */
    public function lies(string $id, int $teil): ?string
    {
        $datei = $this->datei($id, $teil);
        if (!is_file($datei)) {
            return null;
        }
        $inhalt = file_get_contents($datei);

        return $inhalt === false ? null : $inhalt;
    }

    public function loesche(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return;
        }
        self::entferne($this->verzeichnis . '/' . $id);
    }

    /**
     * Removes every index whose newest file is older than $aelterAls.
     * Returns how many it removed.
     */
    public function aufraeumen(\DateTimeImmutable $aelterAls): int
    {
        if (!is_dir($this->verzeichnis)) {
            return 0;
        }
        $eintraege = scandir($this->verzeichnis);
        if ($eintraege === false) {
            return 0;
        }

        clearstatcache();
        $grenze = $aelterAls->getTimestamp();
        $entfernt = 0;
        foreach ($eintraege as $eintrag) {
            $ordner = $this->verzeichnis . '/' . $eintrag;
            if (preg_match(self::ID_PATTERN, $eintrag) !== 1 || !is_dir($ordner) || self::neuesteAenderung($ordner) >= $grenze) {
                continue;
            }
            self::entferne($ordner);
            $entfernt++;
        }

        return $entfernt;
    }

    private function ordner(string $id): string
    {
        // The id goes into a path: 32 hex characters and nothing else.
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('Ungültige Suchindex-Kennung.');
        }

        return $this->verzeichnis . '/' . $id;
    }

    private function datei(string $id, int $teil): string
    {
        if ($teil < 1) {
            throw new \InvalidArgumentException('Suchindex-Teile zählen ab 1.');
        }

        return $this->ordner($id) . '/' . $teil . '.bin';
    }

    private static function neuesteAenderung(string $ordner): int
    {
        $neueste = (int) filemtime($ordner);
        foreach (scandir($ordner) ?: [] as $datei) {
            if ($datei !== '.' && $datei !== '..') {
                $neueste = max($neueste, (int) @filemtime($ordner . '/' . $datei));
            }
        }

        return $neueste;
    }

    private static function entferne(string $ordner): void
    {
        if (!is_dir($ordner)) {
            return;
        }
        foreach (scandir($ordner) ?: [] as $datei) {
            if ($datei !== '.' && $datei !== '..') {
                @unlink($ordner . '/' . $datei);
            }
        }
        @rmdir($ordner);
    }
}
