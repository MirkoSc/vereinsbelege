<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * An artifact upload the web host refuses (issue #52/M7b-2): a code for the
 * worker's JSON error and the HTTP status to answer with. Never quotes
 * anything the worker sent.
 */
final class WorkerArtefaktFehler extends \RuntimeException
{
    public function __construct(public readonly string $fehler, public readonly int $status)
    {
        parent::__construct($fehler);
    }
}
