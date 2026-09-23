<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * Why the spam defence turned a public request away
 * (docs/spec/01-sicherheit.md section 5, issue #25/M4-3,
 * App\Service\Submission\Spamschutz).
 *
 * The same shape as App\Service\Upload\UploadError: one sensible status
 * code and one sensible sentence per case, next to it. No message names an
 * IP, a limit or a file - an error message is not a place for that
 * (CLAUDE.md section 4), and telling a bot exactly which rule it tripped
 * would only help it get past the next one.
 */
enum EinreichungAbgelehnt: string
{
    case Pausiert = 'pausiert';
    case ZuVieleVonIp = 'zu_viele_von_ip';
    case ZuVieleGesamt = 'zu_viele_gesamt';
    case ProofOfWorkFehlt = 'pow_ungueltig';
    case BotVerdacht = 'bot_verdacht';
    case DateiZuGross = 'datei_zu_gross';

    public function status(): int
    {
        return match ($this) {
            self::Pausiert => 503,
            self::ZuVieleVonIp, self::ZuVieleGesamt => 429,
            self::ProofOfWorkFehlt => 403,
            self::BotVerdacht => 422,
            self::DateiZuGross => 413,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Pausiert => 'Die Einreichung ist vorübergehend nicht möglich. Bitte später erneut versuchen.',
            self::ZuVieleVonIp, self::ZuVieleGesamt => 'Zu viele Einreichungen. Bitte später erneut versuchen.',
            self::ProofOfWorkFehlt => 'Das Formular ist abgelaufen – bitte die Seite neu laden.',
            self::BotVerdacht => 'Bitte kurz warten und das Formular erneut absenden.',
            self::DateiZuGross => 'Die Datei ist zu groß.',
        };
    }
}
