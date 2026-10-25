<?php

declare(strict_types=1);

namespace App\Worker;

use App\Service\Worker\WorkerProtokoll;

/**
 * What the worker keeps across restarts - the only thing it stores
 * permanently (docs/spec/07-worker.md section 1): its id, the access key
 * from the pairing, its key pair W_pub/W_priv and the web host's address.
 * One JSON file in the configuration volume, mode 0600. Never anything of
 * a receipt, never the AI keys (those stay in memory, KiKonfiguration).
 */
final readonly class Identitaet
{
    public const int FORMAT = 1;

    public function __construct(
        public int $workerId,
        #[\SensitiveParameter] public string $schluessel,
        #[\SensitiveParameter] public string $schluesselpaar,
        public string $basisUrl,
        public string $name,
    ) {
        if ($workerId < 1 || strlen($schluessel) !== WorkerProtokoll::SCHLUESSEL_BYTES || strlen($schluesselpaar) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
            throw new \InvalidArgumentException('Die gespeicherte Worker-Identität ist unvollständig.');
        }
    }

    public function publicKey(): string
    {
        return sodium_crypto_box_publickey($this->schluesselpaar);
    }

    public function fingerabdruck(): string
    {
        return WorkerProtokoll::fingerabdruckAnzeige(WorkerProtokoll::fingerabdruck($this->publicKey()));
    }

    public static function laden(string $datei): ?self
    {
        if (!is_file($datei)) {
            return null;
        }
        // Tighten what someone loosened by hand - the file holds W_priv.
        clearstatcache(true, $datei);
        if ((fileperms($datei) & 0o077) !== 0) {
            @chmod($datei, 0o600);
        }
        $json = file_get_contents($datei);
        $daten = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($daten) || ($daten['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException('Die Worker-Identität ist nicht lesbar – bitte neu koppeln.');
        }

        return new self(
            is_int($daten['worker_id'] ?? null) ? $daten['worker_id'] : 0,
            (string) base64_decode(is_string($daten['schluessel'] ?? null) ? $daten['schluessel'] : '', true),
            (string) base64_decode(is_string($daten['schluesselpaar'] ?? null) ? $daten['schluesselpaar'] : '', true),
            is_string($daten['basis_url'] ?? null) ? $daten['basis_url'] : '',
            is_string($daten['name'] ?? null) ? $daten['name'] : '',
        );
    }

    /** Writes the file atomically with mode 0600. */
    public function speichern(string $datei): void
    {
        $verzeichnis = dirname($datei);
        if (!is_dir($verzeichnis) && !mkdir($verzeichnis, 0o700, true) && !is_dir($verzeichnis)) {
            throw new \RuntimeException('Konfigurationsverzeichnis kann nicht angelegt werden.');
        }
        $json = json_encode([
            'format' => self::FORMAT,
            'worker_id' => $this->workerId,
            'schluessel' => base64_encode($this->schluessel),
            'schluesselpaar' => base64_encode($this->schluesselpaar),
            'basis_url' => $this->basisUrl,
            'name' => $this->name,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $tmp = $datei . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $alt = umask(0o077);
        try {
            if (file_put_contents($tmp, $json) !== strlen($json)) {
                throw new \RuntimeException('Worker-Identität konnte nicht gespeichert werden.');
            }
            chmod($tmp, 0o600);
            if (!rename($tmp, $datei)) {
                throw new \RuntimeException('Worker-Identität konnte nicht gespeichert werden.');
            }
        } finally {
            umask($alt);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            sodium_memzero($json);
        }
    }
}
