<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Mail\MailException;
use App\Service\Mail\MailMessage;
use App\Service\Mail\MailSettings;
use App\Service\Mail\MailTransport;

/**
 * A MailTransport double that never opens a socket or calls mail() -
 * App\Service\Mail\SmtpConnection is the real seam, this is the level above
 * it. Shared by tests/Integration/MailerTest.php and
 * tests/Integration/TwoFactorFlowTest.php (issue #17), both of which only
 * care about the queue/backoff bookkeeping and the rendered mail body
 * around a transport, never about an actual connection.
 */
final class FakeMailTransport implements MailTransport
{
    /** @var list<MailMessage> */
    public array $gesendete = [];

    /**
     * @param list<bool> $ergebnisse true = succeeds, false = throws
     */
    public function __construct(private array $ergebnisse)
    {
    }

    public function send(MailMessage $mail, MailSettings $settings): void
    {
        $this->gesendete[] = $mail;
        if (!array_shift($this->ergebnisse)) {
            throw new MailException('Testfehler.');
        }
    }
}
