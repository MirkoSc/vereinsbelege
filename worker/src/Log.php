<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * The worker's log: one JSON line per event on stderr (Docker collects and
 * rotates it). Only event names, ids, counters, durations, HTTP status and
 * error codes - never content of a receipt, never a key, never a tool's
 * output (CLAUDE.md section 4). Values that are not int/bool/float or a
 * short identifier are dropped.
 */
final class Log
{
    /** @var \Closure(string): void */
    private \Closure $ausgabe;

    /** @param ?\Closure(string): void $ausgabe defaults to STDERR */
    public function __construct(?\Closure $ausgabe = null)
    {
        $this->ausgabe = $ausgabe ?? static function (string $zeile): void {
            fwrite(STDERR, $zeile);
        };
    }

    /**
     * @param array<string, scalar|null> $felder
     */
    public function ereignis(string $ereignis, array $felder = []): void
    {
        $zeile = ['zeit' => date(DATE_ATOM), 'ereignis' => self::bezeichner($ereignis) ?? 'unbekannt'];
        foreach ($felder as $name => $wert) {
            $name = self::bezeichner((string) $name);
            if ($name === null) {
                continue;
            }
            $zeile[$name] = match (true) {
                is_int($wert), is_bool($wert), is_float($wert), $wert === null => $wert,
                default => self::bezeichner((string) $wert),
            };
        }
        ($this->ausgabe)(json_encode($zeile, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }

    /** An identifier like an error code or class name, else null. */
    private static function bezeichner(string $wert): ?string
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_.:\\\\-]{0,119}$/', $wert) === 1 ? $wert : null;
    }
}
