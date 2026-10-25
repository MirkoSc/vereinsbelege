<?php

declare(strict_types=1);

namespace App\Tests\Worker\Support;

use App\Worker\Http\HttpAntwort;
use App\Worker\Http\HttpFehler;
use App\Worker\Http\WorkerHttp;

/**
 * A web host on a script: answers (or network errors) in order, and every
 * request kept for the assertions.
 */
final class SkriptHttp implements WorkerHttp
{
    /** @var list<array{methode: string, url: string, header: array<string, string>, body: string, zeitlimit: int, abbrechbar: bool}> */
    public array $anfragen = [];

    /**
     * @param list<HttpAntwort|HttpFehler|\Closure(): (HttpAntwort|HttpFehler)> $antworten
     */
    public function __construct(private array $antworten = [])
    {
    }

    /** @param array<string, mixed> $daten */
    public static function json(int $status, array $daten, array $header = []): HttpAntwort
    {
        return new HttpAntwort($status, $header, json_encode($daten, JSON_THROW_ON_ERROR));
    }

    public function dazu(HttpAntwort|HttpFehler|\Closure ...$antworten): void
    {
        array_push($this->antworten, ...$antworten);
    }

    public function anfrage(string $methode, string $url, array $header, string $body, int $zeitlimitSekunden, bool $abbrechbar = true): HttpAntwort
    {
        $kopf = [];
        foreach ($header as $zeile) {
            [$name, $wert] = array_map(trim(...), explode(':', $zeile, 2));
            $kopf[strtolower($name)] = $wert;
        }
        $this->anfragen[] = ['methode' => $methode, 'url' => $url, 'header' => $kopf, 'body' => $body, 'zeitlimit' => $zeitlimitSekunden, 'abbrechbar' => $abbrechbar];

        $antwort = array_shift($this->antworten) ?? throw new \LogicException('SkriptHttp: no answer left for ' . $methode . ' ' . $url);
        if ($antwort instanceof \Closure) {
            $antwort = $antwort();
        }
        if ($antwort instanceof HttpFehler) {
            throw $antwort;
        }

        return $antwort;
    }
}
