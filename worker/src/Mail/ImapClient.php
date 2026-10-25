<?php

declare(strict_types=1);

namespace App\Worker\Mail;

use App\Domain\ImapSicherheit;

/**
 * A minimal IMAP4rev1 client for the mail intake (issue #87, E-54,
 * docs/spec/07-worker.md section 9) - just what reading a receipt mailbox
 * needs: CAPABILITY, STARTTLS, LOGIN/AUTHENTICATE PLAIN, SELECT,
 * UID SEARCH, UID FETCH, UID STORE, UID MOVE, LOGOUT.
 *
 * Written by hand instead of a library: ext/imap is gone since PHP 8.4,
 * and the pure-PHP libraries pull in frameworks (E-54).
 *
 * Never logs in over plaintext: with `starttls` the server must offer
 * STARTTLS and the upgrade must succeed (certificate and host name
 * verified, SocketStrom) before LOGIN; the stream is checked once more
 * right before the credentials go out. Mails are read with BODY.PEEK so
 * reading alone marks nothing; what was handled gets a keyword (or \Seen)
 * and may be moved - nothing is ever deleted or expunged.
 */
final class ImapClient implements Postfach
{
    public const string KEYWORD_ERLEDIGT = '$Vereinsbelege';

    public const string KEYWORD_ABGELEHNT = '$VereinsbelegeAbgelehnt';

    /**
     * The attempt counter, kept at the mail itself because the worker keeps
     * nothing (review of #87, issue #243): before the n-th attempt the mail
     * gets the n-th keyword, all go once it is handled. A mail still
     * carrying them was not finished - the connection broke or the worker
     * died - and is tried again; after the last one it is set aside.
     */
    public const array KEYWORDS_VERSUCH = ['$VereinsbelegeVersuch', '$VereinsbelegeVersuch2', '$VereinsbelegeVersuch3'];

    /** Piece size for reading off a literal that is dropped. */
    private const int STUECK = 65536;


    /** Largest literal accepted unless a command sets a smaller one. */
    private const int MAX_LITERAL = 96 * 1024 * 1024;

    private int $maxLiteral = self::MAX_LITERAL;

    /**
     * A literal of the last command was larger than allowed and was read
     * off the stream and dropped - the answer is unusable, the connection
     * stays in step (review of #243).
     */
    private bool $verworfen = false;

    private int $tag = 0;

    private int $uidGueltigkeit = 0;

    /** @var list<string> upper case */
    private array $faehigkeiten = [];

    private function __construct(private readonly ImapStrom $strom)
    {
    }

