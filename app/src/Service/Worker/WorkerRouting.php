<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\Worker;
use App\Domain\WorkerFaehigkeit;
use App\Domain\WorkerStatus;
use App\Repository\SettingRepository;
use App\Repository\WorkerRepository;

/**
 * Who runs a worker-capable step (docs/spec/07-worker.md section 6,
 * issue #53/M7b-3, E-43): the worker only if the module is switched on,
 * the worker is active, speaks our protocol, reported a heartbeat within
 * ONLINE_SEKUNDEN and reports the capability for the job type. Otherwise
 * the browser/session route, as without a worker at all.
 *
 * Reads only plaintext operating data (settings, the worker row) - nothing
 * here decrypts, so the cron's fallback can ask it as well.
 */
final readonly class WorkerRouting
{
    public const string SETTING_AKTIV = 'worker_modul_aktiv';

    public const string SETTING_FALLBACK_MINUTEN = 'worker_fallback_minuten';

    public const int FALLBACK_MINUTEN_STANDARD = 60;

    public const int FALLBACK_MINUTEN_MIN = 5;

    public const int FALLBACK_MINUTEN_MAX = 7 * 24 * 60;

    /** "online" = heartbeat younger than this (section 6: < 5 min). */
    public const int ONLINE_SEKUNDEN = 300;

    /**
     * Job types a worker job must wait for on the same document before it
     * may be claimed - the worker counterpart of KiAuslesung::pruefen()
     * waiting for `extract_text`/`render_pages` (the AI reads the OCR text
     * and the page images).
     *
     * @var array<string, non-empty-list<string>>
     */
    public const array VORGAENGER = [
        'ai_extract' => ['pdf_erzeugen', 'extract_text', 'render_pages', 'ocr', 'image_cleanup'],
    ];

    public function __construct(
        private WorkerRepository $workers,
        private SettingRepository $settings,
    ) {
    }

    public function modulAktiv(): bool
    {
        return $this->settings->get(self::SETTING_AKTIV) === '1';
    }

    public function fallbackMinuten(): int
    {
        $wert = filter_var($this->settings->get(self::SETTING_FALLBACK_MINUTEN), FILTER_VALIDATE_INT);

        return is_int($wert) && $wert >= self::FALLBACK_MINUTEN_MIN && $wert <= self::FALLBACK_MINUTEN_MAX
            ? $wert
            : self::FALLBACK_MINUTEN_STANDARD;
    }

    public static function online(Worker $worker, \DateTimeImmutable $now): bool
    {
        return $worker->letzterHeartbeat !== null
            && $now->getTimestamp() - $worker->letzterHeartbeat->getTimestamp() < self::ONLINE_SEKUNDEN;
    }

    /** Active and of a protocol version we speak - ready to take jobs, online or not. */
    public static function einsatzbereit(Worker $worker): bool
    {
        return $worker->status === WorkerStatus::Aktiv && WorkerProtokoll::unterstuetzt($worker->protokollVersion);
    }

    /**
     * The worker that should run a new job of $typ right now, or null for
     * the browser/session route.
     */
    public function workerFuer(string $typ, \DateTimeImmutable $now): ?Worker
    {
        if (!$this->modulAktiv()) {
            return null;
        }
        $worker = $this->workers->aktueller();

        return $worker !== null
            && self::einsatzbereit($worker)
            && self::online($worker, $now)
            && in_array($typ, $worker->jobTypen(), true)
            ? $worker
            : null;
    }

    /**
     * Whether the AI provider profiles including their API keys may be
     * sealed to the worker (review of #52): only with the module on and
     * only for a worker that reports the `ai` capability. Anything else
     * gets an empty profile list - a key handed out is out for good.
     *
     * @param list<WorkerFaehigkeit> $faehigkeiten what the worker reported
     */
    public function kiSchluesselErlaubt(array $faehigkeiten): bool
    {
        return $this->modulAktiv() && in_array(WorkerFaehigkeit::Ai, $faehigkeiten, true);
    }

    /**
     * Whether open worker jobs must go back to the browser/session route:
     * module off, no worker ready, or silent for longer than the fallback
     * period (section 6).
     */
    public function fallbackFaellig(?Worker $worker, \DateTimeImmutable $now): bool
    {
        if (!$this->modulAktiv() || $worker === null || !self::einsatzbereit($worker)) {
            return true;
        }
        return $now->getTimestamp() - self::letzteMeldung($worker)->getTimestamp() >= $this->fallbackMinuten() * 60;
    }

    /** The worker's last sign of life: heartbeat, else confirmation, else pairing. */
    public static function letzteMeldung(Worker $worker): \DateTimeImmutable
    {
        return $worker->letzterHeartbeat ?? $worker->bestaetigtAm ?? $worker->gekoppeltAm;
    }
}
