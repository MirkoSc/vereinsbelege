<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\WorkerController;
use App\Domain\BlobMeta;
use App\Domain\DocumentSource;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\WorkerFaehigkeit;
use App\Domain\WorkerStatus;
use App\Repository\DocumentArtifactRepository;
use App\Service\Account\SessionVault;
use App\Service\Crypto\DataKey;
use App\Service\Upload\MagicBytes;
use App\Service\Worker\WorkerRouting;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Session;
use App\Tests\Service\Worker\WorkerTestCase;
use App\View\View;

/**
 * The admin page "Worker": pairing (issue #51/M7b-1: the code is shown
 * once, the fingerprint must be confirmed explicitly, unpairing locks the
 * worker out) and, since issue #53, the module switch, the fallback and
 * the status view.
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
        $this->controller = new WorkerController(
            $view,
            new Session(),
            $this->workers,
            $this->kopplung,
            $this->routing,
            $this->uebergabe,
            $this->fallback,
            $this->jobs,
            new DocumentArtifactRepository($this->pdo()),
            $this->settings,
            new SessionVault(),
            $this->mailEingang,
            new \App\Service\Audit\AuditLog(new \App\Repository\AuditLogRepository($this->pdo()), new \App\Repository\VaultRepository($this->pdo()), $this->crypto),
        );
    }

    public function testTheMailIntakeKeepsThePasswordHiddenAndEncrypted(): void
    {
        $felder = [
            'aktiv' => '1',
            'host' => 'imap.verein.de',
            'sicherheit' => 'ssl',
            'port' => '993',
            'benutzer' => 'belege@verein.de',
            'passwort' => 'Sehr-Geheim-1',
            'ordner' => 'INBOX',
            'absender' => '@stadtwerke.de',
        ];
        $antwort = $this->controller->mailEingang($this->post('/admin/worker/mail-eingang', $felder));
        self::assertSame(302, $antwort->status);
        self::assertTrue($this->mailEingang->laden()->aktiv);

        $seite = $this->controller->page(new Request(HttpMethod::Get, '/admin/worker'))->body;
        self::assertStringContainsString('imap.verein.de', $seite);
        self::assertStringNotContainsString('Sehr-Geheim-1', $seite, 'the password is never shown again');
        $gespeichert = implode('|', $this->pdo()->query("SELECT value FROM setting WHERE name LIKE 'mail_eingang%'")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertStringNotContainsString('Sehr-Geheim-1', $gespeichert);
        self::assertStringNotContainsString('stadtwerke', $gespeichert);
        $audit = implode('|', $this->pdo()->query('SELECT action FROM audit_log')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertStringContainsString('einstellung.mail_eingang', $audit);

        // An empty password field keeps the stored one; a plaintext option does not exist.
        $this->controller->mailEingang($this->post('/admin/worker/mail-eingang', ['passwort' => ''] + $felder));
        self::assertSame('Sehr-Geheim-1', $this->mailEingang->laden()->passwort);
        $this->controller->mailEingang($this->post('/admin/worker/mail-eingang', ['sicherheit' => 'keine', 'host' => 'anders.example'] + $felder));
        self::assertSame('imap.verein.de', $this->mailEingang->laden()->host, 'refused, nothing stored');
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

    public function testUnpairingSendsTheWorkersJobsBackAtOnce(): void
    {
        [$id] = $this->koppeln();
        $job = $this->jobs->enqueue('render_pages', JobExecutor::Worker, 'document', $this->dokument());

        $this->controller->entkoppeln($this->post('/admin/worker/' . $id . '/entkoppeln'), ['id' => (string) $id]);

        self::assertSame(JobExecutor::Browser, $this->jobs->find($job)?->executor);
    }

    public function testSwitchingOnNeedsAnUnlockedVaultSwitchingOffFallsBack(): void
    {
        $this->settings->set(WorkerRouting::SETTING_AKTIV, '0');
        // No vault in this session: refused, nothing stored.
        $this->controller->einstellungen($this->post('/admin/worker/einstellungen', ['aktiv' => '1', 'fallback_minuten' => '90']));
        self::assertFalse($this->routing->modulAktiv());

        // Already on (set elsewhere): saving the period needs no vault.
        $this->settings->set(WorkerRouting::SETTING_AKTIV, '1');
        $this->controller->einstellungen($this->post('/admin/worker/einstellungen', ['aktiv' => '1', 'fallback_minuten' => '90']));
        self::assertSame(90, $this->routing->fallbackMinuten());
        $this->controller->einstellungen($this->post('/admin/worker/einstellungen', ['aktiv' => '1', 'fallback_minuten' => '2']));
        self::assertSame(90, $this->routing->fallbackMinuten(), 'Out of range: refused.');

        [$id] = $this->koppeln();
        $this->workers->heartbeat($id, 1, '1.0.0', WorkerFaehigkeit::cases(), new \DateTimeImmutable());
        $job = $this->jobs->enqueue('ai_extract', JobExecutor::Worker, 'document', $this->dokument());
        $seite = $this->controller->page(new Request(HttpMethod::Get, '/admin/worker'))->body;
        self::assertStringContainsString('online', $seite);
        self::assertStringContainsString('KI-Auslesen', $seite, 'The queue shows the waiting job.');

        $this->controller->einstellungen($this->post('/admin/worker/einstellungen', ['fallback_minuten' => '90']));
        self::assertFalse($this->routing->modulAktiv());
        self::assertSame([JobExecutor::Session, JobStatus::Offen], [$this->jobs->find($job)?->executor, $this->jobs->find($job)?->status]);
    }

    public function testOcrCatchUpNeedsAnUnlockedVault(): void
    {
        $antwort = $this->controller->ocrNachholen($this->post('/admin/worker/ocr-nachholen'));

        self::assertSame(302, $antwort->status);
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM job WHERE typ = 'ocr'")->fetchColumn());
    }

    private function dokument(): int
    {
        $blob = $this->blobService->storeString('Bild', new BlobMeta(MagicBytes::JPEG), $this->vault);

        return $this->documents->insert(DocumentSource::Intern, null, [$blob->id], $this->vault->sealDataKey(DataKey::generate()), new \DateTimeImmutable());
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
