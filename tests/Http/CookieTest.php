<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Service\Account\SessionVault;
use PHPUnit\Framework\TestCase;

/**
 * The cookie that carries the vault session key
 * (docs/spec/01-sicherheit.md section 2).
 *
 * What is checked here is the part a browser enforces and no PHP test can:
 * the `__Host-` prefix only holds if Secure and Path=/ are there and Domain
 * is not - get that wrong and the browser silently drops the cookie, which
 * would look like "the login does not work" and not like a bug in a header.
 */
final class CookieTest extends TestCase
{
    public function testTheVaultCookieOverHttpsCarriesTheHostPrefixAndItsAttributes(): void
    {
        $header = Cookie::vaultKey('abc123', secure: true)->header();

        self::assertStringStartsWith(SessionVault::COOKIE . '=abc123;', $header);
        self::assertStringContainsString('Path=/', $header);
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Strict', $header);
        self::assertStringNotContainsString('Domain=', $header, '__Host- verträgt kein Domain-Attribut.');
        self::assertStringNotContainsString('Max-Age', $header, 'Sitzungs-Cookie: keine eigene Lebensdauer.');
    }

    /**
     * The development branch: over plain HTTP the prefix cannot be honoured,
     * so the name has to give way rather than the whole login.
     */
    public function testWithoutHttpsTheCookieDropsThePrefixAndTheSecureFlag(): void
    {
        $header = Cookie::vaultKey('abc123', secure: false)->header();

        self::assertStringStartsWith(Cookie::INSECURE_NAME . '=abc123;', $header);
        self::assertStringNotContainsString('Secure', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Strict', $header);
    }

    public function testTheExpiredCookieKeepsNameAndAttributesButClearsTheValue(): void
    {
        $header = Cookie::vaultKey('abc123', secure: true)->expired()->header();

        self::assertStringStartsWith(SessionVault::COOKIE . '=;', $header);
        self::assertStringContainsString('Max-Age=0', $header);
        self::assertStringContainsString('Expires=', $header);
        self::assertStringContainsString('Path=/', $header);
        self::assertStringContainsString('Secure', $header);
    }

    /**
     * Reading does not need to know the scheme: only one of the two names
     * can ever be present, because a browser sets `__Host-` over HTTPS only.
     */
    public function testTheVaultKeyIsReadUnderEitherName(): void
    {
        self::assertSame('aus-https', Cookie::vaultKeyFrom(self::request([SessionVault::COOKIE => 'aus-https'])));
        self::assertSame('aus-http', Cookie::vaultKeyFrom(self::request([Cookie::INSECURE_NAME => 'aus-http'])));
        self::assertNull(Cookie::vaultKeyFrom(self::request([])));
    }

    public function testTheHostNameWinsWhenBothArePresent(): void
    {
        $request = self::request([Cookie::INSECURE_NAME => 'unsicher', SessionVault::COOKIE => 'sicher']);

        self::assertSame('sicher', Cookie::vaultKeyFrom($request));
    }

    public function testAResponseCarriesTheCookieAsASetCookieHeader(): void
    {
        $antwort = Response::redirect('/app')->withCookie(Cookie::vaultKey('abc123', secure: true));

        self::assertSame('/app', $antwort->headers['Location']);
        self::assertSame(Cookie::vaultKey('abc123', secure: true)->header(), $antwort->headers['Set-Cookie']);
    }

    /** A value a client controls must never be able to forge a header. */
    public function testAValueWithASeparatorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cookie('vk', "abc; Domain=example.org", secure: true);
    }

    /**
     * @param array<string, string> $cookies
     */
    private static function request(array $cookies): Request
    {
        return new Request(HttpMethod::Get, '/app', cookies: $cookies);
    }
}
