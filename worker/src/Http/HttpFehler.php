<?php

declare(strict_types=1);

namespace App\Worker\Http;

/** No answer: connection refused, DNS, TLS, timeout or a stop request. */
final class HttpFehler extends \RuntimeException
{
    public function __construct(string $meldung, public readonly bool $zeitueberschreitung = false, public readonly bool $abgebrochen = false)
    {
        parent::__construct($meldung);
    }
}
