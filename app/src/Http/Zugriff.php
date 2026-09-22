<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Permission;

/**
 * The access declaration every route carries (docs/spec/01-sicherheit.md
 * section 4, issue #19/M3-6): who may call it. Router::add() takes it as a
 * required argument, so a route without one does not exist, and
 * app/src/routes.php derives the guard wrapper from this very value - the
 * declaration and the check cannot drift apart.
 *
 * tests/Http/RoutePermissionMatrixTest.php runs every declared route
 * against every shipped role.
 */
final readonly class Zugriff
{
    private function __construct(
        public ZugriffArt $art,
        public ?Permission $recht = null,
        /** JSON routes (fetch()): 401/403 as JSON instead of a redirect or a page. */
        public bool $api = false,
    ) {
    }

    /** No session, no login: the way in, the start page, the public submission. */
    public static function oeffentlich(): self
    {
        return new self(ZugriffArt::Oeffentlich);
    }

    /** No session; the shared cron token is the credential (06 section 4). */
    public static function cron(): self
    {
        return new self(ZugriffArt::Cron);
    }

    /** Any logged-in account - things every account does for itself. */
    public static function angemeldet(): self
    {
        return new self(ZugriffArt::Angemeldet);
    }

    public static function recht(Permission $recht): self
    {
        return new self(ZugriffArt::Recht, $recht);
    }

    /** Any `admin.*` right - the floor for every page under /admin. */
    public static function adminBereich(): self
    {
        return new self(ZugriffArt::AdminBereich);
    }

    public function alsApi(): self
    {
        return new self($this->art, $this->recht, api: true);
    }

    /** Whether the guard has to run at all. */
    public function brauchtAnmeldung(): bool
    {
        return match ($this->art) {
            ZugriffArt::Oeffentlich, ZugriffArt::Cron => false,
            ZugriffArt::Angemeldet, ZugriffArt::Recht, ZugriffArt::AdminBereich => true,
        };
    }

    /** For test output and the spec's route table. */
    public function beschreibung(): string
    {
        return match ($this->art) {
            ZugriffArt::Oeffentlich => 'öffentlich',
            ZugriffArt::Cron => 'cron-token',
            ZugriffArt::Angemeldet => 'angemeldet',
            ZugriffArt::Recht => (string) $this->recht?->value,
            ZugriffArt::AdminBereich => 'admin.*',
        } . ($this->api ? ' (api)' : '');
    }
}
