<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a worker reports it can do on heartbeat (docs/spec/07-worker.md
 * sections 2 and 5), and which job types each capability lets it claim.
 */
enum WorkerFaehigkeit: string
{
    case Ocr = 'ocr';
    case Render = 'render';
    case Ai = 'ai';

    /**
     * @return non-empty-list<string> the `job.typ` values this capability covers
     */
    public function jobTypen(): array
    {
        return match ($this) {
            self::Ocr => ['ocr', 'image_cleanup'],
            self::Render => ['render_pages'],
            self::Ai => ['ai_extract'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ocr => 'Texterkennung (OCR)',
            self::Render => 'PDF-Seiten rendern',
            self::Ai => 'KI-Auslesen',
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
