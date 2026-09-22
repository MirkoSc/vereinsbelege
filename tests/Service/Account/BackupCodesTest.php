<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Service\Account\BackupCodes;
use PHPUnit\Framework\TestCase;

/**
 * Backup codes (docs/spec/01-sicherheit.md section 3: "10 Einmal-Backup-Codes
 * (gehasht) bei Einrichtung"). What is actually hashed and marked used lives
 * in App\Service\Account\MfaService; this only covers generation and
 * normalisation.
 */
final class BackupCodesTest extends TestCase
{
    public function testGenerateProduziertZehnCodes(): void
    {
        $codes = BackupCodes::generate();

        self::assertCount(10, $codes);
    }

    public function testAlleCodesSindEindeutig(): void
    {
        $codes = BackupCodes::generate();

        self::assertCount(10, array_unique($codes));
    }

    public function testJederCodeHatDasErwarteteFormat(): void
    {
        foreach (BackupCodes::generate() as $code) {
            self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/', $code);
        }
    }

    public function testNormalizeIgnoriertGrossKleinschreibungUndTrennzeichen(): void
    {
        self::assertSame('ABCDE12345', BackupCodes::normalize('abcde-12345'));
        self::assertSame('ABCDE12345', BackupCodes::normalize('AbCdE 12345'));
        self::assertSame('ABCDE12345', BackupCodes::normalize('  abcde12345  '));
    }

    public function testNormalizeLoestCrockfordVerwechslungenAuf(): void
    {
        // o/O -> 0, i/I/l/L -> 1, wie bei App\Service\Crypto\RecoveryKey.
        self::assertSame('011011AB', BackupCodes::normalize('oIlOIlAB'));
    }

    public function testGenerierteCodesEnthaltenKeineVerwechselbarenZeichen(): void
    {
        foreach (BackupCodes::generate() as $code) {
            self::assertStringNotContainsString('I', $code);
            self::assertStringNotContainsString('L', $code);
            self::assertStringNotContainsString('O', $code);
            self::assertStringNotContainsString('U', $code);
        }
    }
}
