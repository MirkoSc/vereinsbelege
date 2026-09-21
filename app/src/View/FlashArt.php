<?php

declare(strict_types=1);

namespace App\View;

/**
 * Severity of a flash message. Decides the visual variant and - more
 * importantly - how a screen reader announces it: a failure interrupts
 * (role="alert"), a confirmation waits its turn (role="status").
 */
enum FlashArt: string
{
    case Ok = 'ok';
    case Info = 'info';
    case Warnung = 'warnung';
    case Fehler = 'fehler';

    /** Variant of the .hinweis component in public/css/app.css. */
    public function cssKlasse(): string
    {
        return 'hinweis-' . $this->value;
    }

    public function ariaRolle(): string
    {
        return $this === self::Fehler ? 'alert' : 'status';
    }
}
