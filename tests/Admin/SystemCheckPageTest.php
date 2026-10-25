<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\SystemCheckController;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Service\SystemCheck\SystemCheck;
use App\View\View;
use PHPUnit\Framework\TestCase;

/**
 * The system check page (M3-10, issue #107): what an admin sees. The access
 * rule itself - `admin.system`, nothing else - is pinned for every role by
 * RoutePermissionMatrixTest, which runs the real route table.
 *
 * No database: the controller gets a SystemCheck without a PDO, so the
 * wait_timeout line is left out here (it is covered against a real server
 * where one is configured, see SystemCheckWaitTimeoutTest).
 */
final class SystemCheckPageTest extends TestCase
{
    private const string PFAD = '/admin/systemcheck';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function seite(?SystemCheck $check = null, ?string $cronToken = null): string
    {
        return $this->antwort($check, $cronToken)->body;
    }

    private function antwort(?SystemCheck $check = null, ?string $cronToken = null): Response
    {
        $view = new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test');
        $view->setCurrentPath(self::PFAD);
        $controller = new SystemCheckController($view, new Session(), $check ?? new SystemCheck(), $cronToken);

        $antwort = $controller->page(new Request(HttpMethod::Get, self::PFAD, headers: ['host' => 'verein.example']));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(200, $antwort->status);

        return $antwort;
    }

    /**
     * Issue #244: the admin sees the cron URL with the token of
     * shared/config.php - after a restore that is a new one - without FTP.
     * It is not one of the findings, so it never ends up in the JSON that
     * is meant to be pasted into an issue, and the page is not cached.
     * Who may open the page at all (`admin.system`) is
     * RoutePermissionMatrixTest's job.
     */
    public function testTheCronUrlIsShownButNotPartOfTheCopyableReport(): void
    {
        $token = str_repeat('ab12', 12);
        $antwort = $this->antwort(cronToken: $token);
        $html = $antwort->body;

        self::assertStringContainsString('<h3>Cron-URL</h3>', $html);
        self::assertStringContainsString(
            'id="systemcheck-cron-url" readonly spellcheck="false" autocomplete="off" value="http://verein.example/cron?token=' . $token . '"',
            $html,
        );
        self::assertSame('no-store', $antwort->headers['Cache-Control'] ?? null);

        self::assertSame(1, preg_match('#<textarea id="systemcheck-json"[^>]*>(.*?)</textarea>#s', $html, $treffer));
        self::assertStringNotContainsString($token, $treffer[1], 'the copyable JSON stays free of the token');
        self::assertDoesNotMatchRegularExpression('#\son[a-z]+\s*=#i', $html, 'no inline handler (CSP)');
    }

    public function testWithoutATokenThereIsNoCronSection(): void
    {
        self::assertStringNotContainsString('Cron-URL', $this->seite());
    }

    public function testThePageListsEveryCheckWithExpectationActualValueAndStatus(): void
    {
        $html = $this->seite();

        self::assertStringContainsString('<h2>Systemcheck</h2>', $html);
        foreach (new SystemCheck()->all() as $ergebnis) {
            self::assertStringContainsString(e($ergebnis->label), $html, $ergebnis->key);
            self::assertStringContainsString(e($ergebnis->expected), $html, $ergebnis->key);
        }
        self::assertStringContainsString('Funktionsargumente aus Stacktraces', $html, 'issue #97');
        self::assertStringContainsString('<th scope="col">Erwartung</th>', $html);
        self::assertStringContainsString('<th scope="col">Ist-Wert</th>', $html);
    }

    /**
     * 360 px: every cell names its column itself, because the header row is
     * hidden when the table turns into cards (public/css/app.css).
     */
    public function testEveryCellCarriesItsColumnNameForTheCardLayout(): void
    {
        $html = $this->seite();

        self::assertStringContainsString('class="tabelle tabelle-karten"', $html);
        $zeilen = count(new SystemCheck()->all());
        foreach (['Prüfpunkt', 'Erwartung', 'Ist-Wert', 'Status', 'Erläuterung'] as $spalte) {
            self::assertSame($zeilen, substr_count($html, 'data-label="' . $spalte . '"'), $spalte);
        }
    }

