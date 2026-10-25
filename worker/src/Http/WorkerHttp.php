<?php

declare(strict_types=1);

namespace App\Worker\Http;

/**
 * One HTTP exchange of the worker - with the web host or an AI provider.
 * Implemented with curl (CurlWorkerHttp); the tests put the web host's own
 * controller behind it.
 */
interface WorkerHttp
{
    /**
     * @param list<string> $header "Name: value" lines
     * @param bool $abbrechbar whether a stop request (SIGTERM) may cut it off
     *
     * @throws HttpFehler when no answer arrived (connection, timeout, abort)
     */
    public function anfrage(string $methode, string $url, array $header, string $body, int $zeitlimitSekunden, bool $abbrechbar = true): HttpAntwort;
}
