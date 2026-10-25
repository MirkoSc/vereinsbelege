<?php

declare(strict_types=1);

namespace App\Worker\Mail;

/**
 * The attempt counter of the mail intake for a mailbox that allows no own
 * keywords (review of #243): there the mailbox has no place for it, so it
 * lives in a small file next to the identity (volume `/daten`) and
 * survives a killed worker - a mail that makes the worker die is set aside
 * after three restarts instead of being fetched after every one.
 *
 * Holds no mail content: entries are keyed by an HMAC of
 * "folder|UIDVALIDITY|UID" under a key derived from the worker's secret,
 * with the count and the time of the last attempt. Old entries (30 days)
 * and a surplus beyond MAX_EINTRAEGE are dropped. Written atomically with
 * mode 0600; a file that cannot be read counts as empty, one that cannot
 * be written leaves the count in memory (as before #243).
 *
 * Without a file (tests, `null`) it counts in memory only.
 */
final class VersuchsSpeicher
{
    public const int FORMAT = 1;

    private const int MAX_EINTRAEGE = 500;

    private const int MAX_ALTER_SEKUNDEN = 30 * 86400;

    /** @var array<string, array{0: int, 1: int}> hmac => [attempts, time] */
    private array $eintraege = [];

    private bool $geladen = false;

    /**
     * @param \Closure(): int $uhr
     */
    public function __construct(
        private readonly ?string $datei,
        #[\SensitiveParameter] private readonly string $schluessel,
        private readonly \Closure $uhr,
    ) {
    }

    public function anzahl(string $kennung): int
    {
        $this->laden();

        return $this->eintraege[$this->hmac($kennung)][0] ?? 0;
    }

    /** Stores the count; 0 removes the entry. Written before the mail is fetched. */
    public function setzen(string $kennung, int $anzahl): void
    {
        $this->laden();
        $hmac = $this->hmac($kennung);
        if ($anzahl <= 0) {
            if (!isset($this->eintraege[$hmac])) {
                return;
            }
            unset($this->eintraege[$hmac]);
        } else {
            $this->eintraege[$hmac] = [$anzahl, ($this->uhr)()];
        }
        $this->schreiben();
    }

    /** Access gone: nothing of the old mailbox is kept. */
    public function leeren(): void
    {
        $this->laden();
        if ($this->eintraege === []) {
            return;
        }
        $this->eintraege = [];
        $this->schreiben();
    }

    private function hmac(string $kennung): string
    {
        return hash_hmac('sha256', $kennung, $this->schluessel);
    }

    private function laden(): void
    {
        if ($this->geladen) {
            return;
        }
        $this->geladen = true;
        if ($this->datei === null || !is_file($this->datei)) {
            return;
        }
        $json = @file_get_contents($this->datei);
        $daten = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($daten) || ($daten['format'] ?? null) !== self::FORMAT || !is_array($daten['eintraege'] ?? null)) {
            return;
        }
        foreach ($daten['eintraege'] as $hmac => $eintrag) {
            if (is_string($hmac) && preg_match('/^[0-9a-f]{64}$/', $hmac) === 1
                && is_array($eintrag) && is_int($eintrag[0] ?? null) && is_int($eintrag[1] ?? null)) {
                $this->eintraege[$hmac] = [$eintrag[0], $eintrag[1]];
            }
        }
    }

    private function schreiben(): void
    {
        $grenze = ($this->uhr)() - self::MAX_ALTER_SEKUNDEN;
        $this->eintraege = array_filter($this->eintraege, static fn(array $e): bool => $e[1] >= $grenze);
        if (count($this->eintraege) > self::MAX_EINTRAEGE) {
            uasort($this->eintraege, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
            $this->eintraege = array_slice($this->eintraege, 0, self::MAX_EINTRAEGE, true);
        }
        if ($this->datei === null) {
            return;
        }
        $json = json_encode(['format' => self::FORMAT, 'eintraege' => $this->eintraege], JSON_THROW_ON_ERROR);
        $tmp = $this->datei . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $alt = umask(0o077);
        try {
            if (@file_put_contents($tmp, $json) === strlen($json)) {
                @chmod($tmp, 0o600);
                @rename($tmp, $this->datei);
            }
        } finally {
            umask($alt);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
