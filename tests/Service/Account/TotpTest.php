<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Service\Account\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6238 (docs/spec/01-sicherheit.md, Pflicht-Tests: "TOTP
 * (RFC-6238-Testvektoren)"). The vectors are Appendix B of the RFC: three
 * seeds (one per hash algorithm), eight-digit codes, 30 s steps, T0 = 0.
 */
final class TotpTest extends TestCase
{
    private const string SEED_SHA1 = '12345678901234567890';

    private const string SEED_SHA256 = '12345678901234567890123456789012';

    private const string SEED_SHA512 = '1234567890123456789012345678901234567890123456789012345678901234';

    #[DataProvider('rfc6238Vektoren')]
    public function testDieVeroeffentlichtenTestvektorenStimmen(
        int $zeit,
        string $algorithmus,
        string $seed,
        string $erwartet,
    ): void {
        $code = Totp::code(
            $seed,
            new \DateTimeImmutable('@' . $zeit),
            digits: 8,
            algorithm: $algorithmus,
        );

        self::assertSame($erwartet, $code);
    }

    /**
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function rfc6238Vektoren(): iterable
    {
        yield '59s SHA1' => [59, 'sha1', self::SEED_SHA1, '94287082'];
        yield '59s SHA256' => [59, 'sha256', self::SEED_SHA256, '46119246'];
        yield '59s SHA512' => [59, 'sha512', self::SEED_SHA512, '90693936'];

        yield '1111111109 SHA1' => [1111111109, 'sha1', self::SEED_SHA1, '07081804'];
        yield '1111111109 SHA256' => [1111111109, 'sha256', self::SEED_SHA256, '68084774'];
        yield '1111111109 SHA512' => [1111111109, 'sha512', self::SEED_SHA512, '25091201'];

        yield '1111111111 SHA1' => [1111111111, 'sha1', self::SEED_SHA1, '14050471'];
        yield '1111111111 SHA256' => [1111111111, 'sha256', self::SEED_SHA256, '67062674'];
        yield '1111111111 SHA512' => [1111111111, 'sha512', self::SEED_SHA512, '99943326'];

        yield '1234567890 SHA1' => [1234567890, 'sha1', self::SEED_SHA1, '89005924'];
        yield '1234567890 SHA256' => [1234567890, 'sha256', self::SEED_SHA256, '91819424'];
        yield '1234567890 SHA512' => [1234567890, 'sha512', self::SEED_SHA512, '93441116'];

        yield '2000000000 SHA1' => [2000000000, 'sha1', self::SEED_SHA1, '69279037'];
        yield '2000000000 SHA256' => [2000000000, 'sha256', self::SEED_SHA256, '90698825'];
        yield '2000000000 SHA512' => [2000000000, 'sha512', self::SEED_SHA512, '38618901'];
    }

    public function testEinSechsstelligerCodeWirdErkannt(): void
    {
        $secret = Totp::generateSecret();
        $jetzt = new \DateTimeImmutable('@1700000000');

        $code = Totp::code($secret, $jetzt);

        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertTrue(Totp::verify($secret, $code, $jetzt));
    }

    public function testEinFalscherCodeWirdAbgelehnt(): void
    {
        $secret = Totp::generateSecret();
        $jetzt = new \DateTimeImmutable('@1700000000');
        $richtig = Totp::code($secret, $jetzt);

        // Guaranteed to differ, wraps around within the 6-digit space.
        $falsch = str_pad((string) ((((int) $richtig) + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);

        self::assertFalse(Totp::verify($secret, $falsch, $jetzt));
    }

    public function testEinCodeAusDemNaechstenZeitfensterWirdInnerhalbDerToleranzAkzeptiert(): void
    {
        $secret = Totp::generateSecret();
        $jetzt = new \DateTimeImmutable('@1700000000');
        $naechsterSchritt = $jetzt->modify('+' . Totp::DEFAULT_PERIOD . ' seconds');

        $code = Totp::code($secret, $naechsterSchritt);

        self::assertTrue(Totp::verify($secret, $code, $jetzt, window: 1));
    }

    public function testEinCodeWeitAusserhalbDesFensterWirdAbgelehnt(): void
    {
        $secret = Totp::generateSecret();
        $jetzt = new \DateTimeImmutable('@1700000000');
        $weitWeg = $jetzt->modify('+10 minutes');

        $code = Totp::code($secret, $weitWeg);

        self::assertFalse(Totp::verify($secret, $code, $jetzt, window: 1));
    }

    public function testEineNichtNumerischeEingabeWirdAbgelehnt(): void
    {
        self::assertFalse(Totp::verify(Totp::generateSecret(), 'abcdef'));
        self::assertFalse(Totp::verify(Totp::generateSecret(), ''));
        self::assertFalse(Totp::verify(Totp::generateSecret(), '12345'));
    }

    public function testBase32HinUndZurueckErgibtDasOriginal(): void
    {
        $secret = Totp::generateSecret();

        self::assertSame($secret, Totp::base32Decode(Totp::base32Encode($secret)));
    }

    public function testBase32IstGrossschreibungUndPaddingKonform(): void
    {
        // 20 bytes -> 32 base32 characters, no padding needed (160 bits / 5 = 32).
        $encoded = Totp::base32Encode(str_repeat("\x00", Totp::SECRET_BYTES));

        self::assertMatchesRegularExpression('/^[A-Z2-7]+=*$/', $encoded);
        self::assertSame(32, strlen($encoded));
    }

    public function testDieOtpauthUriEnthaeltAusstellerUndKonto(): void
    {
        $uri = Totp::uri(Totp::generateSecret(), 'kasse@example.org', 'Vereinsbelege');

        self::assertStringStartsWith('otpauth://totp/Vereinsbelege:kasse%40example.org?', $uri);
        self::assertStringContainsString('secret=', $uri);
    }
}
