<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Who may run a job (docs/spec/06-betrieb.md section 4). Never the cron: a
 * job that needs the vault runs in a user session.
 */
enum JobExecutor: string
{
    case Session = 'session';
    case Browser = 'browser';
    case Worker = 'worker';
}
