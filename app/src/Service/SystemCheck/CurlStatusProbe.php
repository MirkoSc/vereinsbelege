<?php

declare(strict_types=1);

namespace App\Service\SystemCheck;

/**
 * Asks the web server for the HTTP status of one URL (issue #101): the
 * system check uses it to try fetching shared/config.php through the
 * installation's own domain.
 *
 * Only the status code leaves this class - the body is discarded unread, so
 * even a server that did hand out the file leaks nothing into the page or
 * its JSON. Redirects are not followed: the question is what THIS address
 * answers. Short timeouts, because the admin page waits for it.
 *
 * No curl_close(): deprecated since PHP 8.5 and without effect since 8.0
 * (same as App\Service\Update\ReleaseDownloader).
 */
final readonly class CurlStatusProbe
{
    private const int TIMEOUT_S = 5;

    /**
     * @return int|null the status code, null when no answer arrived
     */
    public function __invoke(string $url): ?int
    {
        if (!function_exists('curl_init') || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_S,
            CURLOPT_TIMEOUT => self::TIMEOUT_S,
            CURLOPT_USERAGENT => 'Vereinsbelege-Systemcheck',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($body);

        return $status > 0 ? $status : null;
    }
}
