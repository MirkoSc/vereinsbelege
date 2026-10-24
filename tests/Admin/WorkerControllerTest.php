<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\WorkerController;
use App\Domain\WorkerStatus;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Session;
use App\Tests\Service\Worker\WorkerTestCase;
use App\View\View;

/**
 * The pairing part of the admin page "Worker" (issue #51/M7b-1): the code
 * is shown once, the fingerprint must be confirmed explicitly, unpairing
 * locks the worker out.
 */
final class WorkerControllerTest extends WorkerTestCase
{
    private WorkerController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Session()->start();
        $view = new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test');
        $view->setCurrentPath('/admin/worker');
        $this->controller = new WorkerController($view, new Session(), $this->workers, $this->kopplung);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testTheCodeIsShownOnceAndNotStoredInTheSession(): void
    {
        $antwort = $this->controller->koppeln($this->post('/admin/worker/koppeln'));

        self::assertSame(200, $antwort->status);
        self::assertMatchesRegularExpression('/<code>[0-9A-Z]{4}-[0-9A-Z]{4}<\/code>/', $antwort->body);
        self::assertStringContainsString('docker compose run --rm worker pair http://belege.example.test ', $antwort->body);
        preg_match('/<code>([0-9A-Z]{4}-[0-9A-Z]{4})<\/code>/', $antwort->body, $treffer);
        self::assertStringNotContainsString($treffer[1], serialize($_SESSION));

        $seite = $this->controller->page(new Request(HttpMethod::Get, '/admin/worker'));
        self::assertStringNotContainsString($treffer[1], $seite->body);
        self::assertStringContainsString('Ein Kopplungscode ist bis', $seite->body);
    }

    public function testConfirmingNeedsTheTickAndShowsTheFingerprint(): void
    {
        [$id] = $this->koppeln(bestaetigen: false);
        $worker = $this->workers->find($id);
        self::assertNotNull($worker);

        $seite = $this->controller->page(new Request(HttpMethod::Get, '/admin/worker'));
        self::assertStringContainsString(substr($worker->fingerprint, 0, 4) . ' ' . substr($worker->fingerprint, 4, 4), $seite->body);
        self::assertStringContainsString('Fingerabdruck bestätigen', $seite->body);

        $this->controller->bestaetigen($this->post('/admin/worker/' . $id . '/bestaetigen'), ['id' => (string) $id]);
        self::assertSame(WorkerStatus::Gekoppelt, $this->workers->find($id)?->status);

        $this->controller->bestaetigen($this->post('/admin/worker/' . $id . '/bestaetigen', ['verglichen' => '1']), ['id' => (string) $id]);
        self::assertSame(WorkerStatus::Aktiv, $this->workers->find($id)?->status);
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        [$id] = $this->koppeln();

        $antwort = $this->controller->entkoppeln(new Request(HttpMethod::Post, '/admin/worker/' . $id . '/entkoppeln', post: ['_csrf' => 'falsch']), ['id' => (string) $id]);

        self::assertSame(302, $antwort->status);
        self::assertSame(WorkerStatus::Aktiv, $this->workers->find($id)?->status);
    }

    public function testUnpairing(): void
    {
        [$id] = $this->koppeln();

        $this->controller->entkoppeln($this->post('/admin/worker/' . $id . '/entkoppeln'), ['id' => (string) $id]);

        self::assertSame(WorkerStatus::Gesperrt, $this->workers->find($id)?->status);
        self::assertStringContainsString('nicht gekoppelt', $this->controller->page(new Request(HttpMethod::Get, '/admin/worker'))->body);
    }

    /**
     * @param array<string, string> $felder
     */
    private function post(string $pfad, array $felder = []): Request
    {
        return new Request(
            HttpMethod::Post,
            $pfad,
            post: ['_csrf' => new Session()->csrfToken()] + $felder,
            headers: ['host' => 'belege.example.test'],
        );
    }
}
