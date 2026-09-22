<?php

declare(strict_types=1);

namespace App\Http;

/** The kinds of App\Http\Zugriff. */
enum ZugriffArt
{
    case Oeffentlich;
    case Cron;
    case Angemeldet;
    case Recht;
    case AdminBereich;
}
