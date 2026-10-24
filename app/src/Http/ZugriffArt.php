<?php

declare(strict_types=1);

namespace App\Http;

/** The kinds of App\Http\Zugriff. */
enum ZugriffArt
{
    case Oeffentlich;
    case Cron;
    /** No session; an HMAC-signed request of a paired worker (issue #51). */
    case Worker;
    case Angemeldet;
    case Recht;
    case AdminBereich;
}
