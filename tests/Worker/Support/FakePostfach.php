<?php

declare(strict_types=1);

namespace App\Tests\Worker\Support;

use App\Worker\Mail\ImapFehler;
use App\Worker\Mail\MimeNachricht;
use App\Worker\Mail\Postfach;

/**
 * An IMAP mailbox in memory for the mail intake tests (issue #87): mails
 * by UID, their flags, what was moved where - and nothing is ever deleted
 * (there is no method for it).
 */
final class FakePostfach implements Postfach
{
    /** @var array<int, string> uid => raw mail */
    public array $mails = [];

    /** @var array<int, list<string>> */
    public array $flags = [];

    /** @var array<int, string> uid => folder */
    public array $verschoben = [];

    public bool $keywords = true;

    public bool $kannVerschieben = true;

    public int $anmeldungen = 0;

    public bool $abgemeldet = false;

    public ?string $ordner = null;

    public ?ImapFehler $fehler = null;

    /** @var list<int> UIDs whose FETCH the server cannot answer */
    public array $kaputt = [];

    /**
     * Called when a mail is fetched - a test throws from here what happens
     * mid-mail (the connection breaks, the worker is killed).
     *
     * @var ?\Closure(int): void
     */
    public ?\Closure $beimHolen = null;

    /** @var array<int, int> uid => how often the whole mail was fetched */
    public array $geholt = [];

    /** @var list<int> UIDs whose Message-ID alone was asked for */
    public array $messageIdAbgefragt = [];

    public function hinzufuegen(string $roh): int
    {
        $uid = $this->mails === [] ? 1 : max(array_keys($this->mails)) + 1;
        $this->mails[$uid] = $roh;
        $this->flags[$uid] = [];

        return $uid;
    }

    public function auswaehlen(string $ordner): bool
    {
        if ($this->fehler !== null) {
            throw $this->fehler;
        }
        $this->ordner = $ordner;

        return $this->keywords;
    }

    /** @var list<?string> the $seit of every search */
    public array $seit = [];

    public function offene(bool $mitKeywords, ?string $seit = null): array
    {
        $this->seit[] = $seit;
        $offen = [];
        foreach ($this->mails as $uid => $roh) {
            if (isset($this->verschoben[$uid])) {
                continue;
            }
            $f = $this->flags[$uid];
            $handled = $mitKeywords
                ? in_array('$Vereinsbelege', $f, true) || in_array('$VereinsbelegeAbgelehnt', $f, true)
                : in_array('\\Seen', $f, true);
            if (!$handled) {
                $offen[] = $uid;
            }
        }

        return $offen;
    }

    public function angaben(array $uids): array
    {
        $a = [];
        foreach ($uids as $uid) {
            $a[$uid] = ['groesse' => strlen($this->mails[$uid]), 'flags' => $this->flags[$uid]];
        }

        return $a;
    }

    /** @var array<int, list<string>> the flags of each mail at the moment it was fetched */
    public array $flagsBeimHolen = [];

    public function messageId(int $uid): ?string
    {
        $this->messageIdAbgefragt[] = $uid;
        $kopf = preg_split('/\r?\n\r?\n/', $this->mails[$uid], 2)[0] ?? '';

        return MimeNachricht::messageIdAusKopf($kopf);
    }

    public function entmarkieren(int $uid, array $flags): void
    {
        $this->flags[$uid] = array_values(array_diff($this->flags[$uid], $flags));
    }

    public function holen(int $uid, int $maxBytes): string
    {
        $this->flagsBeimHolen[$uid] = $this->flags[$uid];
        $this->geholt[$uid] = ($this->geholt[$uid] ?? 0) + 1;
        if ($this->beimHolen !== null) {
            ($this->beimHolen)($uid);
        }
        if (in_array($uid, $this->kaputt, true)) {
            throw new ImapFehler('imap_protokoll');
        }

        return $this->mails[$uid];
    }

    public function markieren(int $uid, array $flags): void
    {
        $this->flags[$uid] = array_values(array_unique([...$this->flags[$uid], ...$flags]));
    }

    public function verschieben(int $uid, string $ordner): bool
    {
        if (!$this->kannVerschieben) {
            return false;
        }
        $this->verschoben[$uid] = $ordner;

        return true;
    }

    public function abmelden(): void
    {
        $this->abgemeldet = true;
    }
}

