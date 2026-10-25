<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Domain\BlobMeta;
use App\Domain\WorkerFaehigkeit;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Document\PdfErzeugung;
use App\Service\Upload\MagicBytes;
use App\Service\Worker\MailEingangEinstellungen;
use App\Service\Worker\MailEingangSpeicher;
use App\Service\Worker\WorkerRouting;
use App\Tests\Service\Worker\WorkerTestCase;
use App\Tests\Worker\Mail\MimeNachrichtTest;
use App\Tests\Worker\Support\FakePostfach;
use App\Tests\Worker\Support\InProzessHttp;
use App\Worker\Abbruch;
use App\Worker\ApiClient;
use App\Worker\Arbeitsverzeichnis;
use App\Worker\Identitaet;
use App\Worker\KiKonfiguration;
use App\Worker\Log;
use App\Worker\Mail\ImapFehler;
use App\Worker\Mail\MailEingang;
use App\Worker\Mail\MailZugang;
use App\Worker\Mail\VersuchsSpeicher;
use App\Worker\Schleife;

/**
 * The mail intake end to end (issue #87, E-54, 07 section 9): the worker's
 * loop gets the IMAP access sealed with its configuration, reads an
 * in-memory mailbox and hands receipts in through the real signature check
 * into the real WorkerMailController - the receipt lands in the database
 * like a public submission, readable only with the vault.
 */
final class MailEingangTest extends WorkerTestCase
{
    private const string BASIS = 'https://belege.verein.de';

    private const string PASSWORT = 'Imap-Geheimnis-42';

    private FakePostfach $postfach;

    private KiKonfiguration $ki;

    private Schleife $schleife;

    private string $log = '';

    /** @var list<MailZugang> */
    private array $verbindungen = [];

    private int $uhr;

    /** Minutes passed for the mail intake only - the signatures keep the real time. */
    private int $spaeter = 0;

    private int $workerId = 0;

    private string $schluessel = '';

    private ApiClient $apiClient;

    private Abbruch $abbruch;

