<?php

declare(strict_types=1);

namespace App\Worker\Mail;

/**
 * Something went wrong with the IMAP mailbox (issue #87). $fehlercode is
 * an identifier for the log and the admin page (`imap_verbindung`,
 * `imap_tls`, `imap_starttls_fehlt`, `imap_anmeldung`, `imap_ordner`,
 * `imap_protokoll`); the message never quotes the server's answer, which
 * may echo the user name or mailbox content.
 */
final class ImapFehler extends \RuntimeException
{
    public function __construct(public readonly string $fehlercode, string $meldung = '', ?\Throwable $vorher = null)
    {
        parent::__construct($meldung === '' ? $fehlercode : $meldung, 0, $vorher);
    }
}
