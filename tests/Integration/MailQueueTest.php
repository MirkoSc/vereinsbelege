<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\MailStatus;
use App\Repository\MailQueueRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * The `mail_queue` table against a real server (docs/spec/06-betrieb.md
 * section 3, migrations/005_mail_queue.sql, issue #14): encryption at rest,
 * and the claim pattern that stands in for the `locked_until` column
 * JobRepository has and this table deliberately does not.
 */
final class MailQueueTest extends DatabaseTestCase
{
    private MailQueueRepository $queue;

    private \DateTimeImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->queue = new MailQueueRepository($this->pdo(), new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES)));
        $this->t0 = new \DateTimeImmutable('2026-03-01 12:00:00');
    }

    public function testEnqueueStartsOpenAndDueImmediately(): void
    {
        $id = $this->queue->enqueue('empfaenger@example.org', 'Betreff', 'Text', $this->t0);

        $mail = $this->queue->find($id);
        self::assertNotNull($mail);
        self::assertSame('empfaenger@example.org', $mail->to);
        self::assertSame('Betreff', $mail->subject);
        self::assertSame('Text', $mail->body);
        self::assertSame(MailStatus::Offen, $mail->status);
        self::assertSame(0, $mail->attempts);
        self::assertEquals($this->t0, $mail->nextTryAt);
    }

    /**
     * The row is plaintext nowhere else, only App\Repository\MailQueueRepository
     * holds the key that can read it (CLAUDE.md section 4).
     */
    public function testStoredRowContainsNoPlaintext(): void
    {
        $this->queue->enqueue('kassier@example.org', 'Erinnerung', 'Bitte pruefen: Rechnung Mustermann', $this->t0);

        $roh = $this->pdo()->query('SELECT to_enc, subject_enc, body_enc FROM mail_queue')->fetch();

        self::assertNotFalse($roh);
        self::assertStringNotContainsString('kassier@example.org', (string) $roh['to_enc']);
        self::assertStringNotContainsString('Erinnerung', (string) $roh['subject_enc']);
        self::assertStringNotContainsString('Mustermann', (string) $roh['body_enc']);
    }

    public function testClaimByIdLocksTheRow(): void
    {
        $id = $this->queue->enqueue('a@example.org', 's', 'b', $this->t0);

        $mail = $this->queue->claimById($id, $this->t0);

        self::assertNotNull($mail);
        self::assertSame(MailStatus::Laeuft, $mail->status);
        self::assertSame(1, $mail->attempts);
    }

    /** The provisional lock a claim sets stands in for `locked_until` (migrations/005_mail_queue.sql). */
    public function testSecondClaimWhileInFlightGetsNothing(): void
    {
        $id = $this->queue->enqueue('a@example.org', 's', 'b', $this->t0);
        self::assertNotNull($this->queue->claimById($id, $this->t0));

        self::assertNull($this->queue->claimById($id, $this->t0->modify('+10 seconds')));
        self::assertSame([], $this->queue->claimDue($this->t0->modify('+10 seconds'), 10));
    }

    /** A crashed send must not park the mail forever. */
    public function testExpiredInFlightLockIsTakenOverByClaimDue(): void
    {
        $id = $this->queue->enqueue('a@example.org', 's', 'b', $this->t0);
        $this->queue->claimById($id, $this->t0);

        $faellig = $this->queue->claimDue($this->t0->modify('+301 seconds'), 10);

        self::assertCount(1, $faellig);
        self::assertSame($id, $faellig[0]->id);
        self::assertSame(2, $faellig[0]->attempts);
    }

    public function testClaimDueOrdersByAgeAndRespectsTheLimit(): void
    {
        $erster = $this->queue->enqueue('a@example.org', 's', 'b', $this->t0);
        $zweiter = $this->queue->enqueue('b@example.org', 's', 'b', $this->t0);
        $this->queue->enqueue('c@example.org', 's', 'b', $this->t0);

        $faellig = $this->queue->claimDue($this->t0, 2);

        self::assertSame([$erster, $zweiter], array_map(static fn($m): int => $m->id, $faellig));
    }

    public function testMarkSentClearsTheError(): void
    {
        $id = $this->queue->enqueue('a@example.org', 's', 'b', $this->t0);
        $this->queue->claimById($id, $this->t0);
        $this->queue->markFailed($id, \RuntimeException::class, MailStatus::Offen, $this->t0->modify('+60 seconds'), $this->t0);

        $this->queue->markSent($id, $this->t0->modify('+70 seconds'));

        $mail = $this->queue->find($id);
        self::assertSame(MailStatus::Gesendet, $mail?->status);
        self::assertNull($mail?->lastError);
    }

    /**
     * Takes the exception CLASS, not its message - a database or SMTP
     * message can quote the recipient or the credentials (CLAUDE.md section 4).
     */
    public function testMarkFailedStoresOnlyTheErrorClass(): void
    {
        $id = $this->queue->enqueue('a@example.org', 's', 'b', $this->t0);
        $this->queue->claimById($id, $this->t0);

        $this->queue->markFailed(
            $id,
            \RuntimeException::class,
            MailStatus::Offen,
            $this->t0->modify('+60 seconds'),
            $this->t0,
        );

        $mail = $this->queue->find($id);
        self::assertSame('RuntimeException', $mail?->lastError);
        self::assertSame(MailStatus::Offen, $mail?->status);
        self::assertEquals($this->t0->modify('+60 seconds'), $mail?->nextTryAt);
    }

    /** After the final attempt the row moves to `fehler` and stops being claimed. */
    public function testFinalFailureMovesToFehlerAndStopsRetrying(): void
    {
        $id = $this->queue->enqueue('a@example.org', 's', 'b', $this->t0);
        $this->queue->claimById($id, $this->t0);

        $this->queue->markFailed($id, \RuntimeException::class, MailStatus::Fehler, $this->t0, $this->t0);

        self::assertSame(MailStatus::Fehler, $this->queue->find($id)?->status);
        self::assertSame([], $this->queue->claimDue($this->t0->modify('+1 day'), 10));
    }

    public function testDeleteSentBeforeOnlyRemovesOldSentMails(): void
    {
        $altGesendet = $this->queue->enqueue('a@example.org', 's', 'b', $this->t0);
        $this->queue->markSent($altGesendet, $this->t0);
        $altFehler = $this->queue->enqueue('b@example.org', 's', 'b', $this->t0);
        $this->queue->claimById($altFehler, $this->t0);
        $this->queue->markFailed($altFehler, \RuntimeException::class, MailStatus::Fehler, $this->t0, $this->t0);
        $neuGesendet = $this->queue->enqueue('c@example.org', 's', 'b', $this->t0->modify('+2 days'));
        $this->queue->markSent($neuGesendet, $this->t0->modify('+2 days'));

        $geloescht = $this->queue->deleteSentBefore($this->t0->modify('+1 day'));

        self::assertSame(1, $geloescht);
        self::assertNull($this->queue->find($altGesendet));
        self::assertNotNull($this->queue->find($altFehler), 'failed mails stay for a human to look at');
        self::assertNotNull($this->queue->find($neuGesendet));
    }

    public function testRecentReturnsNewestFirst(): void
    {
        $erster = $this->queue->enqueue('a@example.org', 's', 'b', $this->t0);
        $zweiter = $this->queue->enqueue('b@example.org', 's', 'b', $this->t0->modify('+1 minute'));

        $liste = $this->queue->recent(10);

        self::assertSame([$zweiter, $erster], array_map(static fn($m): int => $m->id, $liste));
    }
}
