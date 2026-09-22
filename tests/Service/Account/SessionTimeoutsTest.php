<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Repository\SettingRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The two session timeouts as settings (docs/spec/01-sicherheit.md
 * section 2). The point of the test is the fallback: an unusable value must
 * not be able to switch a timeout off.
 */
final class SessionTimeoutsTest extends DatabaseTestCase
{
    private SettingRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->settings = new SettingRepository($this->pdo());
    }

    public function testWithoutSettingsTheSpecDefaultsApply(): void
    {
        $timeouts = SessionTimeouts::fromSettings($this->settings);

        self::assertSame(1800, $timeouts->idleSeconds, '30 Minuten Leerlauf.');
        self::assertSame(43200, $timeouts->absoluteSeconds, '12 Stunden absolut.');
    }

    public function testStoredValuesWin(): void
    {
        $this->settings->set(SessionTimeouts::SETTING_IDLE, '600');
        $this->settings->set(SessionTimeouts::SETTING_ABSOLUTE, '7200');

        $timeouts = SessionTimeouts::fromSettings($this->settings);

        self::assertSame(600, $timeouts->idleSeconds);
        self::assertSame(7200, $timeouts->absoluteSeconds);
    }

    #[DataProvider('unbrauchbareWerte')]
    public function testAnUnusableValueFallsBackToTheDefault(string $wert): void
    {
        $this->settings->set(SessionTimeouts::SETTING_IDLE, $wert);

        self::assertSame(
            SessionTimeouts::IDLE_DEFAULT,
            SessionTimeouts::fromSettings($this->settings)->idleSeconds,
            sprintf('»%s« darf den Zeitablauf nicht abschalten.', $wert),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unbrauchbareWerte(): iterable
    {
        yield 'leer' => [''];
        yield 'null' => ['0'];
        yield 'negativ' => ['-1'];
        yield 'keine Zahl' => ['nie'];
        yield 'Kommazahl' => ['12.5'];
    }
}
