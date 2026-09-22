<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Response implements ResponseInterface
{
    /**
     * @param array<string, string> $headers
     * @param list<Cookie> $additionalCookies a second, third, ... cookie
     *        beyond the one $headers['Set-Cookie'] can hold - see
     *        withCookie(). Empty for every response that sets at most one,
     *        which is still almost all of them.
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
        public array $additionalCookies = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, ['Location' => $location]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * The same response plus one more `Set-Cookie`. Almost every response
     * that sets a cookie sets exactly one (the vault key, App\Http\Cookie),
     * so the first call still goes into the simple name => value header map
     * everything else (App\Http\CookieTest, the login flow tests) already
     * reads it from. Since M3-4 (issue #17) a login that also remembers the
     * device needs a second, independent cookie in the same response
     * (`__Host-vk` and `__Host-td`) - a further call appends to
     * $additionalCookies instead of overwriting the first.
     */
    public function withCookie(Cookie $cookie): self
    {
        if (!isset($this->headers['Set-Cookie'])) {
            return new self(
                $this->status,
                [...$this->headers, 'Set-Cookie' => $cookie->header()],
                $this->body,
                $this->additionalCookies,
            );
        }

        return new self($this->status, $this->headers, $this->body, [...$this->additionalCookies, $cookie]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            // A Set-Cookie must never replace: PHP's header() drops every
            // same-named header by default, and that includes the session
            // cookie session_regenerate_id() queued in Session::login() -
            // the browser would keep the old, anonymous session id and the
            // login would not hold (issue #127).
            header($name . ': ' . $value, strcasecmp($name, 'Set-Cookie') !== 0);
        }
        foreach ($this->additionalCookies as $cookie) {
            // false = add a further header line instead of replacing the one
            // the loop above (or an earlier iteration of this one) already
            // sent - PHP's header() replaces same-named headers by default.
            header('Set-Cookie: ' . $cookie->header(), false);
        }
        echo $this->body;
    }
}