    private Log $workerLog;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->workerId, $this->schluessel] = $this->koppeln();
        $identitaet = new Identitaet($this->workerId, $this->schluessel, $this->workerSchluesselpaar, self::BASIS, 'Pi');
        $arbeit = $this->tmp . '/arbeit';
        mkdir($arbeit, 0700);
        $this->uhr = time();
        $abbruch = $this->abbruch = new Abbruch();
        $log = $this->workerLog = new Log(function (string $zeile): void {
            $this->log .= $zeile;
        });
        $uhr = fn(): int => $this->uhr;
        $schlafen = static fn(float $s): bool => true;
        $api = $this->apiClient = new ApiClient(new InProzessHttp($this->auth, $this->api, $this->mailApi), $identitaet, $abbruch, $uhr, $schlafen);
        $this->postfach = new FakePostfach();
        $this->ki = new KiKonfiguration();
        $this->schleife = new Schleife(
            $api,
            $identitaet,
            $this->ki,
            new Arbeitsverzeichnis($arbeit, 'tmpfs ' . realpath($arbeit) . " tmpfs rw 0 0\n"),
            [],
            ['ai', 'mail'],
            '2.0.0',
            $abbruch,
            $log,
            $uhr,
            $schlafen,
            $this->neuerEingang(),
        );
        $this->einrichten();
    }

    public function testAMailWithAnInvoiceBecomesAReceiptReadableOnlyWithTheVault(): void
    {
        $uid = $this->postfach->hinzufuegen($this->rechnungsMail());

        $this->schleife->runde();

        self::assertCount(1, $this->verbindungen);
        self::assertSame('imap.verein.de', $this->verbindungen[0]->host);
        self::assertSame(self::PASSWORT, $this->verbindungen[0]->passwort, 'the access arrived sealed with the configuration');
        self::assertSame(['$Vereinsbelege', '\\Seen'], $this->postfach->flags[$uid]);
        self::assertSame('Belege/Erledigt', $this->postfach->verschoben[$uid]);

        $zeilen = $this->pdo()->query('SELECT s.id, s.reference_code, s.dek_sealed, s.payload_enc, d.id AS doc, d.source, d.original_blob_ids FROM submission s JOIN document d ON d.submission_id = s.id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $zeilen);
        $z = $zeilen[0];
        self::assertSame('einreichung', $z['source']);
        $payload = json_decode(FieldCipher::decrypt($this->vault->openDataKey($z['dek_sealed']), $z['payload_enc'], new FieldContext('submission', (int) $z['id'], 'payload_enc')), true);
        self::assertSame([
            'name' => 'Müller Getränke',
            'freitext' => 'Rechnung für Sommerfest',
            'email' => 'rechnung@mueller-getraenke.de',
            'kanal' => 'mail',
        ], $payload);

        $blobIds = json_decode($z['original_blob_ids'], true);
        self::assertCount(1, $blobIds);
        $blob = $this->blobService->find($blobIds[0]);
        self::assertNotNull($blob);
        self::assertSame(MimeNachrichtTest::PDF, implode('', iterator_to_array($this->blobService->openRead($blob, $this->vault), false)));
        self::assertFalse(sodium_crypto_box_seal_open(substr($blob->dekSealed, 1), $this->workerSchluesselpaar), 'the worker cannot read it afterwards');
        self::assertTrue($this->jobs->offenFuer([PdfErzeugung::JOB_TYP], 'document', (int) $z['doc']), 'the same chain as a public submission');

        // Nothing of the mail in the log, nothing of the access in the database.
        foreach (['Müller', 'mueller', 'Sommerfest', 'Rechnung', self::PASSWORT] as $verboten) {
            self::assertStringNotContainsString($verboten, $this->log);
        }
        $settings = implode('|', $this->pdo()->query("SELECT value FROM setting WHERE name LIKE 'mail_eingang%'")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertStringNotContainsString(self::PASSWORT, $settings);
        self::assertStringNotContainsString('kasse@verein.de', $settings, 'the allowlist is encrypted as well');

        // Reported with the next heartbeat, for the admin page.
        $this->schleife->runde();
        $status = $this->mailEingang->status();
        self::assertNotNull($status);
        self::assertSame(1, $status['eingereicht']);
        self::assertNull($status['fehler']);
    }

    public function testAMailHandedInTwiceGivesOneReceipt(): void
    {
        $roh = $this->rechnungsMail();
        $erste = $this->postfach->hinzufuegen($roh);
        $this->schleife->runde();
        // The mark got lost (crash, a person removed it): the same mail again.
        $this->postfach->flags[$erste] = [];
        unset($this->postfach->verschoben[$erste]);
        $zweite = $this->postfach->hinzufuegen($roh);
        $this->spaeter += 10 * 60;

        $this->schleife->runde();

        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
        $this->schleife->runde();
        self::assertSame(1, $this->mailEingang->status()['eingereicht'] ?? null, 'counted once');
        self::assertContains('$Vereinsbelege', $this->postfach->flags[$erste], $this->log);
        self::assertContains('$Vereinsbelege', $this->postfach->flags[$zweite]);
    }

    public function testAPhotoSentInlineByAPhoneIsAReceiptALogoIsNot(): void
    {
        // Apple Mail: the photo inline with Content-ID, a megabyte-size JPEG.
        $foto = "\xFF\xD8\xFF\xE0" . random_bytes(60 * 1024);
        $roh = MimeNachrichtTest::mail(
            "From: kasse@verein.de\nSubject: Quittung\nMessage-ID: <foto@iphone>\nContent-Type: multipart/mixed; boundary=b",
            "--b\r\nContent-Type: image/jpeg; name=IMG_1234.jpeg\r\nContent-Disposition: inline; filename=IMG_1234.jpeg\r\nContent-Id: <x1>\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($foto))
            . "--b\r\nContent-Type: image/png\r\nContent-Disposition: inline; filename=logo.png\r\nContent-Id: <x2>\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode("\x89PNG\r\n\x1A\n" . random_bytes(20 * 1024))) . "--b--\r\n",
        );
        $uid = $this->postfach->hinzufuegen($roh);

        $this->schleife->runde();

        self::assertContains('$Vereinsbelege', $this->postfach->flags[$uid]);
        $ids = json_decode((string) $this->pdo()->query('SELECT original_blob_ids FROM document')->fetchColumn(), true);
        self::assertCount(1, $ids, 'the photo, not the logo');
    }

    public function testAMailThatAlwaysFailsDoesNotHoldUpTheOthers(): void
    {
        $kaputt = $this->postfach->hinzufuegen($this->rechnungsMail());
        $this->postfach->kaputt = [$kaputt];

        for ($runde = 1; $runde <= 3; ++$runde) {
            $gut = $this->postfach->hinzufuegen(str_replace('r-1@', 'r-' . ($runde + 1) . '@', $this->rechnungsMail()));
            $this->schleife->runde();
            self::assertContains('$Vereinsbelege', $this->postfach->flags[$gut], 'later mail goes on in run ' . $runde);
            $this->spaeter += 5 * 60;
        }

        self::assertSame(['$VereinsbelegeAbgelehnt'], $this->postfach->flags[$kaputt], 'set aside after three attempts');
        self::assertStringContainsString('"grund":"unlesbar"', $this->log);
    }

    /**
     * Issue #243: a broken connection mid-mail counts as one attempt - kept
     * as keyword at the mail - and the mail is tried again, three times in
     * all; only then is it set aside, and without being fetched once more.
     */
    public function testAMailIsTriedThreeTimesWhenTheConnectionBreaksThenSetAside(): void
    {
        $uid = $this->postfach->hinzufuegen($this->rechnungsMail());
        $this->postfach->beimHolen = static fn(int $u) => throw new ImapFehler('imap_verbindung');

        $erwartet = [
            ['$VereinsbelegeVersuch'],
            ['$VereinsbelegeVersuch', '$VereinsbelegeVersuch2'],
            ['$VereinsbelegeVersuch', '$VereinsbelegeVersuch2', '$VereinsbelegeVersuch3'],
        ];
        foreach ($erwartet as $versuch => $marken) {
            $this->schleife->runde();
            self::assertSame($marken, $this->postfach->flagsBeimHolen[$uid], 'attempt ' . ($versuch + 1) . ' is counted before the fetch');
            self::assertSame($marken, $this->postfach->flags[$uid], 'and stays when the connection is gone');
            $this->spaeter += 5 * 60;
        }
        $this->postfach->beimHolen = null;
        $this->schleife->runde();

        self::assertSame(3, $this->postfach->geholt[$uid], 'not fetched a fourth time');
        self::assertSame(['$VereinsbelegeAbgelehnt'], $this->postfach->flags[$uid], 'set aside, the attempts cleared');
        self::assertStringContainsString('"grund":"unlesbar"', $this->log);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
    }

    /** IMAP flags are case-insensitive: a server that lower-cases them still counts. */
    public function testAttemptMarksCountWhateverTheCase(): void
    {
        $uid = $this->postfach->hinzufuegen($this->rechnungsMail());
        $this->postfach->flags[$uid] = ['$vereinsbelegeversuch', '$VEREINSBELEGEVERSUCH2', '$vereinsbelegeVersuch3'];

        $this->schleife->runde();

        self::assertArrayNotHasKey($uid, $this->postfach->geholt, 'three attempts: not fetched again');
        self::assertSame([$uid], $this->postfach->kopfAbgefragt);
    }

    /**
     * A run that died while reading a mail (out of memory, killed) left the
     * attempt mark on it: the next run tries again rather than giving up
     * at once - only a mail that carries all three is set aside.
     */
    public function testAMailTheWorkerDiedOnIsTriedAgainUpToThreeTimes(): void
    {
        $einmal = $this->postfach->hinzufuegen($this->rechnungsMail());
        $this->postfach->flags[$einmal] = ['$VereinsbelegeVersuch'];
        $dreimal = $this->postfach->hinzufuegen(str_replace('r-1@', 'r-9@', $this->rechnungsMail()));
        $this->postfach->flags[$dreimal] = ['$VereinsbelegeVersuch', '$VereinsbelegeVersuch2', '$VereinsbelegeVersuch3'];

        $this->schleife->runde();

        self::assertSame(['$VereinsbelegeVersuch', '$VereinsbelegeVersuch2'], $this->postfach->flagsBeimHolen[$einmal], 'the second attempt');
        self::assertSame(['$Vereinsbelege', '\\Seen'], $this->postfach->flags[$einmal], 'handed in, the attempts cleared before the move');
        self::assertArrayNotHasKey($dreimal, $this->postfach->geholt, 'not fetched again');
        self::assertSame(['$VereinsbelegeAbgelehnt'], $this->postfach->flags[$dreimal]);
        self::assertStringContainsString('"grund":"unlesbar"', $this->log);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
    }

    /**
     * The worker died after handing the mail in, before marking it - the
     * next attempt finds the receipt by its key; and when all attempts are
     * used up, the Message-ID alone shows it is in the inbox: "erledigt",
     * never "abgelehnt".
     */
    public function testAMailHandedInBeforeTheCrashIsNotSetAside(): void
    {
        $roh = $this->rechnungsMail();
        $uid = $this->postfach->hinzufuegen($roh);
        $this->schleife->runde();
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());

        // As if every attempt had died between handing in and marking.
        foreach ([1, 3] as $versuche) {
            $this->postfach->flags[$uid] = array_slice(\App\Worker\Mail\ImapClient::KEYWORDS_VERSUCH, 0, $versuche);
            unset($this->postfach->verschoben[$uid], $this->postfach->geholt[$uid]);
            $this->spaeter += 5 * 60;

            $this->schleife->runde();

            self::assertSame(['$Vereinsbelege', '\\Seen'], $this->postfach->flags[$uid], $versuche . ' attempts: erledigt');
            self::assertSame('Belege/Erledigt', $this->postfach->verschoben[$uid] ?? null);
        }
        self::assertArrayNotHasKey($uid, $this->postfach->geholt, 'the last time matched by its Message-ID alone');
        self::assertSame([$uid], $this->postfach->kopfAbgefragt);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
        self::assertStringNotContainsString('"grund"', $this->log);
    }

    /** The web host failing as a whole is not the mail's fault: the attempt is taken back. */
    public function testAnAttemptCutShortByTheWebHostDoesNotCount(): void
    {
        $uid = $this->postfach->hinzufuegen($this->rechnungsMail());
        // Switched off while the mail is read: the web host answers 409.
        $this->postfach->beimHolen = fn(int $u) => $this->settings->set(WorkerRouting::SETTING_AKTIV, '0');

        $this->schleife->runde();

        self::assertSame(['$VereinsbelegeVersuch'], $this->postfach->flagsBeimHolen[$uid]);
        self::assertSame([], $this->postfach->flags[$uid], 'the attempt was taken back');
        self::assertStringContainsString('"fehler":"webhoster"', $this->log);
    }

    public function testRefusedMailsAreMarkedAndStay(): void
    {
        $spam = $this->postfach->hinzufuegen(MimeNachrichtTest::mail("From: x@spam.example\nX-Spam-Flag: YES\nContent-Type: multipart/mixed; boundary=b", self::anhang('b', MimeNachrichtTest::PDF)));
        $fremd = $this->postfach->hinzufuegen(MimeNachrichtTest::mail("From: fremd@example.org\nContent-Type: multipart/mixed; boundary=b", self::anhang('b', MimeNachrichtTest::PDF)));
        $ohne = $this->postfach->hinzufuegen(MimeNachrichtTest::mail("From: kasse@verein.de\nSubject: Hallo", 'Nur Text'));
        $logo = $this->postfach->hinzufuegen(MimeNachrichtTest::mail("From: kasse@verein.de\nContent-Type: multipart/mixed; boundary=b", self::anhang('b', "\x89PNG\r\n\x1A\n" . str_repeat('x', 500), 'image/png', 'logo.png')));
        $vieleTeile = '';
        // One more than the public form's page limit (20).
        for ($i = 0; $i < 21; ++$i) {
            $vieleTeile .= self::anhang('b', MimeNachrichtTest::PDF . $i, schluss: false);
        }
        $viele = $this->postfach->hinzufuegen(MimeNachrichtTest::mail("From: kasse@verein.de\nContent-Type: multipart/mixed; boundary=b", $vieleTeile . "--b--\r\n"));

        $this->schleife->runde();

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
        foreach ([$spam, $fremd, $ohne, $logo, $viele] as $uid) {
            self::assertSame(['$VereinsbelegeAbgelehnt'], $this->postfach->flags[$uid]);
            self::assertArrayNotHasKey($uid, $this->postfach->verschoben, 'refused mail stays where a person sees it');
        }
        foreach (['spam', 'absender_nicht_erlaubt', 'keine_anhaenge', 'zu_viele_anhaenge'] as $grund) {
            self::assertStringContainsString('"grund":"' . $grund . '"', $this->log);
        }
    }

    public function testWithoutOwnKeywordsHandledMailIsMarkedSeen(): void
    {
        $this->postfach->keywords = false;
        $this->postfach->kannVerschieben = false;
        $gut = $this->postfach->hinzufuegen($this->rechnungsMail());
        $schlecht = $this->postfach->hinzufuegen(MimeNachrichtTest::mail("From: kasse@verein.de\nSubject: x", 'Text'));

        $this->schleife->runde();

        self::assertSame(['\\Seen'], $this->postfach->flags[$gut]);
        self::assertEqualsCanonicalizing(['\\Seen', '\\Flagged'], $this->postfach->flags[$schlecht]);
        self::assertStringContainsString('mail_nicht_verschoben', $this->log);
    }

    /**
     * Without own keywords \\Flagged is the person's (issue #243): a mail
     * flagged by hand is handed in like any other and keeps its flag; the
     * attempts are counted in memory, a broken mail is set aside after three.
     */
    public function testWithoutOwnKeywordsAFlaggedMailIsNotSetAside(): void
    {
        $this->postfach->keywords = false;
        $markiert = $this->postfach->hinzufuegen($this->rechnungsMail());
        $this->postfach->flags[$markiert] = ['\\Flagged'];
        $abbruch = $this->postfach->hinzufuegen(str_replace('r-1@', 'r-2@', $this->rechnungsMail()));
        $this->postfach->beimHolen = function (int $u) use ($abbruch): void {
            if ($u === $abbruch) {
                throw new ImapFehler('imap_verbindung');
            }
        };

        for ($runde = 1; $runde <= 4; ++$runde) {
            $this->schleife->runde();
            $this->spaeter += 5 * 60;
        }

        self::assertEqualsCanonicalizing(['\\Flagged', '\\Seen'], $this->postfach->flags[$markiert], 'handed in, the flag left alone');
        self::assertSame(1, $this->postfach->geholt[$markiert]);
        self::assertNotContains('\\Flagged', $this->postfach->flagsBeimHolen[$abbruch], 'no mark of ours');
        self::assertSame(3, $this->postfach->geholt[$abbruch]);
        self::assertEqualsCanonicalizing(['\\Seen', '\\Flagged'], $this->postfach->flags[$abbruch], 'set aside after three attempts');
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
    }

    /**
     * Review of #243: without own keywords the attempts survive a killed
     * worker - in /daten, not in memory. A mail that kills the worker on
     * every start is set aside after three, not fetched after every restart.
     */
    public function testWithoutOwnKeywordsAttemptsSurviveARestart(): void
    {
        $this->postfach->keywords = false;
        $uid = $this->postfach->hinzufuegen($this->rechnungsMail());
        // What a kill looks like from the counter's side: the attempt began
        // and never ended.
        $this->postfach->beimHolen = static fn(int $u) => throw new ImapFehler('imap_verbindung');

        $this->schleife->runde();
        $zugang = $this->ki->mailZugang();
        self::assertNotNull($zugang);
        for ($neustart = 2; $neustart <= 4; ++$neustart) {
            // A fresh process: nothing in memory, only the file.
            $this->neuerEingang()->abrufen($zugang);
        }

        self::assertSame(3, $this->postfach->geholt[$uid], 'three attempts across restarts, not a fourth');
        self::assertEqualsCanonicalizing(['\\Seen', '\\Flagged'], $this->postfach->flags[$uid], 'then set aside');
        self::assertStringContainsString('"grund":"unlesbar"', $this->log);
        $inhalt = (string) file_get_contents($this->versuchsDatei());
        self::assertSame('{"format":1,"eintraege":[]}', $inhalt, 'cleared once handled');
        self::assertSame(0o600, fileperms($this->versuchsDatei()) & 0o777);
    }

    public function testTheAttemptFileHoldsNoMailContent(): void
    {
        $this->postfach->keywords = false;
        $this->postfach->hinzufuegen($this->rechnungsMail());
        $this->postfach->beimHolen = static fn(int $u) => throw new ImapFehler('imap_verbindung');

        $this->schleife->runde();

        $inhalt = (string) file_get_contents($this->versuchsDatei());
        self::assertMatchesRegularExpression('/^\{"format":1,"eintraege":\{"[0-9a-f]{64}":\[1,\d+\]\}\}$/', $inhalt);
        foreach (['INBOX', 'mueller', 'r-1'] as $verboten) {
            self::assertStringNotContainsString($verboten, $inhalt);
        }
    }

    /**
     * Review of #243: a mail without Message-ID handed in on its last
     * attempt, the worker killed before marking it - matched by its header
     * block, "erledigt", not "abgelehnt".
     */
    public function testAMailWithoutMessageIdHandedInBeforeTheCrashIsNotSetAside(): void
    {
        $uid = $this->postfach->hinzufuegen(str_replace("Message-ID: <r-1@mueller-getraenke.de>\r\n", '', $this->rechnungsMail()));
        self::assertStringNotContainsString('Message-ID', $this->postfach->mails[$uid]);
        $this->schleife->runde();
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());

        $this->postfach->flags[$uid] = \App\Worker\Mail\ImapClient::KEYWORDS_VERSUCH;
        unset($this->postfach->verschoben[$uid], $this->postfach->geholt[$uid]);
        $this->spaeter += 5 * 60;
        $this->schleife->runde();

        self::assertArrayNotHasKey($uid, $this->postfach->geholt, 'not fetched again');
        self::assertSame([$uid], $this->postfach->kopfAbgefragt);
        self::assertSame(['$Vereinsbelege', '\\Seen'], $this->postfach->flags[$uid]);
        self::assertStringNotContainsString('"grund"', $this->log);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
    }

    /**
     * N-17: the web host limits mails per worker and hour itself; over the
     * limit the worker ends its run, the mail waits unmarked and uncounted.
     */
    public function testTheWebHostsLimitStopsTheRunAndTheMailWaits(): void
    {
        $this->settings->set(\App\Service\Worker\MailEingangGrenze::SETTING_MAILS_STUNDE, '1');
        $erste = $this->postfach->hinzufuegen($this->rechnungsMail());
        $zweite = $this->postfach->hinzufuegen(str_replace('r-1@', 'r-2@', $this->rechnungsMail()));

        $this->schleife->runde();

        self::assertSame(['$Vereinsbelege', '\\Seen'], $this->postfach->flags[$erste]);
        self::assertSame([], $this->postfach->flags[$zweite], 'waits, the attempt taken back');
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
        // The admin page shows why with the next heartbeat.
        $this->schleife->runde();
        self::assertSame('mail_limit', $this->mailEingang->status()['fehler'] ?? null);
    }

    public function testTheMailboxIsReadEveryIntervalAndAtMostTwentyAtOnce(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $this->postfach->hinzufuegen(MimeNachrichtTest::mail("From: kasse@verein.de\nSubject: x" . $i, 'Text'));
        }

        $this->schleife->runde();
        self::assertCount(20, array_filter($this->postfach->flags), 'at most twenty per run');
        $this->schleife->runde();
        self::assertCount(2, $this->verbindungen, 'the rest follows in the next round');
        self::assertCount(25, array_filter($this->postfach->flags));

        $this->schleife->runde();
        self::assertCount(2, $this->verbindungen, 'then not before the interval is over');
        $this->spaeter += 5 * 60;
        $this->schleife->runde();
        self::assertCount(3, $this->verbindungen);
        self::assertSame(date('Y-m-d'), $this->postfach->seit[0], 'only mail since the intake was switched on');
    }

    public function testAnImapErrorReachesTheAdminPage(): void
    {
        $this->postfach->fehler = new ImapFehler('imap_anmeldung');

        $this->schleife->runde();
        $this->schleife->runde();

        $status = $this->mailEingang->status();
        self::assertNotNull($status);
        self::assertSame('imap_anmeldung', $status['fehler']);
    }

    public function testWithoutReleaseTheWorkerGetsNoAccessAndDropsAnOldOne(): void
    {
        $this->schleife->runde();
        self::assertNotNull($this->ki->mailZugang());

        // Switched off in the admin: the next configuration has no access.
        $e = $this->mailEingang->laden();
        $this->mailEingang->speichern(new MailEingangEinstellungen(false, $e->host, $e->port, $e->sicherheit, $e->benutzer, $e->passwort, $e->ordner, $e->zielOrdner, $e->absender, $e->abrufMinuten));
        $this->schleife->runde();
        self::assertNull($this->ki->mailZugang());

        // On again, but the module off: no access either.
        $this->mailEingang->speichern($e);
        $this->settings->set(WorkerRouting::SETTING_AKTIV, '0');
        $this->schleife->runde();
        self::assertNull($this->ki->mailZugang());
        self::assertCount(1, $this->verbindungen);
    }

    public function testTheWebHostRefusesWhatTheWorkerShouldNotSend(): void
    {
        $schluessel = hash('sha256', 'x');
        $body = json_encode(['schluessel' => $schluessel, 'groesse' => 20], JSON_THROW_ON_ERROR);
        // Not before the worker reported `mail`.
        $this->workers->heartbeat($this->workerId, 1, '2.0.0', [WorkerFaehigkeit::Ai], new \DateTimeImmutable());
        $antwort = $this->aufruf($this->signiert($this->workerId, $this->schluessel, '/api/worker/mail/uploads', $body), fn($r, $p, $w) => $this->mailApi->uploadAnlegen($r, $w));
        self::assertSame(409, $antwort->status);
        $this->workers->heartbeat($this->workerId, 1, '2.0.0', WorkerFaehigkeit::cases(), new \DateTimeImmutable());

        // An HTML page is no receipt, whatever the worker thinks.
        $html = '<html><body>Rechnung</body></html>';
        $ticket = self::daten($this->aufruf($this->signiert($this->workerId, $this->schluessel, '/api/worker/mail/uploads', json_encode(['schluessel' => $schluessel, 'groesse' => strlen($html)], JSON_THROW_ON_ERROR)), fn($r, $p, $w) => $this->mailApi->uploadAnlegen($r, $w)));
        $pfad = '/api/worker/mail/uploads/' . $ticket['id'];
        $this->aufruf($this->signiert($this->workerId, $this->schluessel, $pfad . '/chunks/0', $html), fn($r, $p, $w) => $this->mailApi->uploadChunk($r, $p, $w), ['upload' => $ticket['id'], 'n' => '0']);
        $fertig = $this->aufruf($this->signiert($this->workerId, $this->schluessel, $pfad . '/finish', json_encode(['schluessel' => $schluessel, 'name' => 'x.html'], JSON_THROW_ON_ERROR)), fn($r, $p, $w) => $this->mailApi->uploadAbschliessen($r, $p, $w), ['upload' => $ticket['id']]);
        self::assertSame(415, $fertig->status);

        // A sender outside the allowlist is refused here too, and a blob of
        // another mail cannot be claimed.
        $einreichen = fn(array $daten) => $this->aufruf($this->signiert($this->workerId, $this->schluessel, '/api/worker/mail/einreichen', json_encode($daten, JSON_THROW_ON_ERROR)), fn($r, $p, $w) => $this->mailApi->einreichen($r, $w));
        $abgelehnt = $einreichen(['schluessel' => $schluessel, 'blobs' => [1], 'absender' => ['name' => '', 'adresse' => 'fremd@example.org'], 'betreff' => 'x']);
        self::assertSame(422, $abgelehnt->status);
        self::assertSame('absender_nicht_erlaubt', self::daten($abgelehnt)['fehler']);
        $fremderBlob = $this->blobService->storeString(MimeNachrichtTest::PDF, new BlobMeta(MagicBytes::PDF, 'r.pdf'), $this->vault);
        $abgelehnt = $einreichen(['schluessel' => $schluessel, 'blobs' => [$fremderBlob->id], 'absender' => ['name' => '', 'adresse' => 'kasse@verein.de'], 'betreff' => 'x']);
        self::assertSame('anhang_unbekannt', self::daten($abgelehnt)['fehler']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM submission')->fetchColumn());
    }

    /** N-17: bytes per worker and hour, checked before an upload ticket is handed out. */
    public function testTheWebHostLimitsTheBytesPerHour(): void
    {
        $this->settings->set(\App\Service\Worker\MailEingangGrenze::SETTING_MB_STUNDE, '1');
        $this->workers->heartbeat($this->workerId, 1, '2.0.0', WorkerFaehigkeit::cases(), new \DateTimeImmutable());
        $ticket = fn(int $groesse) => $this->aufruf($this->signiert($this->workerId, $this->schluessel, '/api/worker/mail/uploads', json_encode(['schluessel' => hash('sha256', 'x'), 'groesse' => $groesse], JSON_THROW_ON_ERROR)), fn($r, $p, $w) => $this->mailApi->uploadAnlegen($r, $w));

        self::assertSame(201, $ticket(700 * 1024)->status);
        $zuViel = $ticket(400 * 1024);
        self::assertSame(429, $zuViel->status);
        self::assertSame('mail_limit', self::daten($zuViel)['fehler']);
        self::assertSame(201, $ticket(300 * 1024)->status, 'what still fits');
    }

    /** A file larger than a whole hour allows can never pass: refused for good, not 429 forever. */
    public function testAFileLargerThanTheHourlyLimitIsRefusedForGood(): void
    {
        $this->settings->set(\App\Service\Worker\MailEingangGrenze::SETTING_MB_STUNDE, '1');
        $this->workers->heartbeat($this->workerId, 1, '2.0.0', WorkerFaehigkeit::cases(), new \DateTimeImmutable());
        $antwort = $this->aufruf($this->signiert($this->workerId, $this->schluessel, '/api/worker/mail/uploads', json_encode(['schluessel' => hash('sha256', 'x'), 'groesse' => 1024 * 1024 + 1], JSON_THROW_ON_ERROR)), fn($r, $p, $w) => $this->mailApi->uploadAnlegen($r, $w));

        self::assertSame(413, $antwort->status, 'the worker sets the mail aside');
    }

    /** A mail intake as a fresh worker process would build it - attempts in the /daten file. */
    private function neuerEingang(): MailEingang
    {
        return new MailEingang($this->apiClient, function (MailZugang $z): FakePostfach {
            $this->verbindungen[] = $z;
            ++$this->postfach->anmeldungen;

            return $this->postfach;
        }, $this->abbruch, $this->workerLog, fn(): int => $this->uhr + $this->spaeter, new VersuchsSpeicher($this->versuchsDatei(), 'test-schluessel', fn(): int => $this->uhr + $this->spaeter));
    }

    private function versuchsDatei(): string
    {
        if (!is_dir($this->tmp . '/daten')) {
            mkdir($this->tmp . '/daten', 0700);
        }

        return $this->tmp . '/daten/mail_versuche.json';
    }

    private function einrichten(): void
    {
        [$e, $fehler] = MailEingangEinstellungen::ausFormular([
            'aktiv' => '1',
            'host' => 'imap.verein.de',
            'sicherheit' => 'ssl',
            'port' => '993',
            'benutzer' => 'belege@verein.de',
            'passwort' => self::PASSWORT,
            'ordner' => 'INBOX',
            'ziel_ordner' => 'Belege/Erledigt',
            'absender' => "kasse@verein.de\n@mueller-getraenke.de",
            'abruf_minuten' => '5',
        ], new MailEingangEinstellungen());
        self::assertSame([], $fehler);
        self::assertNotNull($e);
        $this->mailEingang->speichern($e);
    }

    private function rechnungsMail(): string
    {
        return MimeNachrichtTest::mail(
            "From: =?UTF-8?Q?M=C3=BCller_Getr=C3=A4nke?= <rechnung@mueller-getraenke.de>\nSubject: =?UTF-8?Q?Rechnung_f=C3=BCr_Sommerfest?=\nMessage-ID: <r-1@mueller-getraenke.de>\nContent-Type: multipart/mixed; boundary=b",
            "--b\r\nContent-Type: text/plain\r\n\r\nAnbei.\r\n" . self::anhang('b', MimeNachrichtTest::PDF),
        );
    }

    private static function anhang(string $grenze, string $inhalt, string $typ = 'application/pdf', string $name = 'rechnung.pdf', bool $schluss = true): string
    {
        return '--' . $grenze . "\r\nContent-Type: " . $typ . "\r\nContent-Disposition: attachment; filename=\"" . $name . "\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($inhalt)) . ($schluss ? '--' . $grenze . "--\r\n" : '');
    }
}
