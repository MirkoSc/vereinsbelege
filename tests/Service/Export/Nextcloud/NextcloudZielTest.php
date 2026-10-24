<?php

declare(strict_types=1);

namespace App\Tests\Service\Export\Nextcloud;

use App\Service\Export\Nextcloud\NextcloudZiel;
use App\Service\Export\Nextcloud\NextcloudZielFehler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NextcloudZielTest extends TestCase
{
    public function testAValidTargetIsTrimmedAndKeepsItsValues(): void
    {
        $ziel = NextcloudZiel::pruefen('  https://cloud.example.org/remote.php/dav/files/kasse/Belege/ ', ' kasse ', 'a b-c');

        self::assertSame('https://cloud.example.org/remote.php/dav/files/kasse/Belege', $ziel->url);
        self::assertSame('kasse', $ziel->benutzer);
        self::assertSame('a b-c', $ziel->passwort, 'the password is taken as typed');
        self::assertSame('cloud.example.org', $ziel->host());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function ungueltig(): iterable
    {
        yield 'empty address' => ['', 'kasse', 'pw'];
        yield 'plain http' => ['http://cloud.example.org/dav', 'kasse', 'pw'];
        yield 'another scheme' => ['ftp://cloud.example.org/dav', 'kasse', 'pw'];
        yield 'no host' => ['https:///dav', 'kasse', 'pw'];
        yield 'credentials in the address' => ['https://kasse:pw@cloud.example.org/dav', 'kasse', 'pw'];
        yield 'query' => ['https://cloud.example.org/dav?a=1', 'kasse', 'pw'];
        yield 'fragment' => ['https://cloud.example.org/dav#x', 'kasse', 'pw'];
        yield 'space in the address' => ['https://cloud.example.org/my dav', 'kasse', 'pw'];
        yield 'too long' => ['https://cloud.example.org/' . str_repeat('a', NextcloudZiel::URL_MAX), 'kasse', 'pw'];
        yield 'no user' => ['https://cloud.example.org/dav', ' ', 'pw'];
        yield 'colon in the user' => ['https://cloud.example.org/dav', 'kas:se', 'pw'];
        yield 'no password' => ['https://cloud.example.org/dav', 'kasse', ''];
        yield 'line break in the password' => ['https://cloud.example.org/dav', 'kasse', "pw\r\nX-Evil: 1"];
    }

    #[DataProvider('ungueltig')]
    public function testInvalidTargetsAreRefused(string $url, string $benutzer, string $passwort): void
    {
        $this->expectException(NextcloudZielFehler::class);
        NextcloudZiel::pruefen($url, $benutzer, $passwort);
    }

    public function testEverySegmentIsEncodedOnItsOwn(): void
    {
        $ziel = NextcloudZiel::pruefen('https://cloud.example.org/dav/Belege', 'kasse', 'pw');

        self::assertSame('https://cloud.example.org/dav/Belege/', $ziel->adresse(''));
        self::assertSame(
            'https://cloud.example.org/dav/Belege/Belege_2026/M%C3%BCller%20%26%20S%C3%B6hne/05.%20Mai/a%3Fb%23c%20%282%29.pdf',
            $ziel->adresse('Belege_2026/Müller & Söhne/05. Mai/a?b#c (2).pdf'),
        );
    }

    public function testThePasswordStaysOutOfDumps(): void
    {
        $ziel = NextcloudZiel::pruefen('https://cloud.example.org/dav', 'kasse', 'sehr-geheim');

        self::assertStringNotContainsString('sehr-geheim', print_r($ziel, true));
        self::assertStringNotContainsString('sehr-geheim', var_export($ziel->__debugInfo(), true));
    }
}
