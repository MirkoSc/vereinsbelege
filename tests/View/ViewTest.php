<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Domain\PermissionScope;
use App\Domain\Role;
use App\View\Area;
use App\View\Flash;
use App\View\FlashArt;
use App\View\View;
use PHPUnit\Framework\TestCase;

/**
 * The shared layout (milestone M1-3): one shell, three areas.
 *
 * The rules pinned here are the ones a new page silently breaks: a public
 * page that suddenly carries session data, a navigation entry that links to
 * a route nobody has built yet, an unescaped title.
 */
final class ViewTest extends TestCase
{
    /**
     * Renders a real page of the area, not a fixture: the point is that the
     * templates the application ships fit the layout.
     *
     * @param array<string, mixed> $data
     */
    private function render(string $template, array $data, Area $bereich, string $pfad = '/'): string
    {
        $view = new View(dirname(__DIR__, 2) . '/app/views', '1.2.3');
        $view->setCurrentPath($pfad);

        return $view->render($template, $data, $bereich);
    }

    public function testEveryAreaRendersACompletePage(): void
    {
        foreach (
            [
                [Area::Oeffentlich, 'home'],
                [Area::App, 'app/start'],
                [Area::Admin, 'admin/designsystem'],
            ] as [$bereich, $template]
        ) {
            $html = $this->render($template, ['title' => 'Titel'], $bereich);

            self::assertStringContainsString('<!DOCTYPE html>', $html);
            self::assertStringContainsString('<html lang="de">', $html);
            self::assertStringContainsString('class="bereich-' . $bereich->value . '"', $html);
            self::assertStringContainsString('<main id="inhalt"', $html);
            self::assertStringContainsString('Titel – Vereinsbelege', $html);
        }
    }

    public function testThePublicAreaHasNoNavigation(): void
    {
        $html = $this->render('home', ['title' => ''], Area::Oeffentlich);

        self::assertStringNotContainsString('<nav', $html);
        self::assertSame([], Area::Oeffentlich->navigation());
    }

    public function testTheUserAndAdminAreasCarryTheirNavigation(): void
    {
        $app = $this->render('app/start', ['title' => ''], Area::App);
        self::assertStringContainsString('aria-label="Hauptnavigation"', $app);
        self::assertStringContainsString('Posteingang', $app);

        $admin = $this->render('admin/designsystem', ['title' => ''], Area::Admin);
        self::assertStringContainsString('href="/admin/update"', $admin);
    }

    public function testThePageShownIsMarkedInTheNavigation(): void
    {
        $html = $this->render('admin/designsystem', ['title' => ''], Area::Admin, '/admin/designsystem');

        self::assertStringContainsString('href="/admin/designsystem" aria-current="page"', $html);
        self::assertSame(1, substr_count($html, 'aria-current="page"'), 'exactly one entry is the current page');
    }

    /**
     * Entries of areas a later milestone brings are rendered as text. A link
     * would be a 404 the navigation itself produces.
     */
    public function testEntriesWithoutARouteAreNotLinks(): void
    {
        $html = $this->render('app/start', ['title' => ''], Area::App);

        self::assertStringContainsString('<span class="navi-spaeter"', $html);
        self::assertStringNotContainsString('href="/app/posteingang"', $html);
    }

    /**
     * The public area must not start a session (App\Http\Session), so its
     * layout must not need one either - no CSRF token, and no htmx header
     * built from one.
     */
    public function testThePublicLayoutCarriesNoCsrfToken(): void
    {
        $html = $this->render('home', ['title' => '', 'csrf' => 'geheim'], Area::Oeffentlich);

        self::assertStringNotContainsString('geheim', $html);
        self::assertStringNotContainsString('hx-headers', $html);
    }

    /**
     * Inside the areas htmx sends the token as a header on every request -
     * App\Http\Session::checkCsrf accepts exactly this name. Without it the
     * first htmx POST of a later milestone would fail the CSRF check, and
     * the only symptom would be a session-expired message nobody can place.
     */
    public function testTheAreasSendTheCsrfTokenWithHtmxRequests(): void
    {
        $html = $this->render('admin/designsystem', ['title' => '', 'csrf' => 'abc123'], Area::Admin);

        self::assertStringContainsString('hx-headers=\'{"X-CSRF-Token":"abc123"}\'', $html);
    }

