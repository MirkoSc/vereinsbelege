<?php

declare(strict_types=1);

namespace App\Worker\Mail;

/**
 * What the mail intake needs of a mailbox (issue #87) - the IMAP client
 * in operation, an in-memory mailbox in the tests. Every method may throw
 * ImapFehler.
 */
interface Postfach
{
    /** Selects $ordner; true when the server lets us set our own keywords. */
    public function auswaehlen(string $ordner): bool;

    /**
     * UIDs of the mails not handled yet, ascending - with $seit (Y-m-d)
     * only those that arrived on or after that day.
     *
     * @return list<int>
     */
    public function offene(bool $mitKeywords, ?string $seit = null): array;

    /**
     * Size (RFC822.SIZE) and flags of each mail - the flags carry the
     * attempt counter (ImapClient::KEYWORDS_VERSUCH).
     *
     * @param list<int> $uids
     *
     * @return array<int, array{groesse: int, flags: list<string>}>
     */
    public function angaben(array $uids): array;

    /**
     * The Message-ID alone, without fetching the mail (null: it has none) -
     * to find a mail in the inbox that is not fetched again (issue #243).
     */
    public function messageId(int $uid): ?string;

    /** The whole mail, without setting \Seen; refused when larger than $maxBytes. */
    public function holen(int $uid, int $maxBytes): string;

    /** @param list<string> $flags */
    public function markieren(int $uid, array $flags): void;

    /** @param list<string> $flags */
    public function entmarkieren(int $uid, array $flags): void;

    /** Moves the mail; false when the server cannot (no MOVE) or refused. */
    public function verschieben(int $uid, string $ordner): bool;

    public function abmelden(): void;
}
