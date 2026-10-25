<?php

declare(strict_types=1);

namespace App\Worker\Mail;

use App\Service\Upload\MagicBytes;
use App\Worker\Abbruch;
use App\Worker\Abgebrochen;
use App\Worker\ApiClient;
use App\Worker\ApiFehler;
use App\Worker\Http\HttpFehler;
use App\Worker\Log;

/**
 * The mail intake (issue #87, E-54, docs/spec/07-worker.md section 9):
 * reads the club's IMAP mailbox and hands every mail with receipt
 * attachments to the web host, which encrypts them to VK_pub and puts a
 * receipt into the inbox - the way the public form does. The worker keeps
 * nothing: mails are held in memory only for as long as one is handed in.
 *
 * Per run (every `abruf_minuten`, from the polling loop):
 *  - not yet handled mails, oldest first, at most MAX_JE_ABRUF (and
 *    MAX_JE_TAG per day) - flood protection;
 *  - larger than the limits, marked as spam, from a sender outside the
 *    allowlist, without usable attachment or with too many/too large ones
 *    → not handed in, marked "abgelehnt" and left where it is;
 *  - else: asked first whether it was handed in before (idempotency key
 *    = SHA-256 over the Message-ID, without one over the whole mail),
 *    attachments uploaded, receipt made → marked "erledigt" and, when a
 *    target folder is set, moved there. Never deleted.
 *
 * Marks are the keywords `$Vereinsbelege`/`$VereinsbelegeAbgelehnt`; a
 * server that allows no own keywords gets \Seen (and \Flagged for refused
 * ones), and only unseen mail counts as new. A crash between handing in
 * and marking does no harm: the next run finds the receipt by its key.
 *
 * Nothing of a mail reaches the log - no sender, subject, file name;
 * only counters, UIDs and reason codes.
 */
final class MailEingang
{
    public const int MAX_JE_ABRUF = 20;

    public const int MAX_JE_TAG = 200;

    /** Smaller images are signature logos and icons, not receipts. */
    public const int MIN_BILD_BYTES = 8 * 1024;

    /**
     * Inline images (Content-ID, not marked as attachment) count only from
     * this size: Outlook logos are inline and tens of KiB, while Apple Mail
     * sends attached photos inline as well - and those are megabytes.
     */
    public const int MIN_INLINE_BILD_BYTES = 50 * 1024;

    /**
     * One run stops handing in new mail after this long - the loop's
     * heartbeat and jobs must not wait (online = heartbeat < 5 min); the
     * rest follows in the next round.
     */
    public const int MAX_LAUF_SEKUNDEN = 120;

    /** A mail that breaks the worker this often is set aside as "abgelehnt". */
    private const int MAX_FEHLVERSUCHE = 3;

    private const array TYPEN = [MagicBytes::PDF, MagicBytes::JPEG, MagicBytes::PNG, MagicBytes::XML];

    private ?int $letzterAbruf = null;

    /** The last run stopped early: the next round goes on at once. */
    private bool $mehr = false;

    private ?MailZugang $zugang = null;

    /** @var array{zeit: int, fehler: ?string, eingereicht: int, abgelehnt: int}|null */
    private ?array $status = null;

    private int $eingereicht = 0;

    private int $abgelehnt = 0;

    private string $tag = '';

    private int $heute = 0;

    /** @var array<string, int> "ordner|uid" => failures */
    private array $fehlversuche = [];

    /**
     * @param \Closure(MailZugang): Postfach $verbinden opens and logs in
     * @param \Closure(): int $uhr
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly \Closure $verbinden,
        private readonly Abbruch $abbruch,
        private readonly Log $log,
        private readonly \Closure $uhr,
    ) {
    }

    public function faellig(MailZugang $zugang): bool
    {
        return $this->zugang !== $zugang
            || $this->mehr
            || $this->letzterAbruf === null
            || ($this->uhr)() - $this->letzterAbruf >= $zugang->abrufMinuten * 60;
    }

    /**
     * What the heartbeat reports (`mail_status`): time, error code and
     * counters of the last run - null before the first.
     *
     * @return array{zeit: int, fehler: ?string, eingereicht: int, abgelehnt: int}|null
     */
    public function status(): ?array
    {
        return $this->status;
    }

