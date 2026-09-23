<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Service\RateLimiter;

/**
 * The public submission's spam defence without a visible hurdle
 * (docs/spec/01-sicherheit.md section 5, E-14, issue #25/M4-3): rate limit
 * per IP and globally, proof of work, honeypot, minimum fill time, and
 * whether the form is paused. Framework-free like
 * App\Service\Submission\SubmissionService - the caller
 * (App\PublicPages\EinreichungController, App\Api\EinreichungUploadController)
 * has already verified the form token and knows the request's IP.
 *
 * Page size (the per-file limit) and the pause flag are checked here
 * because they gate the request before anything is stored; the page
 * *count* and the submission's *total* size are
 * App\Service\Submission\SubmissionService's own field validation instead -
 * both need the list of already-uploaded blobs, which only exists once a
 * visit is under way, and both are things a person fixes by removing a
 * page, not a bot signal worth a 4xx status of its own.
 */
final readonly class Spamschutz
{
    public const string PURPOSE_SUBMIT_IP = 'einreichung_ip';
    public const string PURPOSE_SUBMIT_GESAMT = 'einreichung_gesamt';
    public const string PURPOSE_UPLOAD_IP = 'einreichung_upload_ip';
    public const string PURPOSE_UPLOAD_GESAMT = 'einreichung_upload_gesamt';

    /** The global counters are not keyed by anything - one shared value stands in for "everybody". */
    private const string GLOBAL_VALUE = 'global';

    /**
     * Below this, filling in name, reimbursement, free text and uploading
     * at least one page cannot have happened - a human did not do this
     * (docs/spec/01-sicherheit.md section 5).
     */
    private const int MINDEST_AUSFUELLDAUER_SEKUNDEN = 5;

    public function __construct(
        private RateLimiter $limits,
        private ProofOfWork $proofOfWork,
        private EinreichungsEinstellungen $einstellungen,
    ) {
    }

    /**
     * Before a single byte of a page is accepted
     * (App\Api\EinreichungUploadController::create()). Every accepted call
     * counts immediately, whether or not the visit ever reaches a finished
     * submission - the budget is upload attempts, not successes.
     */
    public function pruefeUpload(
        string $ip,
        FormTokenData $token,
        ?string $powLoesung,
        int $groesse,
        ?\DateTimeImmutable $now = null,
    ): ?EinreichungAbgelehnt {
        $now ??= new \DateTimeImmutable();

        $gesperrt = $this->pruefeGrundlegend($token, $powLoesung);
        if ($gesperrt !== null) {
            return $gesperrt;
        }

        if ($groesse > $this->einstellungen->maxDateiBytes()) {
            return EinreichungAbgelehnt::DateiZuGross;
        }

        // A stack of receipts is several uploads for one submission - the
        // per-visit budget scales with how many pages a submission may
        // have at all, not with the once-an-hour submission limit itself.
        $limitIp = $this->einstellungen->limitProIpStunde * $this->einstellungen->maxSeiten;
        $limitGesamt = $this->einstellungen->limitGesamtStunde * $this->einstellungen->maxSeiten;

        if ($this->limits->isBlocked(self::PURPOSE_UPLOAD_IP, $ip, $limitIp, $now)) {
            return EinreichungAbgelehnt::ZuVieleVonIp;
        }
        if ($this->limits->isBlocked(self::PURPOSE_UPLOAD_GESAMT, self::GLOBAL_VALUE, $limitGesamt, $now)) {
            return EinreichungAbgelehnt::ZuVieleGesamt;
        }

        $this->limits->register(self::PURPOSE_UPLOAD_IP, $ip, $now);
        $this->limits->register(self::PURPOSE_UPLOAD_GESAMT, self::GLOBAL_VALUE, $now);

        return null;
    }

    /**
     * Before App\Service\Submission\SubmissionService::einreichen() runs
     * (App\PublicPages\EinreichungController::absenden()). Does not itself
     * count a legitimate attempt - a field-error retry (missing name, bad
     * IBAN) must not cost a real visitor their hourly budget, so the
     * controller calls zaehleErfolgreicheEinreichung() only once the
     * submission actually went through. A caught bot is the one exception:
     * it is never going to produce a valid submission, so it is charged to
     * the IP limit right here.
     */
    public function pruefeAbsenden(
        string $ip,
        FormTokenData $token,
        ?string $powLoesung,
        bool $honeypotGefuellt,
        ?\DateTimeImmutable $now = null,
    ): ?EinreichungAbgelehnt {
        $now ??= new \DateTimeImmutable();

        $gesperrt = $this->pruefeGrundlegend($token, $powLoesung);
        if ($gesperrt !== null) {
            return $gesperrt;
        }

        if ($this->limits->isBlocked(self::PURPOSE_SUBMIT_IP, $ip, $this->einstellungen->limitProIpStunde, $now)) {
            return EinreichungAbgelehnt::ZuVieleVonIp;
        }
        if ($this->limits->isBlocked(self::PURPOSE_SUBMIT_GESAMT, self::GLOBAL_VALUE, $this->einstellungen->limitGesamtStunde, $now)) {
            return EinreichungAbgelehnt::ZuVieleGesamt;
        }

        $zuSchnell = $now->getTimestamp() - $token->issuedAt->getTimestamp() < self::MINDEST_AUSFUELLDAUER_SEKUNDEN;
        if ($honeypotGefuellt || $zuSchnell) {
            $this->limits->register(self::PURPOSE_SUBMIT_IP, $ip, $now);

            return EinreichungAbgelehnt::BotVerdacht;
        }

        return null;
    }

    /**
     * The counter a finished submission actually costs - called once
     * App\Service\Submission\SubmissionService::einreichen() has returned
     * success, never on a field-error response.
     */
    public function zaehleErfolgreicheEinreichung(string $ip, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $this->limits->register(self::PURPOSE_SUBMIT_IP, $ip, $now);
        $this->limits->register(self::PURPOSE_SUBMIT_GESAMT, self::GLOBAL_VALUE, $now);
    }

    /** What every entry point checks first: pause, then proof of work. */
    private function pruefeGrundlegend(FormTokenData $token, ?string $powLoesung): ?EinreichungAbgelehnt
    {
        if ($this->einstellungen->pausiert) {
            return EinreichungAbgelehnt::Pausiert;
        }

        if (!$this->proofOfWork->pruefen($token, $powLoesung)) {
            return EinreichungAbgelehnt::ProofOfWorkFehlt;
        }

        return null;
    }
}
