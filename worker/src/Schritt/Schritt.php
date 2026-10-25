<?php

declare(strict_types=1);

namespace App\Worker\Schritt;

use App\Worker\JobLauf;

/**
 * One job type the worker processes (docs/spec/07-worker.md section 2).
 * Runs with a private tmpfs directory that is removed afterwards; results
 * go to the web host through the JobLauf, encrypted for the vault.
 */
interface Schritt
{
    /** The job type, as in `job.typ`. */
    public function typ(): string;

    /**
     * @throws \App\Worker\JobFehler when the job cannot be done
     */
    public function ausfuehren(JobLauf $lauf, string $verzeichnis): void;
}
