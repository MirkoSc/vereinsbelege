<?php

declare(strict_types=1);

namespace App\Worker;

/** The worker is shutting down (SIGTERM): the job in hand goes back. */
final class Abgebrochen extends \RuntimeException
{
}
