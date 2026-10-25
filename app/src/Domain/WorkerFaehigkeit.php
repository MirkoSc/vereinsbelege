<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a worker reports it can do on heartbeat (docs/spec/07-worker.md
 * sections 2 and 5), and which job types each capability lets it claim.
 * `mail` (issue #87) claims none: it only releases the IMAP access of the
 * mail intake to the worker.
 */
enum WorkerFaehigkeit: string
{
    case Ocr = 'ocr';
    case Render = 'render';
    case Ai = 'ai';
    /** Fetches receipts from an IMAP mailbox (issue #87) - no job type. */
    case Mail = 'mail';

    /**
     * @return list<string> the `job.typ` values this capability covers
     *         (none for `mail`: the mail intake is no job, issue #87)
     */
    public function jobTypen(): array
    {
        return match ($this) {
            self::Ocr => ['ocr'],
            self::Render => ['render_pages'],
            self::Ai => ['ai_extract'],
            self::Mail => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ocr => 'Texterkennung (OCR)',
            self::Render => 'PDF-Seiten rendern',
            self::Ai => 'KI-Auslesen',
            self::Mail => 'E-Mail-Eingang',
        };
    }

    /**
     * @param list<self> $faehigkeiten
     * @return list<string>
     */
    public static function jobTypenFuer(array $faehigkeiten): array
    {
        $typen = [];
        foreach ($faehigkeiten as $faehigkeit) {
            array_push($typen, ...$faehigkeit->jobTypen());
        }

        return array_values(array_unique($typen));
    }
}
