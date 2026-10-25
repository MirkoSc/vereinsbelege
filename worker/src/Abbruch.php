<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * "Please stop": set by SIGTERM/SIGINT (bin/worker.php), read wherever the
 * worker waits or works. A running job is given back to the web host
 * (`fail` without `endgueltig`), no new one is claimed.
 */
final class Abbruch
{
    private bool $angefordert = false;

    public function anfordern(): void
    {
        $this->angefordert = true;
    }

    public function angefordert(): bool
    {
        return $this->angefordert;
    }

    /** @throws Abgebrochen */
    public function pruefen(): void
    {
        if ($this->angefordert) {
            throw new Abgebrochen('Beenden angefordert.');
        }
    }

    /**
     * Sleeps up to $sekunden, waking early on a stop request. Returns false
     * when it woke for that.
     */
    public function schlafen(float $sekunden): bool
    {
        $ende = microtime(true) + max(0.0, $sekunden);
        while (!$this->angefordert && microtime(true) < $ende) {
            usleep((int) (min(0.25, max(0.0, $ende - microtime(true))) * 1_000_000));
        }

        return !$this->angefordert;
    }
}
