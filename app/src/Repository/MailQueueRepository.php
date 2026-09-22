<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MailStatus;
use App\Domain\QueuedMail;
use App\Service\Crypto\ServerCrypto;

/**
 * The `mail_queue` table (docs/spec/06-betrieb.md section 3,
 * docs/spec/02-datenmodell.md). SQL lives in repositories only, prepared
 * statements only.
 *
 * There is no separate lock column - see migrations/005_mail_queue.sql. A
 * claim is the same optimistic pattern as JobRepository::claim(): a
 * conditional UPDATE whose row count says who won, deliberately not
 * SELECT ... FOR UPDATE (issue #98).
 *
 * Recipient, subject and body are encrypted with the server key
 * (App\Service\Crypto\ServerCrypto) so that both a request and the cron -
 * neither of which has a vault - can read a row (CLAUDE.md section 4).
 */
final readonly class MailQueueRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * How long a claimed row is provisionally protected from being claimed
     * again before markSent()/markFailed() write the real outcome. Only
     * matters if the process dies between claim and mark; long enough that a
     * live SMTP conversation (SmtpTransport's own 15 s timeout) always
     * finishes first, short enough that a crash does not park the mail for
     * good.
     */
    private const int IN_FLIGHT_SECONDS = 300;

    public function __construct(
        private \PDO $pdo,
        private ServerCrypto $crypto,
    ) {
    }

    public function enqueue(string $to, string $subject, string $body, ?\DateTimeImmutable $now = null): int
    {
        $zeit = ($now ?? new \DateTimeImmutable())->format(self::FORMAT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_queue (to_enc, subject_enc, body_enc, status, next_try_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $this->crypto->encrypt($to),
            $this->crypto->encrypt($subject),
            $this->crypto->encrypt($body),
            MailStatus::Offen->value,
            $zeit,
            $zeit,
            $zeit,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?QueuedMail
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mail_queue WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->fromRow($row);
    }

    /**
     * Claims one specific row - used for the immediate attempt right after
     * enqueue(), which needs exactly that mail and not merely "something
     * due" (App\Service\Mail\Mailer::sendeTestmail()).
     */
    public function claimById(int $id, \DateTimeImmutable $now): ?QueuedMail
    {
        return $this->attemptClaim($id, $now) ? $this->find($id) : null;
    }

    /**
     * Hands out due mails (`offen`, or `laeuft` whose provisional lock has
     * expired) and locks each one, oldest first.
     *
     * @return list<QueuedMail>
     */
    public function claimDue(\DateTimeImmutable $now, int $limit): array
    {
        $jetzt = $now->format(self::FORMAT);
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT id FROM mail_queue WHERE status IN (?, ?) AND next_try_at <= ? ORDER BY id LIMIT %d',
            max(1, $limit),
        ));
        $stmt->execute([MailStatus::Offen->value, MailStatus::Laeuft->value, $jetzt]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $ergebnis = [];
        foreach ($ids as $id) {
            if ($this->attemptClaim((int) $id, $now)) {
                $mail = $this->find((int) $id);
                if ($mail !== null) {
                    $ergebnis[] = $mail;
                }
            }
        }

        return $ergebnis;
    }

    public function markSent(int $id, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mail_queue SET status = ?, last_error = NULL, updated_at = ? WHERE id = ?',
        );
        $stmt->execute([
            MailStatus::Gesendet->value,
            ($now ?? new \DateTimeImmutable())->format(self::FORMAT),
            $id,
        ]);
    }

    /**
     * Records a failed attempt. Takes the exception CLASS, not its message,
     * like JobRepository::fail() - a database or SMTP message can quote the
     * recipient or the credentials. The caller (Mailer) decides whether this
     * was the last attempt.
     */
    public function markFailed(
        int $id,
        string $errorClass,
        MailStatus $status,
        \DateTimeImmutable $naechsterVersuch,
        ?\DateTimeImmutable $now = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE mail_queue SET status = ?, last_error = ?, next_try_at = ?, updated_at = ? WHERE id = ?',
        );
        $stmt->execute([
            $status->value,
            mb_substr($errorClass, 0, 255),
            $naechsterVersuch->format(self::FORMAT),
            ($now ?? new \DateTimeImmutable())->format(self::FORMAT),
            $id,
        ]);
    }

    /**
     * Newest first, for the admin queue view (masked recipients, spec 06
     * section 3).
     *
     * @return list<QueuedMail>
     */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM mail_queue ORDER BY id DESC LIMIT %d', max(1, $limit)));
        $stmt->execute();

        return array_map($this->fromRow(...), $stmt->fetchAll());
    }

    /**
     * Housekeeping for the cron (App\Service\Cron\MailCleanupTask): removes
     * sent mails after a week. `fehler` rows stay - the admin queue is where
     * a human finds out mail is not going out.
     */
    public function deleteSentBefore(\DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM mail_queue WHERE status = ? AND updated_at < ?');
        $stmt->execute([MailStatus::Gesendet->value, $before->format(self::FORMAT)]);

        return $stmt->rowCount();
    }

    private function attemptClaim(int $id, \DateTimeImmutable $now): bool
    {
        $jetzt = $now->format(self::FORMAT);
        $inFlightBis = $now->modify(sprintf('+%d seconds', self::IN_FLIGHT_SECONDS))->format(self::FORMAT);

        $stmt = $this->pdo->prepare(
            'UPDATE mail_queue
             SET status = ?, attempts = attempts + 1, next_try_at = ?, updated_at = ?
             WHERE id = ? AND status IN (?, ?) AND next_try_at <= ?',
        );
        $stmt->execute([
            MailStatus::Laeuft->value,
            $inFlightBis,
            $jetzt,
            $id,
            MailStatus::Offen->value,
            MailStatus::Laeuft->value,
            $jetzt,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): QueuedMail
    {
        return new QueuedMail(
            id: (int) $row['id'],
            to: $this->crypto->decrypt((string) $row['to_enc']),
            subject: $this->crypto->decrypt((string) $row['subject_enc']),
            body: $this->crypto->decrypt((string) $row['body_enc']),
            status: MailStatus::from((string) $row['status']),
            attempts: (int) $row['attempts'],
            nextTryAt: new \DateTimeImmutable((string) $row['next_try_at']),
            lastError: $row['last_error'] === null ? null : (string) $row['last_error'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
