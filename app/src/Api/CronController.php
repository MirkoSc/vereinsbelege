<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Service\Cron\CronRunner;

/**
 * Cron entry point: the host's control-panel cronjob calls
 * `GET /cron?token=...` (docs/spec/06-betrieb.md section 4).
 *
 * No session and no login - the shared secret from shared/config.php is the
 * only credential. That is also why the cron never decrypts: it has no vault
 * key (CLAUDE.md section 4).
 *
 * The runner is built lazily so that a wrong token costs no database
 * connection.
 */
final readonly class CronController
{
    /**
     * @param \Closure(): CronRunner $runner
     */
    public function __construct(
        private Config $config,
        private \Closure $runner,
    ) {
    }

    /**
     * The URL the hoster's cronjob calls - shown to an admin after the
     * installer wrote a new token and on the system check page (issue
     * #244). It carries the token, so it goes into a page for an admin and
     * nowhere else: never into a log, a mail or a link of the application.
     */
    public static function url(string $origin, #[\SensitiveParameter] string $token): string
    {
        return rtrim($origin, '/') . '/cron?token=' . rawurlencode($token);
    }

    public function run(Request $request): Response
    {
        $token = $request->query['token'] ?? '';
        // A non-string (?token[]=x) must not reach hash_equals, which throws
        // on it; an empty token never matches, even if the config were empty.
        if (!is_string($token) || $token === '' || !hash_equals($this->config->cronToken, $token)) {
            return Response::json(['fehler' => 'Ungültiges Token.'], 403);
        }

        $ergebnis = ($this->runner)()->run();

        return Response::json($ergebnis->toArray());
    }
}
