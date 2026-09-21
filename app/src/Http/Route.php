<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Route
{
    public function __construct(
        public HttpMethod $method,
        public string $pattern,
        public \Closure $handler,
    ) {
    }

    /**
     * Compiles a pattern like "/beleg/{id}.pdf" or "/beleg/{id:\d+}" into a
     * PCRE with named capture groups. The default placeholder regex [^/]+ is
     * greedy, so a literal suffix like ".pdf" still matches via backtracking
     * (id captures "42" from "42.pdf").
     */
    public function compile(): string
    {
        $regex = preg_replace_callback(
            '/\{([a-z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}|([^{]+)/',
            static function (array $matches): string {
                if (($matches[1] ?? '') !== '') {
                    $inner = ($matches[2] ?? '') !== '' ? $matches[2] : '[^/]+';

                    return '(?P<' . $matches[1] . '>' . $inner . ')';
                }

                return preg_quote($matches[3], '#');
            },
            $this->pattern,
        );

        return '#^' . $regex . '$#';
    }
}
