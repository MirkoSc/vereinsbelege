<?php

declare(strict_types=1);

namespace App\Worker;

/** A program could not be started or ran out of time. */
final class ProzessFehler extends \RuntimeException
{
}
