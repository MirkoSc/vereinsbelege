<?php

declare(strict_types=1);

namespace App\Installer;

use App\Service\Crypto\ServerCrypto;

/**
 * Writes shared/config.php - the last installer step: its existence is what
 * locks /install (docs/spec/06-betrieb.md section 1).
 *
 * The file lives in shared/ and therefore survives every update; nothing
 * ever overwrites it again.
 */
final class ConfigWriter
{
    /**
     * The server key (CLAUDE.md section 4, key level 1): 32 bytes for
     * libsodium's secretbox, base64 encoded so the config stays a readable
     * PHP file. Read by App\Service\Crypto\ServerCrypto.
     *
     * The installer is the one place that creates it, and it must never end
     * up in the database or in a database backup.
     */
    public const int SERVER_KEY_BYTES = ServerCrypto::KEY_BYTES;

    /**
     * @param array<string, mixed> $db host/port/name/user/password
     * @param ?string $serverKey base64 server key to keep (restore); null generates a new one
     */
    public static function write(string $configFile, array $db, ?string $serverKey = null): void
    {
        $config = [
            'debug' => false,
            'db' => [
                'host' => (string) $db['host'],
                'port' => (int) $db['port'],
                'name' => (string) $db['name'],
                'user' => (string) $db['user'],
                'password' => (string) $db['password'],
            ],
            // A restore passes the key of the backed-up installation: data
            // encrypted with it (M3: mail addresses, API keys) stays readable.
            'server_key' => $serverKey ?? base64_encode(random_bytes(self::SERVER_KEY_BYTES)),
            'cron_token' => bin2hex(random_bytes(24)),
        ];

        $dir = dirname($configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $content = "<?php\n\n"
            . "// Written by the installer. Lives in shared/ and survives updates.\n"
            . "// Contains the server key - never commit it, never put it into a\n"
            . "// database backup (CLAUDE.md section 4).\n"
            . 'return ' . var_export($config, true) . ";\n";

        if (file_put_contents($configFile, $content, LOCK_EX) === false) {
            throw new \RuntimeException('config.php kann nicht geschrieben werden: ' . $configFile);
        }

        // The config is the only file in shared/ that holds a secret, and
        // shared/ may be inside the FTP area of a shared host. Best effort:
        // some hosts ignore it, which is why the layout keeps shared/ out of
        // the docroot in the first place.
        @chmod($configFile, 0600);
    }
}