    public function testHtmxIsLoadedVendoredAndConfiguredWithoutAScript(): void
    {
        $html = $this->render('home', ['title' => ''], Area::Oeffentlich);

        self::assertStringContainsString('src="/js/vendor/htmx.min.js?v=1.2.3"', $html);
        self::assertStringContainsString('name="htmx-config"', $html);
        self::assertStringContainsString('"includeIndicatorStyles":false', $html);
        self::assertStringContainsString('"selfRequestsOnly":true', $html);
    }

    public function testAFlashIsRenderedWithItsVariantAndRole(): void
    {
        $html = $this->render(
            'app/start',
            ['title' => '', 'flash' => new Flash('Gespeichert.', FlashArt::Ok)],
            Area::App,
        );

        self::assertStringContainsString('class="hinweis hinweis-ok"', $html);
        self::assertStringContainsString('Gespeichert.', $html);
        self::assertStringContainsString('role="status"', $html);
    }

    /** A failure interrupts the screen reader, a confirmation waits its turn. */
    public function testAFailureFlashIsAnnouncedAsAnAlert(): void
    {
        $html = $this->render(
            'app/start',
            ['title' => '', 'flash' => new Flash('Ging schief.', FlashArt::Fehler)],
            Area::App,
        );

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('class="hinweis hinweis-fehler"', $html);
    }

    public function testTheLiveRegionExistsEvenWithoutAFlash(): void
    {
        // An element that only appears together with its text is announced
        // unreliably - after an htmx swap not at all.
        $html = $this->render('app/start', ['title' => ''], Area::App);

        self::assertStringContainsString('class="flash"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
    }

    public function testFlashTextIsEscaped(): void
    {
        $html = $this->render(
            'app/start',
            ['title' => '', 'flash' => new Flash('<b>x</b>')],
            Area::App,
        );

        self::assertStringNotContainsString('<b>x</b>', $html);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function testTheTitleIsEscaped(): void
    {
        $html = $this->render('home', ['title' => '"><b>x'], Area::Oeffentlich);

        self::assertStringContainsString('&quot;&gt;&lt;b&gt;x', $html);
    }

    /**
     * Every page can be reached with the keyboard without tabbing through
     * the whole navigation first.
     */
    public function testEveryPageHasASkipLink(): void
    {
        $html = $this->render('app/start', ['title' => ''], Area::App);

        self::assertStringContainsString('class="sprungmarke" href="#inhalt"', $html);
        self::assertStringContainsString('id="inhalt"', $html);
    }

    /**
     * The navigation shows what the account may open (issue #19/M3-6) -
     * filtered on the server, from the rights the guard hands the View. A
     * Kassenprüfer holds no `admin.*` right and never gets this far into
     * /admin; within the area an account with only `admin.settings` does not
     * see the update page.
     */
    public function testTheNavigationListsOnlyWhatTheAccountMayOpen(): void
    {
        $rolle = new Role(1, 'Nur Einstellungen', null, false, [Permission::AdminSettings->value => PermissionScope::Alle]);
        $view = new View(dirname(__DIR__, 2) . '/app/views', '1.2.3');
        $view->setAnmeldung('Test', 'token', new Berechtigungen([$rolle]));

        $html = $view->render('admin/designsystem', ['title' => ''], Area::Admin);

        self::assertStringContainsString('href="/admin/mail"', $html);
        self::assertStringNotContainsString('href="/admin/update"', $html);
        self::assertStringNotContainsString('href="/admin/rollen"', $html);
        self::assertStringNotContainsString('Rollen und Rechte fehlen noch', $html, 'Der Übergangshinweis aus M3-3 ist weg.');
    }

    /** A template must not be able to shadow the frame it renders into. */
    public function testATemplateCannotOverrideTheLayoutVariables(): void
    {
        $html = $this->render(
            'home',
            ['title' => '', 'bereich' => Area::Admin, 'content' => 'geschmuggelt', 'version' => '9.9.9'],
            Area::Oeffentlich,
        );

        self::assertStringContainsString('class="bereich-oeffentlich"', $html);
        self::assertStringNotContainsString('geschmuggelt', $html);
        self::assertStringNotContainsString('9.9.9', $html);
    }
}
