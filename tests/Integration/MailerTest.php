<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\MailStatus;
use App\Repository\CronLockRepository;
use App\Repository\MailQueueRepository;
use App\Repository\SettingRepository;
use App\Service\Cron\CronRunner;
use App\Service\Cron\MailCleanupTask;
use App\Service\Cron\MailQueueTask;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\MailException;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Mail\MailTransport;
use App\Service\Mail\SmtpSecurity;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeMailTransport;

/**
 * App\Service\Mail\Mailer against a real server: the immediate attempt of
 * spec 06 section 3, the exponential backoff, the fifth failure giving up,
 * and the cron task that shares the same class (CLAUDE.md section 6a).
 *
 * The transport is a fake (App\Tests\Support\FakeMailTransport) - no
 * socket, no network; what this suite exercises is the queue/backoff
 * bookkeeping around a transport, which SmtpTransportTest and MailQueueTest
 * already cover on their own.
 */
final class MailerTest extends DatabaseTestCase
{
    private MailQueueRepository $queue;

    private MailSettingsRepository $settingsRepo;

    private \DateTimeImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $crypto = new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES));
        $this->queue = new MailQueueRepository($this->pdo(), $crypto);
        $this->settingsRepo = new MailSettingsRepository(new SettingRepository($this->pdo()), $crypto);
        $this->settingsRepo->save('smtp', 'smtp.example.org', 587, SmtpSecurity::Starttls, '', null, 'verein@example.org', '', 'Verein');
        $this->t0 = new \DateTimeImmutable('2026-03-01 12:00:00');
    }

    private function mailer(MailTransport $transport): Mailer
    {
        return new Mailer($this->queue, $this->settingsRepo, new MailTemplates(dirname(__DIR__, 2) . '/app/views/mail'), $transport);
    }

    public function testTestmailIsAttemptedImmediatelyAndMarkedSent(): void
    {
        $transport = new FakeMailTransport([true]);

        $ergebnis = $this->mailer($transport)->sendeTestmail('empfaenger@example.org', $this->t0);

        self::assertTrue($ergebnis->erfolg);
        self::assertCount(1, $transport->gesendete);
        self::assertSame('empfaenger@example.org', $transport->gesendete[0]->to);
        self::assertSame(MailStatus::Gesendet, $this->queue->recent(1)[0]->status);
    }

    public function testFailedAttemptKeepsTheMailOpenWithTheFirstBackoffStep(): void
    {
        $ergebnis = $this->mailer(new FakeMailTransport([false]))->sendeTestmail('empfaenger@example.org', $this->t0);

        self::assertFalse($ergebnis->erfolg);
        self::assertNotNull($ergebnis->fehlermeldung);
        $mail = $this->queue->recent(1)[0];
        self::assertSame(MailStatus::Offen, $mail->status);
        self::assertSame(1, $mail->attempts);
        self::assertEquals($this->t0->modify('+60 seconds'), $mail->nextTryAt);
        self::assertSame(MailException::class, $mail->lastError);
    }

    /** Without a sender address there is nothing to try - treated like any other failure. */
    public function testIncompleteSettingsFailTheAttemptWithoutTouchingTheTransport(): void
    {
        $this->settingsRepo->save('smtp', 'smtp.example.org', 587, SmtpSecurity::Starttls, '', null, '', '', '');
        $transport = new FakeMailTransport([true]);

        $ergebnis = $this->mailer($transport)->sendeTestmail('empfaenger@example.org', $this->t0);

        self::assertFalse($ergebnis->erfolg);
        self::assertSame([], $transport->gesendete);
    }

    public function testFifthFailureGivesUpAndStopsRetrying(): void
    {
        $transport = new FakeMailTransport([false, false, false, false, false]);
        $mailer = $this->mailer($transport);
        $id = $this->queue->enqueue('empfaenger@example.org', 'Betreff', 'Text', $this->t0);

        $zeit = $this->t0;
        for ($i = 0; $i < Mailer::MAX_ATTEMPTS; $i++) {
            self::assertSame(0, $mailer->sendeFaellige($zeit, 10));
            $zeit = $this->queue->find($id)?->nextTryAt ?? $zeit;
        }

        $mail = $this->queue->find($id);
        self::assertSame(MailStatus::Fehler, $mail?->status);
        self::assertSame(Mailer::MAX_ATTEMPTS, $mail?->attempts);
        self::assertCount(5, $transport->gesendete);
        // A `fehler` row is never picked up again, however late the cron looks.
        self::assertSame(0, $mailer->sendeFaellige($zeit->modify('+999 days'), 10));
    }

    public function testMailQueueTaskThroughCronRunnerLeaksNoRecipientOrSubject(): void
    {
        $this->queue->enqueue('geheim@example.org', 'Geheimer Betreff', 'Text', $this->t0);
        $task = new MailQueueTask($this->mailer(new FakeMailTransport([true])));
        $runner = new CronRunner(
            new CronLockRepository($this->pdo()),
            new SettingRepository($this->pdo()),
            jedesMal: [$task],
        );

        $ergebnis = $runner->run($this->t0);

        self::assertSame([['name' => 'mail_versenden', 'erledigt' => 1]], $ergebnis->aufgaben);
        $antwort = (string) json_encode($ergebnis->toArray());
        self::assertStringNotContainsString('geheim@example.org', $antwort);
        self::assertStringNotContainsString('Geheimer Betreff', $antwort);
    }

    public function testMailCleanupTaskRemovesOldSentMailsOnly(): void
    {
        $altGesendet = $this->queue->enqueue('a@example.org', 's', 'b', $this->t0);
        $this->queue->markSent($altGesendet, $this->t0);
        $altFehler = $this->queue->enqueue('b@example.org', 's', 'b', $this->t0);
        $this->queue->claimById($altFehler, $this->t0);
        $this->queue->markFailed($altFehler, \RuntimeException::class, MailStatus::Fehler, $this->t0, $this->t0);

        $geloescht = new MailCleanupTask($this->queue)->run($this->t0->modify('+' . (MailCleanupTask::KEEP_DAYS + 1) . ' days'));

        self::assertSame(1, $geloescht);
        self::assertNull($this->queue->find($altGesendet));
        self::assertNotNull($this->queue->find($altFehler), 'failed mails stay for a human to look at');
    }

    // ------------------------------------------------------ M3-4 (issue #17)

    public function testMfaCodeIsAttemptedImmediatelyAndCarriesTheCode(): void
    {
        $transport = new FakeMailTransport([true]);

        $ergebnis = $this->mailer($transport)->sendeMfaCode('empfaenger@example.org', '123456', 10, $this->t0);

        self::assertTrue($ergebnis->erfolg);
        self::assertCount(1, $transport->gesendete);
        self::assertSame('empfaenger@example.org', $transport->gesendete[0]->to);
        self::assertStringContainsString('123456', $transport->gesendete[0]->body);
        self::assertStringContainsString('10', $transport->gesendete[0]->body);
    }

    public function testSicherheitshinweisTraegtDasEreignisAberKeineFachlichenDaten(): void
    {
        $transport = new FakeMailTransport([true]);

        $ergebnis = $this->mailer($transport)->sendeSicherheitshinweis(
            'empfaenger@example.org',
            'Ein neues Gerät wurde gemerkt.',
            $this->t0,
        );

        self::assertTrue($ergebnis->erfolg);
        self::assertStringContainsString('Ein neues Gerät wurde gemerkt.', $transport->gesendete[0]->body);
    }
}