    /** One run. Never throws, except for a stop request. */
    public function abrufen(MailZugang $zugang): void
    {
        $this->zugang = $zugang;
        $this->letzterAbruf = ($this->uhr)();
        $start = microtime(true);
        $vorher = [$this->eingereicht, $this->abgelehnt];
        $fehler = null;
        $postfach = null;
        $neu = 0;

        try {
            $postfach = ($this->verbinden)($zugang);
            $keywords = $postfach->auswaehlen($zugang->ordner);
            $alle = $postfach->offene($keywords, $zugang->seit);
            $neu = count($alle);
            $uids = array_slice($alle, 0, min(self::MAX_JE_ABRUF, $this->restHeute()));
            $this->mehr = false;
            $groessen = $postfach->groessen($uids);
            foreach ($uids as $i => $uid) {
                $this->abbruch->pruefen();
                if (microtime(true) - $start > self::MAX_LAUF_SEKUNDEN) {
                    $this->mehr = true;
                    break;
                }
                // A mail whose size the server did not report is not fetched blind.
                $this->eineMail($postfach, $zugang, $keywords, $uid, $groessen[$uid] ?? PHP_INT_MAX);
                ++$this->heute;
                if ($i === count($uids) - 1 && count($alle) > count($uids) && $this->restHeute() > 0) {
                    $this->mehr = true;
                }
            }
        } catch (ImapFehler $e) {
            $fehler = $e->fehlercode;
        } catch (ApiFehler | HttpFehler) {
            $fehler = 'webhoster';
        } finally {
            $postfach?->abmelden();
            $this->status = [
                'zeit' => $this->letzterAbruf,
                'fehler' => $fehler,
                'eingereicht' => $this->eingereicht,
                'abgelehnt' => $this->abgelehnt,
            ];
            $this->log->ereignis('mail_abruf', [
                'neu' => $neu,
                'eingereicht' => $this->eingereicht - $vorher[0],
                'abgelehnt' => $this->abgelehnt - $vorher[1],
                'fehler' => $fehler,
                'sekunden' => round(microtime(true) - $start, 1),
            ]);
        }
    }

    /** Drops what was learned about the mailbox (access gone). */
    public function vergessen(): void
    {
        $this->zugang = null;
        $this->letzterAbruf = null;
        $this->mehr = false;
        $this->fehlversuche = [];
    }

    /**
     * @throws ImapFehler|ApiFehler|HttpFehler|Abgebrochen when the mailbox
     *         or the web host as a whole fails - the run ends
     */
    private function eineMail(Postfach $postfach, MailZugang $zugang, bool $keywords, int $uid, int $groesse): void
    {
        if ($groesse > $zugang->maxMailBytes()) {
            $this->ablehnen($postfach, $keywords, $uid, 'zu_gross');

            return;
        }
        try {
            $roh = $postfach->holen($uid);
            $nachricht = MimeNachricht::lesen($roh);
            $schluessel = hash('sha256', $nachricht->messageId !== null ? 'mid|' . $nachricht->messageId : 'roh|' . $roh);
            unset($roh);
            [$anhaenge, $grund] = $this->pruefen($nachricht, $zugang);
            if ($grund === null) {
                $grund = $this->einreichen($nachricht, $anhaenge, $schluessel);
            }
        } catch (MailFehlschlag $e) {
            // This mail fails where others would not (a FETCH the server
            // cannot answer, the web host refusing one attachment): try it
            // again next time, set it aside after a few attempts - one bad
            // mail must not hold up every later one.
            $this->fehlschlag($postfach, $zugang, $keywords, $uid, $e->getMessage());

            return;
        } catch (ImapFehler $e) {
            if ($e->fehlercode !== 'imap_protokoll') {
                throw $e;
            }
            $this->fehlschlag($postfach, $zugang, $keywords, $uid, $e->fehlercode);

            return;
        } catch (ApiFehler | HttpFehler | Abgebrochen $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->fehlschlag($postfach, $zugang, $keywords, $uid, (new \ReflectionClass($e))->getShortName());

            return;
        }
        if ($grund !== null) {
            $this->ablehnen($postfach, $keywords, $uid, $grund);

            return;
        }

        $postfach->markieren($uid, $keywords ? [ImapClient::KEYWORD_ERLEDIGT, '\\Seen'] : ['\\Seen']);
        unset($this->fehlversuche[$zugang->ordner . '|' . $uid]);
        if ($zugang->zielOrdner !== '' && !$postfach->verschieben($uid, $zugang->zielOrdner)) {
            $this->log->ereignis('mail_nicht_verschoben', ['uid' => $uid]);
        }
    }

