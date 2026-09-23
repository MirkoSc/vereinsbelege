<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Repository\SettingRepository;
use App\Service\Migration\Migrator;
use App\Service\Submission\EinreichungsEinstellungen;
use App\Service\Upload\UploadService;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The public submission's admin-configurable limits (docs/spec/
 * 01-sicherheit.md section 5, issue #25/M4-3). The point of the test is the
 * fallback, the same as App\Tests\Service\Account\SessionTimeoutsTest: an
 * unusable value must not be able to switch a limit off.
 */
final class EinreichungsEinstellungenTest extends DatabaseTestCase
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
        $einstellungen = EinreichungsEinstellungen::fromSettings($this->settings);

        self::assertFalse($einstellungen->pausiert);
        self::assertSame(10, $einstellungen->limitProIpStunde);
        self::assertSame(60, $einstellungen->limitGesamtStunde);
        self::assertSame(20, $einstellungen->maxSeiten);
        self::assertSame(10, $einstellungen->maxDateiMb);
        self::assertSame(50, $einstellungen->maxEinreichungMb);
    }

    public function testStoredValuesWin(): void
    {
        $this->settings->set(EinreichungsEinstellungen::SETTING_LIMIT_IP_STUNDE, '5');
        $this->settings->set(EinreichungsEinstellungen::SETTING_LIMIT_GESAMT_STUNDE, '30');
        $this->settings->set(EinreichungsEinstellungen::SETTING_MAX_SEITEN, '8');
        $this->settings->set(EinreichungsEinstellungen::SETTING_MAX_DATEI_MB, '4');
        $this->settings->set(EinreichungsEinstellungen::SETTING_MAX_EINREICHUNG_MB, '20');

        $einstellungen = EinreichungsEinstellungen::fromSettings($this->settings);

        self::assertSame(5, $einstellungen->limitProIpStunde);
        self::assertSame(30, $einstellungen->limitGesamtStunde);
        self::assertSame(8, $einstellungen->maxSeiten);
        self::assertSame(4, $einstellungen->maxDateiMb);
        self::assertSame(20, $einstellungen->maxEinreichungMb);
    }

    public function testThePauseFlagIsSeparateFromTheLimits(): void
    {
        EinreichungsEinstellungen::setzePausiert($this->settings, true);

        self::assertTrue(EinreichungsEinstellungen::fromSettings($this->settings)->pausiert);

        EinreichungsEinstellungen::setzePausiert($this->settings, false);

        self::assertFalse(EinreichungsEinstellungen::fromSettings($this->settings)->pausiert);
    }

    #[DataProvider('unbrauchbareWerte')]
    public function testAnUnusableValueFallsBackToTheDefault(string $wert): void
    {
        $this->settings->set(EinreichungsEinstellungen::SETTING_LIMIT_IP_STUNDE, $wert);

        self::assertSame(
            EinreichungsEinstellungen::LIMIT_IP_STUNDE_DEFAULT,
            EinreichungsEinstellungen::fromSettings($this->settings)->limitProIpStunde,
            sprintf('»%s« darf das Limit nicht abschalten.', $wert),
        );
    }

    public function testThePerFileLimitCanOnlyBeStricterThanTheUploadServiceCeiling(): void
    {
        $this->settings->set(EinreichungsEinstellungen::SETTING_MAX_DATEI_MB, '9999');

        $deckelMb = intdiv(UploadService::MAX_FILE_BYTES, 1024 * 1024);
        self::assertSame($deckelMb, EinreichungsEinstellungen::fromSettings($this->settings)->maxDateiMb);
    }

    public function testMaxSeitenIsCappedEvenWhenSetVeryHigh(): void
    {
        $this->settings->set(EinreichungsEinstellungen::SETTING_MAX_SEITEN, '99999');

        self::assertLessThanOrEqual(100, EinreichungsEinstellungen::fromSettings($this->settings)->maxSeiten);
    }

    public function testSpeichernRoundtripsThroughFromSettings(): void
    {
        $eingabe = new EinreichungsEinstellungen(
            limitProIpStunde: 3,
            limitGesamtStunde: 15,
            maxSeiten: 5,
            maxDateiMb: 2,
            maxEinreichungMb: 12,
        );
        $eingabe->speichern($this->settings);

        $gespeichert = EinreichungsEinstellungen::fromSettings($this->settings);
        self::assertSame(3, $gespeichert->limitProIpStunde);
        self::assertSame(15, $gespeichert->limitGesamtStunde);
        self::assertSame(5, $gespeichert->maxSeiten);
        self::assertSame(2, $gespeichert->maxDateiMb);
        self::assertSame(12, $gespeichert->maxEinreichungMb);
    }

    public function testMaxDateiBytesAndMaxEinreichungBytesConvertFromMb(): void
    {
        $einstellungen = new EinreichungsEinstellungen(maxDateiMb: 3, maxEinreichungMb: 7);

        self::assertSame(3 * 1024 * 1024, $einstellungen->maxDateiBytes());
        self::assertSame(7 * 1024 * 1024, $einstellungen->maxEinreichungBytes());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unbrauchbareWerte(): iterable
    {
        yield 'leer' => [''];
        yield 'null' => ['0'];
        yield 'negativ' => ['-1'];
        yield 'keine Zahl' => ['viele'];
        yield 'Kommazahl' => ['12.5'];
    }
}
