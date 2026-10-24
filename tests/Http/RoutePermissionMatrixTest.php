<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Domain\Role;
use App\Domain\SystemRole;
use App\Domain\User;
use App\Domain\UserStatus;
use App\Http\HttpMethod;
use App\Http\LoginGuard;
use App\Http\MatchType;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Route;
use App\Http\Router;
use App\Http\Session;
use App\Http\ZugriffArt;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\View\View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rights matrix over every route (docs/spec/01-sicherheit.md section 4,
 * "Pflicht-Tests", issue #19/M3-6).
 *
 * Built from the real app/src/routes.php with the real App\Http\LoginGuard,
 * so what is checked is what the application serves: every route, as
 * anonymous visitor and as each of the six shipped roles. The controllers
 * behind the routes are stand-ins that only say "reached" - whether the
 * guard let the request through is all this test is about.
 *
 * A route without a declaration cannot be registered at all (Router::add()
 * requires an App\Http\Zugriff); the structural tests below pin what the
 * declarations must look like, so a new route cannot quietly be public or
 * sit under /admin with a non-admin right.
 */
final class RoutePermissionMatrixTest extends TestCase
{
    public const string ERREICHT = 'ERREICHT';

    /**
     * Routes that may be reached without a login. Anything else that turns
     * up public fails testOnlyTheAllowlistIsPublic() - a deliberate new
     * public route is added here in the same change.
     */
    private const array OEFFENTLICH = [
        '/',
        '/anmelden',
        '/abmelden',
        '/anmelden/bestaetigen',
        '/anmelden/code-senden',
        '/anmelden/backup-code',
        '/anmelden/passwort-vergessen',
        '/anmelden/passwort-neu',
        '/anmelden/einladung',
        '/cron',
        '/einreichen',
        '/einreichen/upload',
        '/einreichen/upload/{id:[0-9a-f]+}/chunk/{n:\d+}',
        '/einreichen/upload/{id:[0-9a-f]+}/finish',
        '/einreichen/upload/{id:[0-9a-f]+}/abort',
        // Issue #51/M7b-1: the one-time pairing code is the credential.
        '/api/worker/pair',
    ];

    private ?SessionUser $angemeldet = null;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routen(): iterable
    {
        foreach (self::routerMit(static fn(): ?SessionUser => null)->routes() as $route) {
            yield $route->method->value . ' ' . $route->pattern => [$route->method->value, $route->pattern];
        }
    }

    #[DataProvider('routen')]
    public function testEveryRoleGetsExactlyWhatTheDeclarationAllows(string $methode, string $muster): void
    {
        $router = $this->router();
        $route = self::finde($router, $methode, $muster);
        $pfad = self::beispielPfad($muster);

        // Anonymous.
        $this->angemeldet = null;
        $_SESSION = [];
        $antwort = $this->aufrufen($router, $route->method, $pfad);
        if ($route->zugriff->brauchtAnmeldung()) {
            self::assertTrue(
                $antwort->status === 401 || ($antwort->status === 302 && str_starts_with($antwort->headers['Location'] ?? '', '/anmelden')),
                $muster . ' must turn an anonymous visitor away',
            );
        } else {
            self::assertTrue($this->erreicht($antwort), $muster . ' is public and must be reachable');
        }

        foreach (SystemRole::cases() as $rolle) {
            $berechtigungen = self::berechtigungen($rolle);
            $this->anmelden($berechtigungen);

            $antwort = $this->aufrufen($router, $route->method, $pfad);
            $erwartet = self::erwartet($route, $pfad, $berechtigungen);

            self::assertSame(
                $erwartet,
                $this->erreicht($antwort),
                sprintf('%s %s as %s (declared: %s, answer %d)', $methode, $muster, $rolle->value, $route->zugriff->beschreibung(), $antwort->status),
            );
            if (!$erwartet && $route->zugriff->brauchtAnmeldung()) {
                self::assertSame(403, $antwort->status, $muster . ' must answer 403 to ' . $rolle->value);
            }
        }
    }

    public function testEveryRouteCarriesADeclaration(): void
    {
        $routen = $this->router()->routes();

        self::assertNotEmpty($routen);
        foreach ($routen as $route) {
            self::assertNotSame('', $route->zugriff->beschreibung(), $route->pattern);
        }
    }

    public function testOnlyTheAllowlistIsPublic(): void
    {
        foreach ($this->router()->routes() as $route) {
            // Signed by a paired worker - not public, see the test below.
            if ($route->zugriff->art === ZugriffArt::Worker) {
                continue;
            }
            if (!$route->zugriff->brauchtAnmeldung()) {
                self::assertContains($route->pattern, self::OEFFENTLICH, $route->pattern . ' is public but not on the allowlist');
            }
        }
    }

    /**
     * Issue #51/M7b-1: every worker route but the pairing is signed, and
     * the signature declaration exists only under /api/worker/.
     */
    public function testWorkerRoutesAreSignedAndOnlyThere(): void
    {
        $gefunden = 0;
        foreach ($this->router()->routes() as $route) {
            $unterWorker = str_starts_with($route->pattern, '/api/worker/');
            if ($route->zugriff->art === ZugriffArt::Worker) {
                self::assertTrue($unterWorker, $route->pattern . ' is declared worker outside /api/worker/');
                ++$gefunden;
            } elseif ($unterWorker) {
                self::assertSame('/api/worker/pair', $route->pattern, $route->pattern . ' under /api/worker/ is not signed');
            }
        }
        self::assertGreaterThan(0, $gefunden);
    }

    /**
     * docs/spec/01-sicherheit.md section 4: "Adminseite /admin/* nur mit
     * mindestens einem admin.*-Recht erreichbar" - declared that way, not
     * only enforced by the guard's floor.
     */
    public function testEveryAdminRouteNeedsAnAdminRight(): void
    {
        foreach ($this->router()->routes() as $route) {
            if (!str_starts_with($route->pattern, '/admin')) {
                continue;
            }

            $zugriff = $route->zugriff;
            self::assertTrue(
                $zugriff->art === ZugriffArt::AdminBereich
                    || ($zugriff->art === ZugriffArt::Recht && $zugriff->recht?->istAdmin() === true),
                $route->pattern . ' is declared ' . $zugriff->beschreibung(),
            );
        }
    }

    public function testTheUploadAnswersInJson(): void
    {
        foreach ($this->router()->routes() as $route) {
            if (!str_starts_with($route->pattern, '/api/')) {
                continue;
            }
            self::assertTrue($route->zugriff->api, $route->pattern);

            // The job step (issue #29/M4-7) is the one /api/ route open to
            // any logged-in account: WHICH job types it may touch is a
            // per-type check inside App\Service\Job\JobRunner, not something
            // a single route-level Permission could express.
            if ($route->pattern === '/api/jobs/step') {
                self::assertSame(ZugriffArt::Angemeldet, $route->zugriff->art, $route->pattern);

                continue;
            }

            // The render_pages browser job's routes (issue #30/M4-8) need
            // `document.edit` - the same right pdf_erzeugen and the inbox
            // decision itself need, and unlike /api/jobs/step there is
            // exactly one job type behind them, so the route declares it.
            if (str_starts_with($route->pattern, '/api/rasterung/')) {
                self::assertSame(Permission::DocumentEdit, $route->zugriff->recht, $route->pattern);

                continue;
            }

            // The worker API (issue #51/M7b-1): no account at all - see
            // testWorkerRoutesAreSignedAndOnlyThere().
            if (str_starts_with($route->pattern, '/api/worker/')) {
                continue;
            }

            self::assertSame(Permission::DocumentSubmitInternal, $route->zugriff->recht, $route->pattern);
        }
    }

    /**
     * Spot checks against the spec's table itself, so the matrix above
     * cannot pass merely because declaration and expectation share a bug.
     */
    public function testSpotChecksAgainstTheSpecTable(): void
    {
        $router = $this->router();
        $faelle = [
            [SystemRole::Admin, HttpMethod::Get, '/admin/update', true],
            // Systemcheck (M3-10): admin.system, like Update - and read only.
            [SystemRole::Admin, HttpMethod::Get, '/admin/systemcheck', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/admin/systemcheck', false],
            [SystemRole::Finanzen, HttpMethod::Get, '/admin/systemcheck', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/admin/systemcheck', false],
            // Backups (issue #112): admin.system, like Update - the list, the
            // chain and the download of the whole dump.
            [SystemRole::Admin, HttpMethod::Get, '/admin/backups', true],
            [SystemRole::Admin, HttpMethod::Post, '/admin/backups/schritt', true],
            [SystemRole::Admin, HttpMethod::Get, '/admin/backups/backup_20260101_120000.zip', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/admin/backups', false],
            [SystemRole::Vorstand, HttpMethod::Get, '/admin/backups/backup_20260101_120000.zip', false],
            [SystemRole::Finanzen, HttpMethod::Post, '/admin/backups', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/admin/backups/backup_20260101_120000.zip', false],
            [SystemRole::Admin, HttpMethod::Get, '/admin/rollen', true],
            [SystemRole::Admin, HttpMethod::Get, '/admin/benutzer', true],
            [SystemRole::Admin, HttpMethod::Post, '/admin/tresor/7/freigeben', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/admin/benutzer', false],
            [SystemRole::Finanzen, HttpMethod::Post, '/admin/benutzer/7/sperren', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/admin/tresor', false],
            [SystemRole::Admin, HttpMethod::Get, '/admin/wiederherstellen', true],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/admin/wiederherstellen', false],
            [SystemRole::Vorstand, HttpMethod::Get, '/admin/mail', false],
            [SystemRole::Admin, HttpMethod::Get, '/admin/einreichung', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/admin/einreichung', false],
            // Export pattern (M12-1): admin.settings, like Einreichung -
            // export.zip alone (Vorstand, Finanzen) does not configure it.
            [SystemRole::Admin, HttpMethod::Get, '/admin/export', true],
            [SystemRole::Admin, HttpMethod::Post, '/admin/export/vorschau', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/admin/export', false],
            [SystemRole::Finanzen, HttpMethod::Post, '/admin/export', false],
            // Kategorien (M6-1): admin.settings, like Kostenstellen.
            [SystemRole::Admin, HttpMethod::Get, '/admin/kategorien', true],
            [SystemRole::Admin, HttpMethod::Post, '/admin/kategorien/3/nach-oben', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/admin/kategorien', false],
            [SystemRole::Vorstand, HttpMethod::Post, '/admin/kategorien', false],
            // KI-Anbieter (M7-1): admin.settings ("KI", 01 section 4) - also
            // the connection test, which spends the club's provider credit.
            [SystemRole::Admin, HttpMethod::Get, '/admin/ki-anbieter', true],
            [SystemRole::Admin, HttpMethod::Post, '/admin/ki-anbieter/3/testen', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/admin/ki-anbieter', false],
            [SystemRole::Finanzen, HttpMethod::Post, '/admin/ki-anbieter/3/testen', false],
            [SystemRole::Vorstand, HttpMethod::Post, '/admin/ki-anbieter/3/standard', false],
            [SystemRole::Admin, HttpMethod::Post, '/admin/ki-anbieter/3/fallback', true],
            [SystemRole::Admin, HttpMethod::Post, '/admin/ki-anbieter/fallback-entfernen', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/admin/ki-anbieter/3/fallback', false],
            [SystemRole::Vorstand, HttpMethod::Post, '/admin/ki-anbieter/fallback-entfernen', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/admin/ki-anbieter/3', false],
            // Lieferanten (M6-2): supplier.manage - Admin and Finanzen only,
            // reading included; Vorstand, Kassenprüfer and Steuerberater do not
            // maintain suppliers.
            [SystemRole::Admin, HttpMethod::Get, '/app/lieferanten', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/lieferanten', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/lieferanten', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/lieferanten/7/loeschen', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/lieferanten', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/lieferanten/7', false],
            [SystemRole::Steuerberater, HttpMethod::Get, '/app/lieferanten', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/app/lieferanten/7', false],
            // Zusammenführen (M6-5): the same permission.
            [SystemRole::Admin, HttpMethod::Get, '/app/lieferanten/7/zusammenfuehren', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/lieferanten/7/zusammenfuehren', true],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/lieferanten/7/zusammenfuehren', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/lieferanten/7/zusammenfuehren', false],
            // "Als geprüft markieren" (M7-6): the same permission.
            [SystemRole::Finanzen, HttpMethod::Post, '/app/lieferanten/7/geprueft', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/lieferanten/7/geprueft', true],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/lieferanten/7/geprueft', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/lieferanten/7/geprueft', false],
            // Prüfansicht (M6-3): document.edit - Admin and Finanzen capture
            // receipts; the reading roles and the Vereinsverantwortlicher
            // (inbox.view only) do not.
            [SystemRole::Admin, HttpMethod::Get, '/app/belege/pruefen', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/belege/pruefen', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/belege/pruefen/7', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/belege/pruefen/7', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/belege/pruefen/7/datei/3', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/belege/pruefen', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/belege/pruefen/7', false],
            [SystemRole::Steuerberater, HttpMethod::Get, '/app/belege/pruefen/7/datei/3', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/belege/pruefen', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/app/belege/pruefen/7', false],
            // Recurring series (issue #57/M8-1): `document.edit` like the review page.
            [SystemRole::Admin, HttpMethod::Get, '/app/serien', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/serien', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/serien/3', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/serien/3', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/serien/3/verwerfen', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/serien', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/serien/3', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/serien/3', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/app/serien/3/verwerfen', false],
            // Work lists "Offen" (issue #69/M10-5): receipts `report.view`
            // (also a cost-center scope), bookings `bank.view`, expected
            // receipts `document.edit`.
            [SystemRole::Vorstand, HttpMethod::Get, '/app/offen', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/offen/rechnungen', true],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/offen/erstattungen', true],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/offen/rechnungen', true],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/offen/erstattungen', true],
            [SystemRole::Steuerberater, HttpMethod::Get, '/app/offen/buchungen', true],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/offen/buchungen', false],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/offen/erwartet', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/offen/erwartet', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/offen/erwartet', false],
            // Festschreibung (issue #38/M6-4): the right to capture is the right to lock.
            [SystemRole::Admin, HttpMethod::Post, '/app/belege/pruefen/7/festschreiben', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/belege/pruefen/7/festschreiben', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/belege/pruefen/7/festschreibung-aufheben', true],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/belege/pruefen/7/festschreiben', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/belege/pruefen/7/festschreibung-aufheben', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/belege/pruefen/7/festschreiben', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/app/belege/pruefen/7/festschreibung-aufheben', false],
            // Duplicate detection (issue #40/M6-6): resolving is `document.edit`.
            [SystemRole::Finanzen, HttpMethod::Post, '/app/belege/pruefen/7/duplikat-verwerfen', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/belege/pruefen/7/duplikat-behalten', true],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/belege/pruefen/7/duplikat-verwerfen', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/belege/pruefen/7/duplikat-behalten', false],
            // Asking the AI again (issue #47/M7-5): `document.edit` like capturing.
            [SystemRole::Finanzen, HttpMethod::Post, '/app/belege/pruefen/7/ki-erneut', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/belege/pruefen/7/ki-erneut', true],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/belege/pruefen/7/ki-erneut', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/belege/pruefen/7/ki-erneut', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/app/belege/pruefen/7/ki-erneut', false],
            // Konten (M9-1): bank.view reads - Vorstand, Kassenprüfer and
            // Steuerberater too; bank.book writes and records cash counts -
            // Admin and Finanzen only. The Vereinsverantwortlicher sees no
            // accounts at all.
            [SystemRole::Admin, HttpMethod::Get, '/app/konten', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/konten', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/konten/7/kassensturz', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/konten', true],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/konten/7', true],
            [SystemRole::Steuerberater, HttpMethod::Get, '/app/konten', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/konten/neu', false],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/konten/7', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/konten/7/kassensturz', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/konten/7/loeschen', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/konten', false],
            // CSV-Formate (M9-3): bank.import on every route - Admin and
            // Finanzen; reading accounts (Vorstand, Kassenprüfer,
            // Steuerberater) is not enough, not even to look at a format.
            [SystemRole::Admin, HttpMethod::Get, '/app/konten/csv-formate', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/konten/csv-formate/neu', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/konten/csv-formate/vorschau', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/konten/csv-formate', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/konten/csv-formate/7/loeschen', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/konten/csv-formate', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/konten/csv-formate/7', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/konten/csv-formate/vorschau', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/konten/csv-formate/7/bearbeiten', false],
            // Kontoauszug-Import (M9-4): bank.import on every route, the
            // JSON step of the chain included - Admin and Finanzen; reading
            // accounts is not enough.
            [SystemRole::Finanzen, HttpMethod::Get, '/app/konten/import', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/konten/import', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/konten/import/7', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/konten/import/7/konto', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/konten/import/7/uebernehmen', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/konten/import/7/schritt', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/konten/import/7/verwerfen', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/konten/import', false],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/konten/import/7/uebernehmen', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/konten/import/7', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/konten/import/7/schritt', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/app/konten/import', false],
            // Buchungen (M9-5): the same split as the accounts - bank.view
            // reads the list and a booking, bank.book enters, changes and
            // deletes manual bookings.
            [SystemRole::Admin, HttpMethod::Get, '/app/buchungen', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/buchungen', true],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/buchungen/7', true],
            [SystemRole::Steuerberater, HttpMethod::Get, '/app/buchungen', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/buchungen/neu', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/buchungen/7', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen/7/loeschen', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/buchungen/neu', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/buchungen', false],
            // Reimbursements (M10-3): `matching.edit` - Finanzen and Admin only.
            [SystemRole::Finanzen, HttpMethod::Get, '/app/buchungen/7/erstattung', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/buchungen/7/erstattung', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/buchungen/7/erstattung', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/buchungen/7/erstattung', false],
            [SystemRole::Steuerberater, HttpMethod::Get, '/app/buchungen/7/erstattung', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/buchungen/7', false],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/buchungen/7/loeschen', false],
            // ZIP export (M12-2): export.zip on the user side - the board,
            // the auditors and the tax advisor export without the admin
            // area; a club officer without the right does not.
            [SystemRole::Admin, HttpMethod::Get, '/app/export', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/export', true],
            // Export runs (M12-4): the same right for creating, downloading,
            // confirming and resetting a run.
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/export/lauf', true],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/export/lauf/7/zip', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/export/lauf/7', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/export/lauf/7/bestaetigen', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/export/lauf/7/zuruecksetzen', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/export', true],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/export', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/app/export/lauf', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/app/export/lauf/7/bestaetigen', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/buchungen', false],
            // Archive import (M12-3): archive.import - Admin and Finanzen
            // only; the board and the auditors read, they do not import.
            [SystemRole::Admin, HttpMethod::Get, '/app/archiv-import', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/archiv-import/upload', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/archiv-import/7/schritt', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/archiv-import/upload/0123456789abcdef0123456789abcdef/finish', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/archiv-import', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/archiv-import/7/starten', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/archiv-import/upload/0123456789abcdef0123456789abcdef/chunk/0', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/archiv-import/7', false],
            // Einordnung und Buchungsregeln (M9-6): bank.view reads the
            // rules - auditors see what they do -, bank.book does
            // everything that writes.
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen/7/einordnung', true],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/buchungen/7/einordnung', false],
            // Umbuchungen eigene Konten (M10-6): pairing, unpairing and the
            // recognition write - bank.book; readers only see the pair.
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen/umbuchungen', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen/7/umbuchung', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen/7/umbuchung/loesen', true],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/buchungen/umbuchungen', false],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/buchungen/7/umbuchung', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/buchungen/7/umbuchung/loesen', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/app/buchungen/regeln', true],
            [SystemRole::Steuerberater, HttpMethod::Get, '/app/buchungen/regeln/7', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/buchungen/regeln/neu', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen/regeln', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/buchungen/regeln/7', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen/regeln/7/anwenden', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen/regeln/7/aktiv', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen/regeln/7/loeschen', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/buchungen/regeln/standard', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/buchungen/regeln/neu', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/buchungen/regeln/7/anwenden', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/buchungen/regeln/standard', false],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/buchungen/regeln/7/loeschen', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/buchungen/regeln', false],
            // Abgleich (M10-2): matching.edit - Admin and Finanzen; the
            // reading roles see bookings and receipts but do not allocate.
            [SystemRole::Admin, HttpMethod::Get, '/app/abgleich', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/abgleich', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/abgleich/ausfuehren', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/abgleich/uebernehmen', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/abgleich/einstellungen', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/abgleich', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/abgleich/ausfuehren', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/abgleich/uebernehmen', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/app/abgleich/einstellungen', false],
            [SystemRole::Vorstand, HttpMethod::Post, '/api/upload', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/api/upload', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/admin/designsystem', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/api/upload', false],
            [SystemRole::Steuerberater, HttpMethod::Get, '/admin', false],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/api/upload', true],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app', true],
            // audit.view (M3-8): in /app so that Vorstand and Kassenprüfer,
            // who hold no admin.* right, reach it.
            [SystemRole::Admin, HttpMethod::Get, '/app/audit', true],
            [SystemRole::Vorstand, HttpMethod::Get, '/app/audit', true],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/audit/pruefen', true],
            [SystemRole::Finanzen, HttpMethod::Get, '/app/audit', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/audit/pruefen', false],
            // Posteingang (M4-5): reading is `inbox.view` (every shipped
            // role), deciding `document.edit` (Admin, Finanzen).
            [SystemRole::Vorstand, HttpMethod::Get, '/app/posteingang', true],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/posteingang/7', true],
            [SystemRole::Steuerberater, HttpMethod::Get, '/app/posteingang/7/datei/3', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/posteingang/7/ablehnen', true],
            [SystemRole::Admin, HttpMethod::Post, '/app/posteingang/7/wiedervorlage', true],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/posteingang/7/annehmen', false],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/posteingang/7/kostenstelle', false],
            [SystemRole::Admin, HttpMethod::Post, '/app/posteingang/7/duplikat-verwerfen', true],
            [SystemRole::Finanzen, HttpMethod::Post, '/app/posteingang/7/duplikat-behalten', true],
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Post, '/app/posteingang/7/duplikat-verwerfen', false],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/posteingang/7/duplikat-behalten', false],
            // Full-text search (issue #94): inbox.view like the inbox the
            // hits link to - every shipped role, the Vereinsverantwortlicher
            // within his cost centers (the scope is the service's job).
            [SystemRole::Vereinsverantwortlicher, HttpMethod::Get, '/app/suche', true],
            [SystemRole::Steuerberater, HttpMethod::Post, '/app/suche', true],
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/app/suche/index', true],
            [SystemRole::Vorstand, HttpMethod::Post, '/app/suche/neu', true],
            // The job step (M4-7): the route itself only needs a login -
            // per-type rights are JobRunner's job, not the guard's.
            [SystemRole::Kassenpruefer, HttpMethod::Post, '/api/jobs/step', true],
            [SystemRole::Steuerberater, HttpMethod::Post, '/api/jobs/step', true],
            // render_pages (M4-8): document.edit, same as the inbox decision
            // itself - Finanzen and Admin only.
            [SystemRole::Finanzen, HttpMethod::Post, '/api/rasterung/naechste', true],
            [SystemRole::Vorstand, HttpMethod::Post, '/api/rasterung/naechste', false],
            [SystemRole::Kassenpruefer, HttpMethod::Get, '/api/rasterung/7/abc123/quelle/0', false],
        ];

        foreach ($faelle as [$rolle, $methode, $pfad, $darf]) {
            $this->anmelden(self::berechtigungen($rolle));
            self::assertSame($darf, $this->erreicht($this->aufrufen($router, $methode, $pfad)), $rolle->value . ' ' . $pfad);
        }
    }

    /**
     * Issue #90: while a vault key rotation runs, /app and /admin are closed
     * - except the rotation itself and the account's own security pages.
     * Accounts that may run the rotation are sent there; JSON answers 409.
     */
    public function testWaehrendDerSchluesselrotationIstNurDieRotationOffen(): void
    {
        $router = self::routerMit(fn(): ?SessionUser => $this->angemeldet, rotationLaeuft: true);

        $this->anmelden(self::berechtigungen(SystemRole::Admin));
        self::assertTrue($this->erreicht($this->aufrufen($router, HttpMethod::Get, '/admin/tresor/rotation')));
        self::assertTrue($this->erreicht($this->aufrufen($router, HttpMethod::Post, '/admin/tresor/rotation/schritt')));
        self::assertTrue($this->erreicht($this->aufrufen($router, HttpMethod::Get, '/app/sicherheit')));
        // The ways out stay open: recovery, update, maintenance, backups, abandoning.
        foreach (['/admin/wiederherstellen', '/admin/update', '/admin/backups'] as $ausgang) {
            self::assertSame(self::ERREICHT, $this->aufrufen($router, HttpMethod::Get, $ausgang)->body, $ausgang);
        }
        self::assertSame(self::ERREICHT, $this->aufrufen($router, HttpMethod::Post, '/admin/wartung/aufheben')->body);
        self::assertSame(self::ERREICHT, $this->aufrufen($router, HttpMethod::Post, '/admin/tresor/rotation/abbrechen')->body);
        $umgeleitet = $this->aufrufen($router, HttpMethod::Get, '/app/posteingang');
        self::assertNotSame(self::ERREICHT, $umgeleitet->body);
        self::assertSame(302, $umgeleitet->status);
        self::assertSame('/admin/tresor/rotation', $umgeleitet->headers['Location'] ?? null);
        self::assertSame('/admin/tresor/rotation', $this->aufrufen($router, HttpMethod::Get, '/admin/tresor')->headers['Location'] ?? null);
        $json = $this->aufrufen($router, HttpMethod::Post, '/api/jobs/step');
        self::assertSame(409, $json->status);

        $this->anmelden(self::berechtigungen(SystemRole::Finanzen));
        $gesperrt = $this->aufrufen($router, HttpMethod::Get, '/app/posteingang');
        self::assertSame(503, $gesperrt->status);
        self::assertFalse($this->erreicht($gesperrt));
        self::assertTrue($this->erreicht($this->aufrufen($router, HttpMethod::Get, '/app/sicherheit')));
        // A right the account lacks is still a plain 403, not a hint at the rotation.
        self::assertSame(403, $this->aufrufen($router, HttpMethod::Get, '/admin/tresor/rotation')->status);
    }

    public function testAdminIsSentToTheFirstAdminPageItMayOpen(): void
    {
        $this->anmelden(self::berechtigungen(SystemRole::Admin));

        $antwort = $this->aufrufen($this->router(), HttpMethod::Get, '/admin');

        self::assertSame(302, $antwort->status);
        self::assertSame('/admin/update', $antwort->headers['Location'] ?? null);
    }

    public function testAnAccountWithoutAnyRoleMayOnlyUseTheLoginArea(): void
    {
        $router = $this->router();
        $this->anmelden(Berechtigungen::keine());

        self::assertTrue($this->erreicht($this->aufrufen($router, HttpMethod::Get, '/app')));
        self::assertTrue($this->erreicht($this->aufrufen($router, HttpMethod::Get, '/app/sicherheit')));
        self::assertSame(403, $this->aufrufen($router, HttpMethod::Get, '/admin/designsystem')->status);
        self::assertSame(403, $this->aufrufen($router, HttpMethod::Post, '/api/upload')->status);
    }

    // ----------------------------------------------------------- scaffolding

    private static function erwartet(Route $route, string $pfad, Berechtigungen $berechtigungen): bool
    {
        $zugriff = $route->zugriff;
        if (($pfad === '/admin' || str_starts_with($pfad, '/admin/')) && !$berechtigungen->darfAdminBereich()) {
            return false;
        }

        return match ($zugriff->art) {
            ZugriffArt::Oeffentlich, ZugriffArt::Cron, ZugriffArt::Worker, ZugriffArt::Angemeldet => true,
            ZugriffArt::AdminBereich => $berechtigungen->darfAdminBereich(),
            ZugriffArt::Recht => $zugriff->recht !== null && $berechtigungen->darf($zugriff->recht),
        };
    }

    private static function berechtigungen(SystemRole $rolle): Berechtigungen
    {
        return new Berechtigungen([new Role(1, $rolle->bezeichnung(), $rolle, $rolle->istExtern(), $rolle->standardRechte())]);
    }

    private function anmelden(Berechtigungen $berechtigungen): void
    {
        // Started first: session_start() would replace what is set here.
        new Session()->start();
        $jetzt = new \DateTimeImmutable();
        $this->angemeldet = new SessionUser(
            new User(7, 'x', 'x', 'x', UserStatus::Aktiv, null, false, null, $jetzt, null),
            'Test',
            $berechtigungen,
        );
        $_SESSION = [
            'user_id' => 7,
            'login_at' => $jetzt->getTimestamp(),
            'last_seen_at' => $jetzt->getTimestamp(),
            'session_epoch' => 0,
        ];
    }

    private function aufrufen(Router $router, HttpMethod $methode, string $pfad): ResponseInterface
    {
        $treffer = $router->match($methode, $pfad);
        self::assertSame(MatchType::Matched, $treffer->type, $methode->value . ' ' . $pfad);
        assert($treffer->handler !== null);

        return ($treffer->handler)(new Request($methode, $pfad), $treffer->params);
    }

    /**
     * Reached = the handler ran: either a stand-in answered, or one of the
     * few inline handlers in routes.php (start pages, the /admin redirect)
     * produced its page.
     */
    private function erreicht(ResponseInterface $antwort): bool
    {
        assert($antwort instanceof Response);
        if ($antwort->body === self::ERREICHT) {
            return true;
        }
        if ($antwort->status === 302) {
            $ziel = $antwort->headers['Location'] ?? '';

            return !str_starts_with($ziel, '/anmelden') && $ziel !== '/app/sicherheit/einrichten';
        }

        return $antwort->status === 200;
    }

    private static function finde(Router $router, string $methode, string $muster): Route
    {
        foreach ($router->routes() as $route) {
            if ($route->method->value === $methode && $route->pattern === $muster) {
                return $route;
            }
        }

        self::fail('Route not found: ' . $methode . ' ' . $muster);
    }

    /** A concrete path for a pattern: each placeholder gets a value its regex accepts. */
    private static function beispielPfad(string $muster): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}/',
            static function (array $m): string {
                $regex = ($m[2] ?? '') !== '' ? $m[2] : '[^/]+';
                foreach (['1', str_repeat('a', 32), 'abc', 'backup_20260101_120000.zip'] as $kandidat) {
                    if (preg_match('#^(?:' . $regex . ')$#', $kandidat) === 1) {
                        return $kandidat;
                    }
                }

                self::fail('No sample value for placeholder ' . $m[0]);
            },
            $muster,
        );
    }

    /**
     * The real route table; every controller is a stand-in that answers
     * "reached" to whatever method is called on it.
     */
    private function router(): Router
    {
        return self::routerMit(fn(): ?SessionUser => $this->angemeldet);
    }

    /**
     * @param \Closure(): ?SessionUser $benutzer who the guard sees as logged in
     */
    private static function routerMit(\Closure $benutzer, bool $rotationLaeuft = false): Router
    {
        $view = new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test');
        $stellvertreter = static fn(): object => new class {
            /** @param array<mixed> $argumente */
            public function __call(string $name, array $argumente): Response
            {
                return Response::html(RoutePermissionMatrixTest::ERREICHT);
            }
        };

        $guard = static fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            static fn(int $id): ?SessionUser => $benutzer(),
            rotationLaeuft: static fn(): bool => $rotationLaeuft,
        );

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $stellvertreter,
            $guard,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            $stellvertreter,
            // The Nextcloud settings (issue #95), optional.
            $stellvertreter,
            // The backups page (issue #112), optional.
            $stellvertreter,
            // The recurring series pages (issue #57/M8-1), optional.
            $stellvertreter,
            // The optional worker (issue #51/M7b-1). The signature check is
            // a stand-in here like the controllers: what this test pins is
            // the declarations; tests/Service/Worker/WorkerAuthTest checks
            // the signature itself.
            workerAuth: $stellvertreter,
            workerApi: $stellvertreter,
            workerAdmin: $stellvertreter,
            // The matching page (issue #66/M10-2), optional.
            abgleich: $stellvertreter,
            // The work lists "Offen" (issue #69/M10-5), optional.
            offen: $stellvertreter,
            // The vault key rotation (issue #90), optional.
            tresorRotation: $stellvertreter,
            // The reimbursement page (issue #67/M10-3), optional.
            erstattungen: $stellvertreter,
        );

        return $router;
    }

}
