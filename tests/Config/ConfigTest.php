<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\Config;
use App\Config\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private const string SERVER_KEY = 'test-server-key-32-bytes-long!!!';

    private const string SERVER_KEY_BASE64 = 'dGVzdC1zZXJ2ZXIta2V5LTMyLWJ5dGVzLWxvbmchISE=';

    /**
     * @return array<mixed>
     */
    private static function validData(): array
    {
        return [
            'db' => [
                'host' => 'db',
                'port' => 3307,
                'name' => 'vereinsbelege',
                'user' => 'belege',
                'password' => 'secret',
            ],
            'server_key' => self::SERVER_KEY_BASE64,
            'cron_token' => 'token',
        ];
    }

    public function testFromArrayReadsAllValues(): void
    {
        $config = Config::fromArray(self::validData());

        self::assertSame('db', $config->dbHost);
        self::assertSame(3307, $config->dbPort);
        self::assertSame('vereinsbelege', $config->dbName);
        self::assertSame('belege', $config->dbUser);
        self::assertSame('secret', $config->dbPassword);
        self::assertSame('token', $config->cronToken);
        self::assertSame(self::SERVER_KEY, $config->serverKey, 'the raw key, base64 already decoded');
        self::assertFalse($config->debug);
    }

    public function testMissingServerKeyThrowsWithKeyName(): void
    {
        $data = self::validData();
        unset($data['server_key']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('server_key');

        Config::fromArray($data);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableServerKeys(): array
    {
        return [
            'too short' => [base64_encode('too short')],
            'too long' => [base64_encode(str_repeat('x', 33))],
            'not base64' => ['nope, this is not base64!'],
        ];
    }

    #[DataProvider('unusableServerKeys')]
    public function testUnusableServerKeyThrows(string $value): void
    {
        $data = self::validData();
        $data['server_key'] = $value;

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('server_key');

        Config::fromArray($data);
    }

    public function testDebugOutputHidesTheSecrets(): void
    {
        $dump = print_r(Config::fromArray(self::validData())->__debugInfo(), true);

        self::assertStringNotContainsString(self::SERVER_KEY, $dump);
        self::assertStringNotContainsString('secret', $dump, 'the database password');
    }

    public function testDbPortDefaultsTo3306(): void
    {
        $data = self::validData();
        unset($data['db']['port']);

        self::assertSame(3306, Config::fromArray($data)->dbPort);
    }

    public function testMissingDbPasswordThrowsWithKeyName(): void
    {
        $data = self::validData();
        unset($data['db']['password']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('db.password');

        Config::fromArray($data);
    }

    public function testMissingCronTokenThrowsWithKeyName(): void
    {
        $data = self::validData();
        unset($data['cron_token']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('cron_token');

        Config::fromArray($data);
    }

    public function testDsnContainsUtf8mb4Charset(): void
    {
        $dsn = Config::fromArray(self::validData())->dsn();

        self::assertSame('mysql:host=db;port=3307;dbname=vereinsbelege;charset=utf8mb4', $dsn);
    }

    public function testFromFileLoadsArrayReturningFile(): void
    {
        $config = Config::fromFile(__DIR__ . '/../fixtures/config/config.php');

        self::assertSame('fixture-host', $config->dbHost);
        self::assertTrue($config->debug);
    }

    public function testFromFileMissingThrows(): void
    {
        $this->expectException(ConfigException::class);

        Config::fromFile(__DIR__ . '/../fixtures/config/does_not_exist.php');
    }
}
