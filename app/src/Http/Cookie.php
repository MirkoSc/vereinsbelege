<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Account\SessionVault;

/**
 * One `Set-Cookie` header, as a value.
 *
 * Not `setcookie()`: a cookie that belongs to a response should travel with
 * that response (App\Http\Response::withCookie()) instead of being written
 * into the output buffer from somewhere in the middle of a controller - and
 * a value object is the only form of this that a test can read back without
 * sending headers.
 *
 * The `__Host-` prefix is the point of the class. A browser accepts such a
 * cookie only with Secure, Path=/ and no Domain, and in exchange no other
 * host - not a sibling subdomain, not a same-name host on plain HTTP - can
 * set or overwrite it. That is exactly the guarantee the vault key needs
 * (docs/spec/01-sicherheit.md section 2), so vaultKey() builds it and
 * nothing else has to remember the rules.
 */
final readonly class Cookie
{
    /**
     * The vault key's name where `__Host-` cannot be used - see
     * vaultKey(). Development only.
     */
    public const string INSECURE_NAME = 'vk';

    /**
     * @param int|null $maxAge null = a session cookie, which is what the
     *                         vault key wants: it must not outlive the
     *                         browser session it belongs to. 0 deletes.
     */
    public function __construct(
        public string $name,
        public string $value,
        public bool $secure,
        public ?int $maxAge = null,
        public string $sameSite = 'Lax',
        public string $path = '/',
        public bool $httpOnly = true,
    ) {
        if (!preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $this->name)) {
            throw new \InvalidArgumentException('Invalid cookie name.');
        }
        if (!preg_match('/^[A-Za-z0-9!#$%&\'()*+\-.\/:<=>?@\[\]^_`{|}~]*$/', $this->value)) {
            throw new \InvalidArgumentException('Invalid cookie value.');
        }
    }

    /**
     * The session key K_s of an unlocked vault
     * (App\Service\Account\SessionVault).
     *
     * Over HTTPS this is `__Host-vk` with everything the prefix demands.
     * Over plain HTTP the prefix would make the browser drop the cookie
     * outright, and with it the whole login - so there the name is plain
     * `vk`, with the same attributes minus the one that cannot hold.
     * Production runs on HTTPS (installation requirement), so that branch is
     * the docker development setup on http://localhost:8080 and nothing
     * else; Session::start() derives its `secure` flag the same way and for
     * the same reason.
     */
    public static function vaultKey(#[\SensitiveParameter] string $value, bool $secure): self
    {
        return new self(
            name: $secure ? SessionVault::COOKIE : self::INSECURE_NAME,
            value: $value,
            secure: $secure,
            // Strict, not Lax: this cookie is never wanted on a request that
            // some other site started. A link from outside lands on a page
            // whose vault is locked, and one same-site navigation later it
            // is back - a receipt is never decrypted because a foreign page
            // asked for it.
            sameSite: 'Strict',
        );
    }

    /**
     * The vault key a request carries, whichever of the two names it uses.
     *
     * Reading both is safe and saves the reader from having to know the
     * scheme: a browser only ever sets `__Host-vk` over HTTPS, and `vk` is
     * only ever sent where this application wrote it. Writing does need the
     * scheme - see vaultKey().
     */
    public static function vaultKeyFrom(Request $request): ?string
    {
        return $request->cookie(SessionVault::COOKIE) ?? $request->cookie(self::INSECURE_NAME);
    }

    /**
     * The same cookie, emptied and expired - what logging out sends.
     * Name and attributes have to match the original, or the browser keeps
     * the cookie it already has next to the new one.
     */
    public function expired(): self
    {
        return new self(
            name: $this->name,
            value: '',
            secure: $this->secure,
            maxAge: 0,
            sameSite: $this->sameSite,
            path: $this->path,
            httpOnly: $this->httpOnly,
        );
    }

    public function header(): string
    {
        $teile = [$this->name . '=' . $this->value, 'Path=' . $this->path];

        if ($this->maxAge !== null) {
            $teile[] = 'Max-Age=' . $this->maxAge;
            // Expires next to Max-Age: a cookie that is being deleted has to
            // go away in the browsers that ignore Max-Age as well.
            $teile[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', time() + $this->maxAge);
        }
        if ($this->httpOnly) {
            $teile[] = 'HttpOnly';
        }
        if ($this->secure) {
            $teile[] = 'Secure';
        }
        $teile[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $teile);
    }
}
