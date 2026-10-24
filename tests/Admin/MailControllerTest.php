<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\MailController;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Repository\AuditLogRepository;
use App\Repository\MailQueueRepository;
use App\Repository\SettingRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * /admin/mail keeps what was typed when validation fails (issue #155) - the
 * password excepted.
 */
final class MailControllerTest extends DatabaseTestCase
{
    private MailController $controller;

    private MailSettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $_SESSION = ['csrf' => 'token'];

        $serverKey = new ServerCrypto(str_repeat('t', ServerCrypto::KEY_BYTES));
        $this->settings = new MailSettingsRepository(new SettingRepository($this->pdo()), $serverKey);
        $queue = new MailQueueRepository($this->pdo(), $serverKey);
        $view = new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test');
        $view->setCurrentPath('/admin/mail/einstellungen');

        $this->controller = new MailController(
            $view,
            new Session(),
            $this->settings,
            new Mailer($queue, $this->settings, new MailTemplates(dirname(__DIR__, 2) . '/app/views/mail')),
            $queue,
            new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $serverKey),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testAValidationErrorKeepsTheTypedInputButNeverThePassword(): void
    {
        $antwort = $this->controller->save(new Request(HttpMethod::Post, '/admin/mail/einstellungen', post: [
            '_csrf' => 'token',
            'transport' => 'smtp',
            'host' => 'mail.example.test',
            'port' => '2525',
            'sicherheit' => 'implizit',
            'benutzer' => 'versand',
            'passwort' => 'geheim-1234',
            'passwort_loeschen' => '1',
            'absender' => 'verein@example.test',
            'vereinsname' => 'Beispielverein',
            'oeffentliche_url' => 'http://belege.example.test',
        ]));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('https://', $antwort->body);
        self::assertStringContainsString('value="mail.example.test"', $antwort->body);
        self::assertStringContainsString('value="2525"', $antwort->body);
        self::assertStringContainsString('value="versand"', $antwort->body);
        self::assertStringContainsString('value="verein@example.test"', $antwort->body);
        self::assertStringContainsString('value="Beispielverein"', $antwort->body);
        self::assertStringContainsString('value="http://belege.example.test"', $antwort->body);
        self::assertStringContainsString('name="passwort_loeschen" value="1" checked', $antwort->body);
        self::assertStringNotContainsString('geheim-1234', $antwort->body);
        // Nothing was saved.
        self::assertSame('', $this->settings->get()->host);
    }
}
