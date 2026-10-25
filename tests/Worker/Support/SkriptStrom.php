<?php

declare(strict_types=1);

namespace App\Tests\Worker\Support;

use App\Worker\Mail\ImapFehler;
use App\Worker\Mail\ImapStrom;

/**
 * An IMAP server on a script: what it sends, in order (lines with CRLF and
 * literal bytes alike, as one byte stream), and everything the client
 * wrote - with the moments TLS was started.
 */
final class SkriptStrom implements ImapStrom
{
    public string $gesendet = '';

    /** Length of $gesendet when TLS started; null = never. */
    public ?int $tlsAb = null;

    public bool $geschlossen = false;

    public function __construct(private string $antworten, private bool $tls = false)
    {
    }

    public function zeile(): string
    {
        $pos = strpos($this->antworten, "\n");
        if ($pos === false) {
            throw new ImapFehler('imap_verbindung');
        }
        $zeile = substr($this->antworten, 0, $pos + 1);
        $this->antworten = substr($this->antworten, $pos + 1);

        return $zeile;
    }

    public function lesen(int $bytes): string
    {
        if (strlen($this->antworten) < $bytes) {
            throw new ImapFehler('imap_verbindung');
        }
        $daten = substr($this->antworten, 0, $bytes);
        $this->antworten = substr($this->antworten, $bytes);

        return $daten;
    }

    public function schreiben(string $daten): void
    {
        $this->gesendet .= $daten;
    }

    public function tlsStarten(): void
    {
        $this->tls = true;
        $this->tlsAb = strlen($this->gesendet);
    }

    public function verschluesselt(): bool
    {
        return $this->tls;
    }

    public function schliessen(): void
    {
        $this->geschlossen = true;
    }
}