    public function testTheOverallStatusIsTheWorstSingleStatus(): void
    {
        $vorher = ini_set('zend.exception_ignore_args', '0');

        try {
            $html = $this->seite();

            self::assertStringContainsString('Gesamtstatus: Fehler', $html);
            self::assertMatchesRegularExpression('#class="hinweis hinweis-fehler" id="systemcheck-gesamt"#', $html);
            self::assertStringContainsString('marke-fehler', $html);
        } finally {
            ini_set('zend.exception_ignore_args', $vorher === false ? '1' : $vorher);
        }
    }

    /**
     * Issue #101: the page asks the own domain for shared/config.php - built
     * from the Host header of the request - and a served file is an error.
     */
    public function testAServedConfigShowsUpAsAnErrorForTheOwnDomain(): void
    {
        $gefragt = [];
        $probe = static function (string $url) use (&$gefragt): ?int {
            $gefragt[] = $url;

            return 200;
        };
        $view = new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test');
        $view->setCurrentPath(self::PFAD);
        $controller = new SystemCheckController($view, new Session(), new SystemCheck(null, null, $probe));

        $antwort = $controller->page(new Request(HttpMethod::Get, self::PFAD, headers: ['host' => 'belege.example']));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(['http://belege.example/shared/config.php'], $gefragt);
        self::assertStringContainsString('shared/config.php von außen gesperrt', $antwort->body);
        self::assertStringContainsString('abrufbar (HTTP 200)', $antwort->body);
        self::assertStringContainsString('Gesamtstatus: Fehler', $antwort->body);
    }

    /**
     * A Host header that is not a host name never becomes part of the URL.
     */
    public function testAForgedHostHeaderFallsBackToLocalhost(): void
    {
        $gefragt = [];
        $probe = static function (string $url) use (&$gefragt): ?int {
            $gefragt[] = $url;

            return 404;
        };
        $view = new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test');
        $view->setCurrentPath(self::PFAD);
        $controller = new SystemCheckController($view, new Session(), new SystemCheck(null, null, $probe));

        $controller->page(new Request(HttpMethod::Get, self::PFAD, headers: ['host' => 'evil.example/x?']));

        self::assertSame(['http://localhost/shared/config.php'], $gefragt);
    }

    public function testTheResultIsOfferedAsJsonToCopy(): void
    {
        $html = $this->seite();

        self::assertSame(1, preg_match('#<textarea id="systemcheck-json"[^>]*readonly[^>]*>(.*?)</textarea>#s', $html, $treffer));
        $json = json_decode(html_entity_decode($treffer[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);

        self::assertContains($json['status'], ['ok', 'warn', 'fail']);
        self::assertContains('zend.exception_ignore_args', array_column($json['checks'], 'key'));
    }

    /**
     * No inline script and no handler attribute (script-src 'self', CLAUDE.md
     * section 4) - the copy button comes from an external file.
     */
    public function testTheCopyButtonIsWiredByAnExternalScript(): void
    {
        $html = $this->seite();

        self::assertStringContainsString('<script src="/js/systemcheck.js?v=0.0.0-test" defer></script>', $html);
        self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)#', $html);
        self::assertDoesNotMatchRegularExpression('#\son[a-z]+\s*=#i', $html);
        self::assertStringContainsString('id="systemcheck-kopieren" class="knopf" hidden', $html, 'shown by the script only');
    }

    /**
     * The var directory is probed for real, but its path is a server detail
     * and stays out of the table and the JSON - like the secrets of
     * config.php, which the page never gets to see at all.
     */
    public function testThePageStaysFreeOfServerPathsAndTokens(): void
    {
        $dir = sys_get_temp_dir() . '/systemcheck-seite-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            $html = $this->seite(new SystemCheck(null, $dir));

            self::assertStringContainsString('Schreibrechte auf shared/var/', $html);
            self::assertStringNotContainsString($dir, $html);
            self::assertStringNotContainsString('config.php', $html);
            self::assertStringNotContainsString('cron_token', $html);
            self::assertSame([], array_diff(scandir($dir) ?: [], ['.', '..']), 'the probe leaves nothing behind');
        } finally {
            @rmdir($dir);
        }
    }
}
