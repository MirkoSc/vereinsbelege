<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailSettings;
use App\Service\Mail\PublicUrl;
use App\Service\Mail\SmtpSecurity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The base of links in mails (issue #18/M3-5): the Host header is only
 * trusted where the club demonstrably owns the name - no host header
 * injection into a password reset mail.
 */
final class PublicUrlTest extends TestCase
{
    private static function settings(string $oeffentlicheUrl = '', string $absender = 'verein@example.org'): MailSettings
    {
        return new MailSettings(
            transport: 'smtp',
            host: 'smtp.example.org',
            port: 587,
            sicherheit: SmtpSecurity::Starttls,
            benutzer: '',
            passwort: '',
            passwortGesetzt: false,
            absender: $absender,
            antwortAn: '',
            vereinsname: '',
            oeffentlicheUrl: $oeffentlicheUrl,
        );
    }

    public function testTheConfiguredAddressWinsOverAnyHost(): void
    {
        self::assertSame(
            'https://belege.example.org',
            PublicUrl::resolve(self::settings('https://belege.example.org/'), 'evil.example.net', true),
        );
    }

    #[DataProvider('hosts')]
    public function testWithoutConfigurationOnlyTheSenderDomainCounts(string $host, bool $https, ?string $erwartet): void
    {
        self::assertSame($erwartet, PublicUrl::resolve(self::settings(), $host, $https));
    }

    /**
     * @return iterable<string, array{string, bool, string|null}>
     */
    public static function hosts(): iterable
    {
        yield 'domain itself' => ['example.org', true, 'https://example.org'];
        yield 'subdomain' => ['belege.example.org', true, 'https://belege.example.org'];
        yield 'with port' => ['belege.example.org:8443', true, 'https://belege.example.org:8443'];
        yield 'plain http keeps its scheme' => ['belege.example.org', false, 'http://belege.example.org'];
        yield 'upper case' => ['Belege.Example.ORG', true, 'https://belege.example.org'];
        yield 'foreign host' => ['evil.example.net', true, null];
        yield 'suffix without a dot' => ['evilexample.org', true, null];
        yield 'sender domain as a prefix' => ['example.org.evil.net', true, null];
        yield 'empty' => ['', true, null];
        yield 'path smuggled in' => ['example.org/evil', true, null];
        yield 'credentials smuggled in' => ['evil.net@example.org', true, null];
    }

    public function testWithoutSenderThereIsNoLink(): void
    {
        self::assertNull(PublicUrl::resolve(self::settings(absender: ''), 'example.org', true));
    }

    #[DataProvider('eingaben')]
    public function testNormalize(string $eingabe, ?string $erwartet): void
    {
        self::assertSame($erwartet, PublicUrl::normalize($eingabe));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function eingaben(): iterable
    {
        yield 'plain https' => ['https://belege.example.org', 'https://belege.example.org'];
        yield 'trailing slash' => ['https://belege.example.org/', 'https://belege.example.org'];
        yield 'port' => ['https://belege.example.org:8443', 'https://belege.example.org:8443'];
        yield 'localhost over http' => ['http://localhost:8080', 'http://localhost:8080'];
        yield 'http elsewhere' => ['http://belege.example.org', null];
        yield 'path' => ['https://belege.example.org/app', null];
        yield 'query' => ['https://belege.example.org?x=1', null];
        yield 'fragment' => ['https://belege.example.org#x', null];
        yield 'credentials' => ['https://user:pw@belege.example.org', null];
        yield 'other scheme' => ['javascript://belege.example.org', null];
        yield 'no scheme' => ['belege.example.org', null];
    }
}