    private function fehlschlag(Postfach $postfach, MailZugang $zugang, bool $keywords, int $uid, string $fehler): void
    {
        $kennung = $zugang->ordner . '|' . $uid;
        $this->fehlversuche[$kennung] = ($this->fehlversuche[$kennung] ?? 0) + 1;
        $this->log->ereignis('mail_fehler', ['uid' => $uid, 'fehler' => $fehler, 'versuch' => $this->fehlversuche[$kennung]]);
        if ($this->fehlversuche[$kennung] >= self::MAX_FEHLVERSUCHE) {
            unset($this->fehlversuche[$kennung]);
            $this->ablehnen($postfach, $keywords, $uid, 'unlesbar');
        }
    }

    /**
     * The attachments to hand in, or why the mail is not handed in.
     *
     * @return array{0: list<array{0: MimeAnhang, 1: string}>, 1: ?string}
     */
    private function pruefen(MimeNachricht $nachricht, MailZugang $zugang): array
    {
        if ($nachricht->spam) {
            return [[], 'spam'];
        }
        if (!$zugang->absender->erlaubt(filter_var($nachricht->absenderAdresse, FILTER_VALIDATE_EMAIL) === false ? null : $nachricht->absenderAdresse)) {
            return [[], 'absender_nicht_erlaubt'];
        }

        return self::auswahl($nachricht, $zugang);
    }

    /**
     * The attachments that become pages: PDF, JPEG, PNG or an e-invoice
     * XML by their magic bytes; no small images (logos, icons - inline
     * ones count only from MIN_INLINE_BILD_BYTES), no duplicates.
     *
     * @return array{0: list<array{0: MimeAnhang, 1: string}>, 1: ?string} attachments with their type, or [] and the reason
     */
    public static function auswahl(MimeNachricht $nachricht, MailZugang $zugang): array
    {
        $auswahl = [];
        $gesehen = [];
        $summe = 0;
        foreach ($nachricht->anhaenge as $anhang) {
            $typ = MagicBytes::detect(substr($anhang->inhalt, 0, MagicBytes::HEAD_BYTES));
            if ($typ === null || !in_array($typ, self::TYPEN, true)) {
                continue;
            }
            $bild = $typ === MagicBytes::JPEG || $typ === MagicBytes::PNG;
            if ($bild && strlen($anhang->inhalt) < ($anhang->inline ? self::MIN_INLINE_BILD_BYTES : self::MIN_BILD_BYTES)) {
                continue;
            }
            $hash = hash('sha256', $anhang->inhalt);
            if (isset($gesehen[$hash])) {
                continue;
            }
            $gesehen[$hash] = true;
            if (strlen($anhang->inhalt) > $zugang->maxDateiBytes) {
                return [[], 'anhang_zu_gross'];
            }
            $summe += strlen($anhang->inhalt);
            $auswahl[] = [$anhang, $typ];
        }
        if ($auswahl === []) {
            return [[], 'keine_anhaenge'];
        }
        if (count($auswahl) > $zugang->maxSeiten) {
            return [[], 'zu_viele_anhaenge'];
        }
        if ($summe > $zugang->maxEinreichungBytes) {
            return [[], 'zu_gross'];
        }

        return [$auswahl, null];
    }

