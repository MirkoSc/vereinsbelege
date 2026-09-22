<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\MailStatus;
use App\Domain\QueuedMail;
use App\Repository\MailQueueRepository;

/**
 * Facade used by both the admin controller (the test mail, an immediate
 * attempt) and the cron task (queued retries) - CLAUDE.md section 6a: the
 * same service class runs inside a request and inside the cron, with no
 * dependency on Http or Session.
 *
 * Spec 06 section 3: every mail gets a first attempt synchronously, in the
 * request that enqueued it - a security mail (2FA code, reset, from M3-3 on)
 * needs its error shown to the user right away, not five minutes later in a
 * cron log line the user never sees.
 */
final readonly class Mailer
{
    /** After the 5th failed attempt a mail stops retrying (docs/spec/06-betrieb.md section 3). */
    public const int MAX_ATTEMPTS = 5;

    /** Exponential backoff in seconds; index 0 applies after the 1st failure. */
    private const array BACKOFF_SECONDS = [60, 300, 900, 3600, 10800];

    public function __construct(
        private MailQueueRepository $queue,
        private MailSettingsRepository $settingsRepo,
        private MailTemplates $templates,
        /**
         * Test seam: production leaves this null and gets the transport
         * matching $settings->transport (transport(), below); tests inject a
         * fake MailTransport and never open a socket or call mail().
         */
        private ?MailTransport $transport = null,
    ) {
    }

    /**
     * Queues a purely technical test mail and attempts it right away, the
     * same way any other mail would be (see class docblock).
     */
    public function sendeTestmail(string $empfaenger, ?\DateTimeImmutable $now = null): MailAttemptResult
    {
        $now ??= new \DateTimeImmutable();
        $settings = $this->settingsRepo->get();
        $body = $this->templates->render('testmail', [
            'vereinsname' => $settings->vereinsname !== '' ? $settings->vereinsname : 'Vereinsbelege',
            'zeit' => $now,
        ]);
        $id = $this->queue->enqueue($empfaenger, 'Testmail Vereinsbelege', $body, $now);

        $mail = $this->queue->claimById($id, $now);
        if ($mail === null) {
            // Cannot happen for a row nobody else could have touched yet,
            // but every claim can in principle miss, and attempt() needs a
            // QueuedMail to work with.
            return new MailAttemptResult(false, 'Die Mail konnte nicht aus der Warteschlange geholt werden.');
        }

        return $this->attempt($mail, $settings, $now);
    }

    /**
     * Cron entry point (App\Service\Cron\MailQueueTask): works through
     * everything due right now, one attempt each.
     */
    public function sendeFaellige(\DateTimeImmutable $now, int $limit = 10): int
    {
        $settings = $this->settingsRepo->get();
        $erledigt = 0;
        foreach ($this->queue->claimDue($now, $limit) as $mail) {
            if ($this->attempt($mail, $settings, $now)->erfolg) {
                $erledigt++;
            }
        }

        return $erledigt;
    }

    private function attempt(QueuedMail $mail, MailSettings $settings, \DateTimeImmutable $now): MailAttemptResult
    {
        try {
            if (!$settings->istVollstaendig()) {
                throw new MailException('Die Mail-Einstellungen sind unvollständig.');
            }

            $this->transport($settings)->send(new MailMessage($mail->to, $mail->subject, $mail->body), $settings);
            $this->queue->markSent($mail->id, $now);

            return new MailAttemptResult(true);
        } catch (\Throwable $e) {
            $this->giveUpOrRetry($mail, $e::class, $now);

            // MailException messages are built to be safe to show (see that
            // class); anything else - a stray \Throwable from deep inside a
            // transport - is reported by class name only, like everywhere
            // else in this codebase that logs a failure (CLAUDE.md section 4).
            $meldung = $e instanceof MailException ? $e->getMessage() : $e::class;

            return new MailAttemptResult(false, $meldung);
        }
    }

    private function giveUpOrRetry(QueuedMail $mail, string $errorClass, \DateTimeImmutable $now): void
    {
        $endgueltig = $mail->attempts >= self::MAX_ATTEMPTS;
        $wartesekunden = self::BACKOFF_SECONDS[min($mail->attempts, count(self::BACKOFF_SECONDS)) - 1];

        $this->queue->markFailed(
            $mail->id,
            $errorClass,
            $endgueltig ? MailStatus::Fehler : MailStatus::Offen,
            $now->modify(sprintf('+%d seconds', $wartesekunden)),
            $now,
        );
    }

    private function transport(MailSettings $settings): MailTransport
    {
        return $this->transport ?? ($settings->transport === 'php_mail' ? new PhpMailTransport() : new SmtpTransport());
    }
}
