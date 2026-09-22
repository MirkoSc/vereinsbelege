<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * The base of links in mails (issue #18/M3-5): where the password reset
 * link points to.
 *
 * The obvious source - the request's Host header - is the dangerous one: it
 * is whatever the client sends. An attacker who requests a reset for
 * somebody else's address with `Host: evil.example` would otherwise have the
 * victim mailed a genuine token on a link to the attacker's server (host
 * header injection). So:
 *
 *   1. the configured `oeffentliche_url` (admin page Mail) wins, always;
 *   2. without it, the request host is accepted only when it is the
 *      sender address's domain or a subdomain of it - a host the club
 *      demonstrably controls;
 *   3. otherwise there is no link, and no mail.
 */
final class PublicUrl
{
    /**
     * @param string $host  the request's Host header, possibly with a port
     * @param bool   $https whether the request came in over HTTPS
     */
    public static function resolve(MailSettings $settings, string $host, bool $https): ?string
    {
        if ($settings->oeffentlicheUrl !== '') {
            return self::normalize($settings->oeffentlicheUrl);
        }

        $host = strtolower(trim($host));
        if (preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)(:\d{1,5})?$/', $host, $teile) !== 1) {
            return null;
        }
        $hostname = $teile[1];

        $klammeraffe = strrpos($settings->absender, '@');
        if ($klammeraffe === false) {
            return null;
        }
        $domain = strtolower(substr($settings->absender, $klammeraffe + 1));
        if ($domain === '' || ($hostname !== $domain && !str_ends_with($hostname, '.' . $domain))) {
            return null;
        }

        return ($https ? 'https://' : 'http://') . $host;
    }

    /**
     * The admin's input as a base URL, or null when it is not one: scheme,
     * host and optional port only - no path, query, fragment or credentials,
     * so nothing a link is appended to can change its meaning. HTTPS is
     * required (an installation requirement anyway, 06 section 1); plain
     * HTTP only for localhost, the docker dev setup.
     */
    public static function normalize(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $teile = parse_url($url);
        if (!is_array($teile) || !isset($teile['scheme'], $teile['host'])) {
            return null;
        }
        if (isset($teile['user']) || isset($teile['pass']) || isset($teile['path']) || isset($teile['query']) || isset($teile['fragment'])) {
            return null;
        }

        $scheme = strtolower($teile['scheme']);
        $host = strtolower($teile['host']);
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host) !== 1) {
            return null;
        }
        if ($scheme !== 'https' && !($scheme === 'http' && $host === 'localhost')) {
            return null;
        }

        return $scheme . '://' . $host . (isset($teile['port']) ? ':' . $teile['port'] : '');
    }
}