    /**
     * Greets, secures and logs in.
     *
     * @throws ImapFehler
     */
    public static function verbinden(ImapStrom $strom, ImapSicherheit $sicherheit, string $benutzer, #[\SensitiveParameter] string $passwort): self
    {
        $client = new self($strom);
        try {
            $client->anmelden($sicherheit, $benutzer, $passwort);
        } catch (\Throwable $e) {
            $strom->schliessen();

            throw $e;
        }

        return $client;
    }

    /** @return list<string> */
    public function faehigkeiten(): array
    {
        return $this->faehigkeiten;
    }

    public function auswaehlen(string $ordner): bool
    {
        [$antworten, $status] = $this->befehl('SELECT ' . self::zitat(self::mutf7($ordner)));
        if ($status !== 'OK') {
            throw new ImapFehler('imap_ordner', 'Der Ordner wurde nicht gefunden.');
        }
        $this->uidGueltigkeit = 0;
        foreach ($antworten as [$text]) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $text, $t) === 1) {
                $this->uidGueltigkeit = (int) $t[1];
            }
        }
        foreach ($antworten as [$text]) {
            if (preg_match('/\[PERMANENTFLAGS \(([^)]*)\)\]/i', $text, $t) === 1) {
                $flags = preg_split('/\s+/', trim($t[1])) ?: [];

                return in_array('\\*', $flags, true)
                    || array_diff([self::KEYWORD_ERLEDIGT, self::KEYWORD_ABGELEHNT, ...self::KEYWORDS_VERSUCH], $flags) === [];
            }
        }

        // No PERMANENTFLAGS: RFC 3501 says every flag is then permanent,
        // keywords included.
        return true;
    }

    public function offene(bool $mitKeywords, ?string $seit = null): array
    {
        return $this->suchen($mitKeywords
            ? 'UNKEYWORD ' . self::KEYWORD_ERLEDIGT . ' UNKEYWORD ' . self::KEYWORD_ABGELEHNT . ' UNDELETED'
            : 'UNSEEN UNDELETED', $seit);
    }

    /** @return list<int> */
    private function suchen(string $kriterien, ?string $seit): array
    {
        $tag = $seit === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $seit);
        if ($tag !== false) {
            // IMAP dates: day-Mon-year with English month names.
            $kriterien .= ' SINCE ' . $tag->format('j-M-Y');
        }
        [$antworten] = $this->befehlOk('UID SEARCH ' . $kriterien);
        $uids = [];
        foreach ($antworten as [$text]) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', rtrim($text), $t) === 1) {
                foreach (preg_split('/\s+/', trim($t[1])) ?: [] as $wert) {
                    if (ctype_digit($wert) && (int) $wert > 0) {
                        $uids[] = (int) $wert;
                    }
                }
            }
        }
        $uids = array_values(array_unique($uids));
        sort($uids);

        return $uids;
    }

    public function angaben(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        [$antworten] = $this->befehlOk('UID FETCH ' . implode(',', array_map(intval(...), $uids)) . ' (RFC822.SIZE FLAGS)');
        $angaben = [];
        foreach ($antworten as [$text]) {
            if (preg_match('/\bUID (\d+)/i', $text, $u) === 1 && preg_match('/\bRFC822\.SIZE (\d+)/i', $text, $g) === 1) {
                $flags = preg_match('/\bFLAGS \(([^)]*)\)/i', $text, $f) === 1 ? (preg_split('/\s+/', trim($f[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
                $angaben[(int) $u[1]] = ['groesse' => (int) $g[1], 'flags' => array_values($flags)];
            }
        }

        return $angaben;
    }

    public function uidGueltigkeit(): int
    {
        return $this->uidGueltigkeit;
    }

    public function kopf(int $uid): string
    {
        $this->maxLiteral = self::KOPF_BYTES;
        try {
            [$antworten] = $this->befehlOk('UID FETCH ' . $uid . ' (BODY.PEEK[HEADER]<0.' . self::KOPF_BYTES . '>)');
        } finally {
            $this->maxLiteral = self::MAX_LITERAL;
        }
        $this->nichtVerworfen();
        foreach ($antworten as [$text, $literale]) {
            if ($literale !== [] && preg_match('/BODY\[HEADER\](?:<0>)? \{\d+\+?\}/i', $text) === 1) {
                return $literale[0];
            }
        }

        throw new ImapFehler('imap_protokoll', 'Die Mail konnte nicht gelesen werden.');
    }

    public function holen(int $uid, int $maxBytes): string
    {
        // A server announcing more than the size limit gets no buffer for it.
        $this->maxLiteral = max(1, min(self::MAX_LITERAL, $maxBytes));
        try {
            [$antworten] = $this->befehlOk('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        } finally {
            $this->maxLiteral = self::MAX_LITERAL;
        }
        $this->nichtVerworfen();
        foreach ($antworten as [$text, $literale]) {
            if ($literale !== [] && preg_match('/\bUID ' . $uid . '\b/i', $text) === 1 && preg_match('/BODY\[\] \{\d+\+?\}/i', $text) === 1) {
                return $literale[0];
            }
        }
        foreach ($antworten as [$text, $literale]) {
            // Some servers put UID after the body; one literal FETCH is ours.
            if ($literale !== [] && preg_match('/BODY\[\] \{\d+\+?\}/i', $text) === 1) {
                return $literale[0];
            }
        }

        throw new ImapFehler('imap_protokoll', 'Die Mail konnte nicht gelesen werden.');
    }

    public function markieren(int $uid, array $flags): void
    {
        $this->speichern($uid, '+', $flags);
    }

    public function entmarkieren(int $uid, array $flags): void
    {
        $this->speichern($uid, '-', $flags);
    }

    /** @param list<string> $flags */
    private function speichern(int $uid, string $richtung, array $flags): void
    {
        foreach ($flags as $flag) {
            if (preg_match('/^\\\\?\$?[A-Za-z][A-Za-z0-9]*$/', $flag) !== 1) {
                throw new \InvalidArgumentException('Invalid flag.');
            }
        }
        $this->befehlOk('UID STORE ' . $uid . ' ' . $richtung . 'FLAGS.SILENT (' . implode(' ', $flags) . ')');
    }

    public function verschieben(int $uid, string $ordner): bool
    {
        if (!in_array('MOVE', $this->faehigkeiten, true)) {
            return false;
        }
        [, $status] = $this->befehl('UID MOVE ' . $uid . ' ' . self::zitat(self::mutf7($ordner)));

        return $status === 'OK';
    }

    public function abmelden(): void
    {
        try {
            $this->befehl('LOGOUT');
        } catch (ImapFehler) {
            // Gone already - nothing to say goodbye to.
        } finally {
            $this->strom->schliessen();
        }
    }

    /**
     * IMAP's modified UTF-7 for mailbox names (RFC 3501 section 5.1.3).
     */
    public static function mutf7(string $name): string
    {
        $ergebnis = '';
        $puffer = '';
        $leeren = static function () use (&$puffer, &$ergebnis): void {
            if ($puffer !== '') {
                $utf16 = mb_convert_encoding($puffer, 'UTF-16BE', 'UTF-8');
                $ergebnis .= '&' . str_replace('/', ',', rtrim(base64_encode($utf16), '=')) . '-';
                $puffer = '';
            }
        };
        foreach (mb_str_split($name, 1, 'UTF-8') as $zeichen) {
            $code = mb_ord($zeichen, 'UTF-8');
            if ($code >= 0x20 && $code <= 0x7E) {
                $leeren();
                $ergebnis .= $zeichen === '&' ? '&-' : $zeichen;
            } else {
                $puffer .= $zeichen;
            }
        }
        $leeren();

        return $ergebnis;
    }

    /** A quoted string; only for 7-bit text without CR/LF (mailbox names after mutf7()). */
    private static function zitat(string $wert): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $wert) . '"';
    }

    /** @throws ImapFehler */
    private function anmelden(ImapSicherheit $sicherheit, string $benutzer, #[\SensitiveParameter] string $passwort): void
    {
        $gruss = $this->strom->zeile();
        if (preg_match('/^\* (OK|PREAUTH|BYE)\b/i', $gruss, $t) !== 1 || strtoupper($t[1]) === 'BYE') {
            throw new ImapFehler('imap_verbindung', 'Der IMAP-Server hat die Verbindung abgelehnt.');
        }
        if (strtoupper($t[1]) === 'PREAUTH') {
            // Logged in before any TLS could happen - not with us.
            throw new ImapFehler('imap_protokoll', 'Der IMAP-Server meldet eine Vorab-Anmeldung.');
        }
        $this->capability();

        if ($sicherheit === ImapSicherheit::Starttls) {
            if (!in_array('STARTTLS', $this->faehigkeiten, true)) {
                throw new ImapFehler('imap_starttls_fehlt', 'Der IMAP-Server bietet kein STARTTLS an.');
            }
            [, $status] = $this->befehl('STARTTLS');
            if ($status !== 'OK') {
                throw new ImapFehler('imap_starttls_fehlt', 'Der IMAP-Server lehnt STARTTLS ab.');
            }
            $this->strom->tlsStarten();
            // What the server said before TLS cannot be trusted (RFC 3501
            // section 6.2.1) - ask again.
            $this->capability();
        }
        if (!$this->strom->verschluesselt()) {
            throw new ImapFehler('imap_tls', 'Die Verbindung ist nicht verschlüsselt.');
        }

        if (in_array('LOGINDISABLED', $this->faehigkeiten, true)) {
            if (!in_array('AUTH=PLAIN', $this->faehigkeiten, true)) {
                throw new ImapFehler('imap_anmeldung', 'Der IMAP-Server erlaubt keine Anmeldung mit Passwort.');
            }
            $status = $this->authenticatePlain($benutzer, $passwort);
        } else {
            [, $status] = $this->befehlTeile(['LOGIN', self::argument($benutzer), self::argument($passwort)]);
        }
        if ($status !== 'OK') {
            throw new ImapFehler('imap_anmeldung', 'Die Anmeldung am IMAP-Server wurde abgelehnt.');
        }
        // Servers may announce new capabilities after login (MOVE).
        $this->capability();
    }

    private function capability(): void
    {
        [$antworten] = $this->befehlOk('CAPABILITY');
        $this->faehigkeiten = [];
        foreach ($antworten as [$text]) {
            if (preg_match('/^\* CAPABILITY (.*)$/i', rtrim($text), $t) === 1) {
                $this->faehigkeiten = array_values(array_map(strtoupper(...), preg_split('/\s+/', trim($t[1])) ?: []));
            }
        }
    }

    private function authenticatePlain(string $benutzer, #[\SensitiveParameter] string $passwort): string
    {
        $tag = $this->naechsterTag();
        $this->strom->schreiben($tag . " AUTHENTICATE PLAIN\r\n");
        $zeile = $this->strom->zeile();
        if (!str_starts_with($zeile, '+')) {
            return 'NO';
        }
        $this->strom->schreiben(base64_encode("\0" . $benutzer . "\0" . $passwort) . "\r\n");

        return $this->bisTag($tag)[1];
    }

    /**
     * A user name or password as command part: a quoted string when it is
     * plain printable ASCII, else a literal (sent after the continuation).
     *
     * @return string|array{0: string}
     */
    private static function argument(#[\SensitiveParameter] string $wert): string|array
    {
        return preg_match('/^[\x20-\x7E]*$/', $wert) === 1 ? self::zitat($wert) : [$wert];
    }

    /**
     * Sends a command and reads up to its tagged answer. $teile are joined
     * by spaces; an array part is a literal.
     *
     * @param list<string|array{0: string}> $teile
     *
     * @return array{0: list<array{0: string, 1: list<string>}>, 1: string} untagged answers (text, literals), status
     *
     * @throws ImapFehler
     */
    private function befehl(string ...$teile): array
    {
        return $this->befehlTeile($teile);
    }

    /**
     * @param list<string|array{0: string}> $teile
     *
     * @return array{0: list<array{0: string, 1: list<string>}>, 1: string}
     */
    private function befehlTeile(array $teile): array
    {
        $tag = $this->naechsterTag();
        $this->verworfen = false;
        $puffer = $tag;
        foreach ($teile as $teil) {
            if (is_string($teil)) {
                $puffer .= ' ' . $teil;
                continue;
            }
            $this->strom->schreiben($puffer . ' {' . strlen($teil[0]) . "}\r\n");
            $zeile = $this->strom->zeile();
            if (!str_starts_with($zeile, '+')) {
                // The server refused the literal: this line is its answer.
                return [[], preg_match('/^' . $tag . ' (OK|NO|BAD)\b/i', $zeile, $t) === 1 ? strtoupper($t[1]) : 'BAD'];
            }
            $puffer = $teil[0];
        }
        $this->strom->schreiben($puffer . "\r\n");

        return $this->bisTag($tag);
    }

    /**
     * @return array{0: list<array{0: string, 1: list<string>}>, 1: string}
     *
     * @throws ImapFehler when the command did not end with OK
     */
    private function befehlOk(string $befehl): array
    {
        $antwort = $this->befehl($befehl);
        if ($antwort[1] !== 'OK') {
            throw new ImapFehler('imap_protokoll', 'Der IMAP-Server hat einen Befehl abgelehnt.');
        }

        return $antwort;
    }

    /**
     * @return array{0: list<array{0: string, 1: list<string>}>, 1: string}
     */
    private function bisTag(string $tag): array
    {
        $antworten = [];
        while (true) {
            [$text, $literale] = $this->antwortLesen();
            if (str_starts_with($text, $tag . ' ')) {
                return [$antworten, preg_match('/^' . $tag . ' (OK|NO|BAD)\b/i', $text, $t) === 1 ? strtoupper($t[1]) : 'BAD'];
            }
            if (str_starts_with($text, '*')) {
                $antworten[] = [$text, $literale];
            }
            // Continuation requests we did not ask for are ignored.
        }
    }

    /**
     * One response line with its literals resolved.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function antwortLesen(): array
    {
        $text = '';
        $literale = [];
        $zeile = $this->strom->zeile();
        while (preg_match('/\{(\d+)\+?\}\r?\n$/', $zeile, $t) === 1) {
            $laenge = (int) $t[1];
            $text .= rtrim($zeile, "\r\n");
            if ($laenge > $this->maxLiteral) {
                // No buffer for it - but read off and dropped piece by piece,
                // else its bytes would be taken for the server's answers.
                for ($rest = $laenge; $rest > 0; $rest -= self::STUECK) {
                    $this->strom->lesen(min(self::STUECK, $rest));
                }
                $this->verworfen = true;
                $literale[] = '';
            } else {
                $literale[] = $this->strom->lesen($laenge);
            }
            $zeile = $this->strom->zeile();
        }

        return [$text . rtrim($zeile, "\r\n"), $literale];
    }

    /** @throws ImapFehler when the last answer had a literal that was dropped */
    private function nichtVerworfen(): void
    {
        if ($this->verworfen) {
            throw new ImapFehler('imap_protokoll', 'Die Antwort des IMAP-Servers ist zu groß.');
        }
    }

    private function naechsterTag(): string
    {
        return sprintf('A%04d', ++$this->tag);
    }
}
