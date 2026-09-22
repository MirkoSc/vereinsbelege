<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One decrypted row of the `mail_queue` table. Decryption happens in
 * App\Repository\MailQueueRepository, which is the only place holding a
 * ServerCrypto instance - this class itself stays a plain value holder, like
 * App\Domain\Job.
 */
final readonly class QueuedMail
{
    public function __construct(
        public int $id,
        public string $to,
        public string $subject,
        public string $body,
        public MailStatus $status,
        public int $attempts,
        public \DateTimeImmutable $nextTryAt,
        public ?string $lastError,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