    /**
     * Hands the mail in. Null when it is in the inbox (now or before),
     * else the web host's reason for refusing it.
     *
     * @param list<array{0: MimeAnhang, 1: string}> $anhaenge
     *
     * @throws ApiFehler|HttpFehler|Abgebrochen when the web host as a whole fails
     * @throws MailFehlschlag when it fails for this mail only
     */
    private function einreichen(MimeNachricht $nachricht, array $anhaenge, string $schluessel): ?string
    {
        if (($this->api->json('POST', '/mail/pruefen', ['schluessel' => $schluessel])['vorhanden'] ?? false) === true) {
            // Handed in before, only the mark got lost - not counted again.
            return null;
        }
        // The web host answered just now: what fails from here on is about
        // this mail (an attachment it cannot store), unless the network goes.
        $blobs = [];
        try {
            foreach ($anhaenge as [$anhang]) {
                $blobs[] = $this->hochladen($anhang, $schluessel);
            }
            $antwort = $this->api->json('POST', '/mail/einreichen', [
                'schluessel' => $schluessel,
                'blobs' => $blobs,
                'absender' => ['name' => $nachricht->absenderName, 'adresse' => $nachricht->absenderAdresse],
                'betreff' => $nachricht->betreff,
            ]);
        } catch (ApiFehler $e) {
            // The mail itself is refused (type, size, sender): set it aside.
            if (in_array($e->status, [413, 415, 422], true)) {
                return $e->fehler;
            }
            // Switched off or not authenticated: the run ends, the mail waits.
            if (in_array($e->status, [401, 403], true) || $e->fehler === 'mail_eingang_aus') {
                throw $e;
            }

            throw new MailFehlschlag($e->fehler, $e);
        }
        if (($antwort['status'] ?? null) === 'eingereicht') {
            ++$this->eingereicht;
        }
        $this->log->ereignis('mail_eingereicht', ['status' => is_string($antwort['status'] ?? null) ? $antwort['status'] : null]);

        return null;
    }

    /** @throws ApiFehler|HttpFehler|Abgebrochen */
    private function hochladen(MimeAnhang $anhang, string $schluessel): int
    {
        $ticket = $this->api->json('POST', '/mail/uploads', ['schluessel' => $schluessel, 'groesse' => strlen($anhang->inhalt)]);
        $id = is_string($ticket['id'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $ticket['id']) === 1 ? $ticket['id'] : throw new HttpFehler('Unerwartete Antwort des Webhosters.');
        $stueck = is_int($ticket['chunk_bytes'] ?? null) && $ticket['chunk_bytes'] > 0 ? $ticket['chunk_bytes'] : 2 * 1024 * 1024;
        $anzahl = (int) ceil(strlen($anhang->inhalt) / $stueck);
        for ($n = 0; $n < $anzahl; ++$n) {
            $this->api->roh('/mail/uploads/' . $id . '/chunks/' . $n, substr($anhang->inhalt, $n * $stueck, $stueck));
        }
        $fertig = $this->api->json('POST', '/mail/uploads/' . $id . '/finish', ['schluessel' => $schluessel, 'name' => $anhang->name]);

        return is_int($fertig['blob_id'] ?? null) ? $fertig['blob_id'] : throw new HttpFehler('Unerwartete Antwort des Webhosters.');
    }

    private function ablehnen(Postfach $postfach, bool $keywords, int $uid, string $grund): void
    {
        $postfach->markieren($uid, $keywords ? [ImapClient::KEYWORD_ABGELEHNT] : ['\\Seen', '\\Flagged']);
        ++$this->abgelehnt;
        $this->log->ereignis('mail_abgelehnt', ['uid' => $uid, 'grund' => $grund]);
    }

    private function restHeute(): int
    {
        $tag = date('Y-m-d', ($this->uhr)());
        if ($tag !== $this->tag) {
            $this->tag = $tag;
            $this->heute = 0;
        }

        return max(0, self::MAX_JE_TAG - $this->heute);
    }
}
