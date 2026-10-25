<?php

declare(strict_types=1);

namespace App\Tests\Worker\Support;

use App\Worker\Mail\ImapFehler;
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

    public function groessen(array $uids): array
    {
        $g = [];
        foreach ($uids as $uid) {
            $g[$uid] = strlen($this->mails[$uid]);
        }

        return $g;
    }

    public function holen(int $uid): string
    {
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
