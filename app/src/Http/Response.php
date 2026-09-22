<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Response implements ResponseInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
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
     * The same response plus one `Set-Cookie`. One cookie per response is
     * all this application ever sets (the vault key, App\Http\Cookie), so
     * the header map stays a simple name => value map.
     */
    public function withCookie(Cookie $cookie): self
    {
        return new self($this->status, [...$this->headers, 'Set-Cookie' => $cookie->header()], $this->body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
