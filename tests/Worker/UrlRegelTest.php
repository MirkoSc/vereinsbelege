<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Worker\Http\UrlRegel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/spec/07-worker.md section 5 (review of #52/#53): the worker talks
 * https:// only - plain http:// only to localhost and only with the
 * explicit test option.
 */
final class UrlRegelTest extends TestCase
{
    /** @return iterable<string, array{string, bool, bool}> */
    public static function adressen(): iterable
    {
        yield 'https' => ['https://belege.verein.de', false, true];
        yield 'https mit Pfad und Port' => ['https://verein.de:8443/belege', false, true];
        yield 'http' => ['http://belege.verein.de', false, false];
        yield 'http trotz Testoption' => ['http://belege.verein.de', true, false];
        yield 'http localhost ohne Option' => ['http://localhost:8080', false, false];
        yield 'http localhost mit Option' => ['http://localhost:8080', true, true];
        yield 'http 127.0.0.1 mit Option' => ['http://127.0.0.1', true, true];
        yield 'http ::1 mit Option' => ['http://[::1]:8080', true, true];
        yield 'localhost als Teil eines Namens' => ['http://localhost.example.org', true, false];
        yield 'Zugangsdaten in der Adresse' => ['https://a:b@belege.verein.de', false, false];
        yield 'ftp' => ['ftp://belege.verein.de', true, false];
        yield 'kein Schema' => ['belege.verein.de', false, false];
    }

    #[DataProvider('adressen')]
    public function testOnlyHttpsAndLocalHttpForTests(string $url, bool $testoption, bool $erlaubt): void
    {
        self::assertSame($erlaubt, UrlRegel::erlaubt($url, $testoption));
    }

    public function testTheBaseAddressIsNormalised(): void
    {
        self::assertSame('https://verein.de/belege', UrlRegel::basis(' HTTPS://verein.de/belege/ '));
        self::assertSame('https://verein.de:8443', UrlRegel::basis('https://verein.de:8443/'));
    }

    public function testAPlainHttpBaseAddressIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('https://');

        UrlRegel::basis('http://belege.verein.de');
    }

    public function testABaseAddressWithQueryIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        UrlRegel::basis('https://belege.verein.de/?x=1');
    }
}
