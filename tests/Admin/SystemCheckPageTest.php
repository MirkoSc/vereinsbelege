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

    private function seite(?SystemCheck $check = null): string
    {
        $view = new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test');
        $view->setCurrentPath(self::PFAD);
        $controller = new SystemCheckController($view, new Session(), $check ?? new SystemCheck());

        $antwort = $controller->page(new Request(HttpMethod::Get, self::PFAD));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(200, $antwort->status);

        return $antwort->body;
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
