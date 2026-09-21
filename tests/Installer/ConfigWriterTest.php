<?php

declare(strict_types=1);

namespace App\Tests\Installer;

use App\Config\Config;
use App\Installer\ConfigWriter;
use PHPUnit\Framework\TestCase;

final class ConfigWriterTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/vb_config_' . uniqid('', true) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @return array<mixed>
     */
    private function written(): array
    {
        /** @var array<mixed> $data */
        $data = require $this->file;

        return $data;
    }

    private function write(string $password = 'geheim'): void
    {
        ConfigWriter::write($this->file, [
            'host' => 'db.example',
            'port' => 3307,
            'name' => 'verein',
            'user' => 'belege',
            'password' => $password,
        ]);
    }

    public function testWrittenConfigLoadsThroughTheConfigClass(): void
    {
        $this->write("ge'heim\\x");

        $config = Config::fromFile($this->file);

        self::assertSame('db.example', $config->dbHost);
        self::assertSame(3307, $config->dbPort);
        self::assertSame('verein', $config->dbName);
        self::assertSame('belege', $config->dbUser);
        self::assertSame("ge'heim\\x", $config->dbPassword, 'special characters survive var_export');
        self::assertFalse($config->debug, 'a fresh installation never starts in debug mode');
        self::assertSame(48, strlen($config->cronToken), 'random cron token is generated');
    }

    /**
     * The server key (CLAUDE.md section 4, key level 1) is created by the
     * installer although the crypto service that uses it only arrives with
     * M2-1: this is the one moment it can be created, and an installation
     * set up today must not need re-keying later.
     */
    public function testAServerKeyIsGenerated(): void
    {
        $this->write();

        $key = $this->written()['server_key'] ?? null;

        self::assertIsString($key);
        $raw = base64_decode($key, true);
        self::assertIsString($raw);
        self::assertSame(ConfigWriter::SERVER_KEY_BYTES, strlen($raw), '32 bytes for libsodium secretbox');
    }

    public function testEveryInstallationGetsItsOwnSecrets(): void
    {
        $this->write();
        $erste = $this->written();

        unlink($this->file);
        $this->write();
        $zweite = $this->written();

        self::assertNotSame($erste['server_key'], $zweite['server_key']);
        self::assertNotSame($erste['cron_token'], $zweite['cron_token']);
    }

    public function testTheConfigDirectoryIsCreatedIfMissing(): void
    {
        $dir = sys_get_temp_dir() . '/vb_config_dir_' . uniqid('', true);
        $this->file = $dir . '/config.php';

        $this->write();

        self::assertFileExists($this->file);

        unlink($this->file);
        rmdir($dir);
        $this->file = '';
    }
}
