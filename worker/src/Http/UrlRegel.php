<?php

declare(strict_types=1);

namespace App\Worker\Http;

/**
 * Which addresses the worker talks to (docs/spec/07-worker.md section 5):
 * https:// only. The single exception, for tests on one machine, is
 * http:// to localhost/127.0.0.1/[::1] - and only with the explicit test
 * option; any other host stays refused even then.
 */
final class UrlRegel
{
    private const array LOKAL = ['localhost', '127.0.0.1', '[::1]', '::1'];

    private function __construct()
    {
    }

    public static function erlaubt(string $url, bool $httpLocalhostErlaubt = false): bool
    {
        $teile = parse_url($url);
        if (!is_array($teile) || !isset($teile['scheme'], $teile['host']) || isset($teile['user']) || isset($teile['pass'])) {
            return false;
        }
        $schema = strtolower($teile['scheme']);
        if ($schema === 'https') {
            return true;
        }

        return $schema === 'http' && $httpLocalhostErlaubt && in_array(strtolower($teile['host']), self::LOKAL, true);
    }

    /**
     * The web host's base address as stored: scheme, host, port and path
     * prefix, without query, fragment or trailing slash.
     *
     * @throws \InvalidArgumentException when it is not allowed
     */
    public static function basis(string $url, bool $httpLocalhostErlaubt = false): string
    {
        $url = trim($url);
        if (!self::erlaubt($url, $httpLocalhostErlaubt)) {
            throw new \InvalidArgumentException('Die Adresse des Webhosters muss mit https:// beginnen.');
        }
        $teile = parse_url($url);
        if (!is_array($teile) || isset($teile['query']) || isset($teile['fragment'])) {
            throw new \InvalidArgumentException('Die Adresse des Webhosters darf keine Parameter enthalten.');
        }

        return strtolower((string) $teile['scheme']) . '://' . $teile['host']
            . (isset($teile['port']) ? ':' . $teile['port'] : '')
            . rtrim($teile['path'] ?? '', '/');
    }
}
