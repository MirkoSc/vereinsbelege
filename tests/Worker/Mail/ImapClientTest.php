<?php

declare(strict_types=1);

namespace App\Tests\Worker\Mail;

use App\Domain\ImapSicherheit;
use App\Tests\Worker\Support\SkriptStrom;
use App\Worker\Mail\ImapClient;
use App\Worker\Mail\ImapFehler;
use App\Worker\Mail\SocketStrom;
use PHPUnit\Framework\TestCase;

/**
 * The worker's minimal IMAP client (issue #87, E-54, 07 section 9): TLS
 * enforced before any credential, mails read without \Seen, handled ones
 * marked or moved, never deleted.
 */
final class ImapClientTest extends TestCase
{
    private const string GRUSS = "* OK IMAP ready\r\n";

    public function testStarttlsIsRequiredBeforeTheLogin(): void
    {
        $strom = new SkriptStrom(
            self::GRUSS
            . "* CAPABILITY IMAP4rev1 STARTTLS LOGINDISABLED\r\nA0001 OK\r\n"
            . "A0002 OK Begin TLS\r\n"
            . "* CAPABILITY IMAP4rev1 AUTH=PLAIN MOVE\r\nA0003 OK\r\n"
            . "A0004 OK Logged in\r\n"
            . "* CAPABILITY IMAP4rev1 MOVE\r\nA0005 OK\r\n",
        );

        $client = ImapClient::verbinden($strom, ImapSicherheit::Starttls, 'belege@verein.de', 'geheim');

        self::assertNotNull($strom->tlsAb);
        $vorTls = substr($strom->gesendet, 0, (int) $strom->tlsAb);
        self::assertStringContainsString('A0002 STARTTLS', $vorTls);
        self::assertStringNotContainsString('geheim', $vorTls, 'no credential before TLS');
        self::assertStringContainsString('A0004 LOGIN "belege@verein.de" "geheim"', $strom->gesendet);
        self::assertContains('MOVE', $client->faehigkeiten());
    }

    public function testPlaintextInjectedAfterStarttlsIsRefused(): void
    {
        $paar = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($paar);
        [$client, $server] = $paar;
        // The server's OK to STARTTLS with a forged answer glued on.
        fwrite($server, "A0002 OK Begin TLS\r\nA0003 OK [CAPABILITY IMAP4rev1]\r\n");
        $strom = SocketStrom::ausSocket($client, false);
        self::assertSame("A0002 OK Begin TLS\r\n", $strom->zeile());

        try {
            $strom->tlsStarten();
            self::fail('expected a refusal');
        } catch (ImapFehler $e) {
            self::assertSame('imap_tls', $e->fehlercode);
        } finally {
            fclose($server);
            $strom->schliessen();
        }
    }

    public function testWithoutStarttlsOnOfferNothingIsSent(): void
    {
        $strom = new SkriptStrom(self::GRUSS . "* CAPABILITY IMAP4rev1 AUTH=PLAIN\r\nA0001 OK\r\n");

        try {
            ImapClient::verbinden($strom, ImapSicherheit::Starttls, 'belege@verein.de', 'geheim');
            self::fail('expected a refusal');
        } catch (ImapFehler $e) {
            self::assertSame('imap_starttls_fehlt', $e->fehlercode);
        }
        self::assertStringNotContainsString('LOGIN', $strom->gesendet);
        self::assertStringNotContainsString('geheim', $strom->gesendet);
        self::assertTrue($strom->geschlossen);
    }

    public function testAPlainStreamIsNeverUsedForTheLogin(): void
    {
        // "ssl" configured, but the stream is not encrypted (a broken
        // transport): the client refuses before LOGIN.
        $strom = new SkriptStrom(self::GRUSS . "* CAPABILITY IMAP4rev1\r\nA0001 OK\r\n", tls: false);

        try {
            ImapClient::verbinden($strom, ImapSicherheit::Ssl, 'u', 'geheim');
            self::fail('expected a refusal');
        } catch (ImapFehler $e) {
            self::assertSame('imap_tls', $e->fehlercode);
        }
        self::assertStringNotContainsString('geheim', $strom->gesendet);
    }

    public function testARefusedLoginIsReportedWithoutTheServersText(): void
    {
        $strom = new SkriptStrom(self::GRUSS . "* CAPABILITY IMAP4rev1\r\nA0001 OK\r\nA0002 NO [AUTHENTICATIONFAILED] user belege@verein.de unknown\r\n", tls: true);

        try {
            ImapClient::verbinden($strom, ImapSicherheit::Ssl, 'belege@verein.de', 'falsch');
            self::fail('expected a refusal');
        } catch (ImapFehler $e) {
            self::assertSame('imap_anmeldung', $e->fehlercode);
            self::assertStringNotContainsString('belege@verein.de', $e->getMessage());
        }
    }

    public function testAPasswordWithUmlautsGoesAsLiteral(): void
    {
        $strom = new SkriptStrom(
            self::GRUSS . "* CAPABILITY IMAP4rev1\r\nA0001 OK\r\n+ go\r\nA0002 OK\r\n* CAPABILITY IMAP4rev1\r\nA0003 OK\r\n",
            tls: true,
        );

        ImapClient::verbinden($strom, ImapSicherheit::Ssl, 'kasse', 'Pässwort"1');

        self::assertStringContainsString('A0002 LOGIN "kasse" {11}' . "\r\n" . 'Pässwort"1' . "\r\n", $strom->gesendet);
    }

    public function testLoginDisabledUsesAuthenticatePlain(): void
    {
        $strom = new SkriptStrom(
            self::GRUSS . "* CAPABILITY IMAP4rev1 AUTH=PLAIN LOGINDISABLED\r\nA0001 OK\r\n+ \r\nA0002 OK\r\n* CAPABILITY IMAP4rev1\r\nA0003 OK\r\n",
            tls: true,
        );

        ImapClient::verbinden($strom, ImapSicherheit::Ssl, 'kasse', 'geheim');

        self::assertStringContainsString("A0002 AUTHENTICATE PLAIN\r\n" . base64_encode("\0kasse\0geheim") . "\r\n", $strom->gesendet);
    }

    public function testReadingMarkingAndMovingAMail(): void
    {
        $mail = "From: a@b.de\r\nSubject: x\r\n\r\nHallo\r\n";
        $strom = new SkriptStrom(
            self::GRUSS . "* CAPABILITY IMAP4rev1 MOVE\r\nA0001 OK\r\nA0002 OK\r\n* CAPABILITY IMAP4rev1 MOVE\r\nA0003 OK\r\n"
            . "* 3 EXISTS\r\n* OK [PERMANENTFLAGS (\\Seen \\Flagged \\*)] ok\r\nA0004 OK [READ-WRITE] SELECT\r\n"
            . "* SEARCH 12 7\r\nA0005 OK\r\n"
            . "* 1 FETCH (UID 7 RFC822.SIZE 120 FLAGS ())\r\n* 2 FETCH (RFC822.SIZE 4000 FLAGS (\\Flagged \$VereinsbelegeVersuch) UID 12)\r\nA0006 OK\r\n"
            . '* 1 FETCH (UID 7 BODY[] {' . strlen($mail) . "}\r\n" . $mail . ")\r\nA0007 OK\r\n"
            . "A0008 OK\r\n"
            . "A0009 OK\r\n"
            . "* BYE\r\nA0010 OK\r\n",
            tls: true,
        );
        $client = ImapClient::verbinden($strom, ImapSicherheit::Ssl, 'u', 'p');

        self::assertTrue($client->auswaehlen('Belege/Eingang'));
        self::assertSame([7, 12], $client->offene(true));
        self::assertSame([
            7 => ['groesse' => 120, 'flags' => []],
            12 => ['groesse' => 4000, 'flags' => ['\\Flagged', '$VereinsbelegeVersuch']],
        ], $client->angaben([7, 12]));
        self::assertSame($mail, $client->holen(7, 1024));
        $client->markieren(7, [ImapClient::KEYWORD_ERLEDIGT, '\\Seen']);
        self::assertTrue($client->verschieben(7, 'Erledigt'));
        $client->abmelden();

        self::assertStringContainsString('A0004 SELECT "Belege/Eingang"', $strom->gesendet);
        self::assertStringContainsString('A0005 UID SEARCH UNKEYWORD $Vereinsbelege UNKEYWORD $VereinsbelegeAbgelehnt UNDELETED', $strom->gesendet);
        self::assertStringContainsString('A0006 UID FETCH 7,12 (RFC822.SIZE FLAGS)', $strom->gesendet);
        self::assertStringContainsString('A0007 UID FETCH 7 (BODY.PEEK[])', $strom->gesendet, 'reading sets no \Seen');
        self::assertStringContainsString('A0008 UID STORE 7 +FLAGS.SILENT ($Vereinsbelege \Seen)', $strom->gesendet);
        self::assertStringContainsString('A0009 UID MOVE 7 "Erledigt"', $strom->gesendet);
        self::assertStringNotContainsString('EXPUNGE', $strom->gesendet);
        self::assertStringNotContainsString('\Deleted', $strom->gesendet);
        self::assertTrue($strom->geschlossen);
    }

    /**
     * Issue #243: the Message-ID of a mail that is not fetched again (all
     * attempts used up) comes from its header alone.
     */
    public function testTheMessageIdIsReadWithoutTheMail(): void
    {
        $kopf = "Message-ID: <r-1@mueller-getraenke.de>\r\n\r\n";
        $strom = new SkriptStrom(
            self::GRUSS . "* CAPABILITY IMAP4rev1\r\nA0001 OK\r\nA0002 OK\r\n* CAPABILITY IMAP4rev1\r\nA0003 OK\r\n"
            . '* 1 FETCH (UID 7 BODY[HEADER.FIELDS (MESSAGE-ID)] {' . strlen($kopf) . "}\r\n" . $kopf . ")\r\nA0004 OK\r\n"
            . "* 2 FETCH (UID 8 BODY[HEADER.FIELDS (\"MESSAGE-ID\")] {2}\r\n\r\n)\r\nA0005 OK\r\n"
            . "* 3 FETCH (UID 9 BODY[HEADER.FIELDS (MESSAGE-ID)] {70000}\r\n" . str_repeat('x', 70000) . ")\r\nA0006 OK\r\n",
            tls: true,
        );
        $client = ImapClient::verbinden($strom, ImapSicherheit::Ssl, 'u', 'p');

        self::assertSame('r-1@mueller-getraenke.de', $client->messageId(7));
        self::assertStringContainsString('A0004 UID FETCH 7 (BODY.PEEK[HEADER.FIELDS (MESSAGE-ID)])', $strom->gesendet, 'only the header, without \\Seen');
        self::assertNull($client->messageId(8), 'a mail without Message-ID');
        try {
            $client->messageId(9);
            self::fail('expected a refusal');
        } catch (ImapFehler $e) {
            self::assertSame('imap_protokoll', $e->fehlercode, 'a header block is small');
        }
    }

    public function testOwnKeywordsNeedTheAttemptCounterToo(): void
    {
        $alle = '$Vereinsbelege $VereinsbelegeAbgelehnt $VereinsbelegeVersuch $VereinsbelegeVersuch2 $VereinsbelegeVersuch3';
        foreach ([$alle => true, '$Vereinsbelege $VereinsbelegeAbgelehnt $VereinsbelegeVersuch' => false] as $flags => $erwartet) {
            $strom = new SkriptStrom(
                self::GRUSS . "* CAPABILITY IMAP4rev1\r\nA0001 OK\r\nA0002 OK\r\n* CAPABILITY IMAP4rev1\r\nA0003 OK\r\n"
                . "* OK [PERMANENTFLAGS (\\Seen \\Flagged " . $flags . ")] ok\r\nA0004 OK\r\n",
                tls: true,
            );
            self::assertSame($erwartet, ImapClient::verbinden($strom, ImapSicherheit::Ssl, 'u', 'p')->auswaehlen('INBOX'), $flags);
        }
    }

    public function testAMailLargerThanAnnouncedIsNotRead(): void
    {
        // The literal's bytes look like answers - they must not be taken for them.
        $literal = str_pad("A0005 OK\r\n* 1 FETCH (UID 7 FLAGS (\\Seen))\r\n", 5000, 'x');
        $strom = new SkriptStrom(
            self::GRUSS . "* CAPABILITY IMAP4rev1\r\nA0001 OK\r\nA0002 OK\r\n* CAPABILITY IMAP4rev1\r\nA0003 OK\r\n"
            . "* 1 FETCH (UID 7 BODY[] {5000}\r\n" . $literal . ")\r\nA0004 OK\r\n"
            . "A0005 NO not now\r\n",
            tls: true,
        );
        $client = ImapClient::verbinden($strom, ImapSicherheit::Ssl, 'u', 'p');

        try {
            $client->holen(7, 1000);
            self::fail('expected a refusal');
        } catch (ImapFehler $e) {
            self::assertSame('imap_protokoll', $e->fehlercode, 'no 5000 byte buffer for a mail allowed 1000 bytes');
        }
        // Read off and dropped: the next command gets its own answer.
        try {
            $client->markieren(7, ['\\Seen']);
            self::fail('the NO of the server, not the fake OK inside the literal');
        } catch (ImapFehler $e) {
            self::assertSame('imap_protokoll', $e->fehlercode);
        }
    }

    public function testWithoutKeywordsUnseenMailCounts(): void
    {
        $strom = new SkriptStrom(
            self::GRUSS . "* CAPABILITY IMAP4rev1\r\nA0001 OK\r\nA0002 OK\r\n* CAPABILITY IMAP4rev1\r\nA0003 OK\r\n"
            . "* OK [PERMANENTFLAGS (\\Seen \\Flagged)] ok\r\nA0004 OK\r\n* SEARCH\r\nA0005 OK\r\n",
            tls: true,
        );
        $client = ImapClient::verbinden($strom, ImapSicherheit::Ssl, 'u', 'p');

        self::assertFalse($client->auswaehlen('INBOX'));
        self::assertSame([], $client->offene(false));
        self::assertStringContainsString('UID SEARCH UNSEEN UNDELETED', $strom->gesendet);
        self::assertFalse($client->verschieben(1, 'Erledigt'), 'no MOVE capability, no copy-and-delete either');
    }

    public function testAnUnknownFolderIsReported(): void
    {
        $strom = new SkriptStrom(self::GRUSS . "* CAPABILITY IMAP4rev1\r\nA0001 OK\r\nA0002 OK\r\n* CAPABILITY IMAP4rev1\r\nA0003 OK\r\nA0004 NO Mailbox doesn't exist\r\n", tls: true);
        $client = ImapClient::verbinden($strom, ImapSicherheit::Ssl, 'u', 'p');

        try {
            $client->auswaehlen('Gibtsnicht');
            self::fail('expected a refusal');
        } catch (ImapFehler $e) {
            self::assertSame('imap_ordner', $e->fehlercode);
        }
    }

    public function testFolderNamesAreModifiedUtf7(): void
    {
        self::assertSame('INBOX', ImapClient::mutf7('INBOX'));
        self::assertSame('Gel&APY-scht', ImapClient::mutf7('Gelöscht'));
        self::assertSame('A&-B', ImapClient::mutf7('A&B'));
        self::assertSame('&ZeVnLIqe-', ImapClient::mutf7('日本語'));
    }

    public function testTheTlsContextVerifiesCertificateAndName(): void
    {
        $optionen = SocketStrom::tlsOptionen('imap.verein.de');

        self::assertTrue($optionen['verify_peer']);
        self::assertTrue($optionen['verify_peer_name']);
        self::assertFalse($optionen['allow_self_signed']);
        self::assertSame('imap.verein.de', $optionen['peer_name']);
        self::assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, $optionen['crypto_method'], 'TLS 1.2 or newer');
    }

    public function testAServerWithAnUntrustedCertificateIsRefused(): void
    {
        // A local TLS server with a self-signed certificate: the handshake
        // with our options fails - there is no switch to accept it. The
        // same server without verification shakes hands (control).
        $schluessel = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($schluessel);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $schluessel);
        self::assertNotFalse($csr);
        $zert = openssl_csr_sign($csr, null, $schluessel, 1);
        self::assertNotFalse($zert);
        openssl_x509_export($zert, $zertPem);
        openssl_pkey_export($schluessel, $schluesselPem);
        $datei = sys_get_temp_dir() . '/vb_imap_' . bin2hex(random_bytes(6)) . '.pem';
        file_put_contents($datei, $zertPem . $schluesselPem);

        try {
            self::assertFalse(self::handschlag($datei, SocketStrom::tlsOptionen('localhost')));
            self::assertTrue(self::handschlag($datei, ['verify_peer' => false, 'verify_peer_name' => false] + SocketStrom::tlsOptionen('localhost')));
        } finally {
            @unlink($datei);
        }
    }

    /**
     * Server and client in one process: both sides step the handshake
     * without blocking until the client is done.
     *
     * @param array<string, mixed> $clientOptionen
     */
    private static function handschlag(string $zertDatei, array $clientOptionen): bool|int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $nr, $text);
        self::assertNotFalse($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $nr, $text, 5);
        self::assertNotFalse($client);
        $verbindung = stream_socket_accept($server, 5);
        self::assertNotFalse($verbindung);
        stream_context_set_option($verbindung, 'ssl', 'local_cert', $zertDatei);
        stream_set_blocking($verbindung, false);
        stream_set_blocking($client, false);
        stream_context_set_options($client, ['ssl' => $clientOptionen]);

        $ergebnis = 0;
        for ($i = 0; $i < 300 && $ergebnis === 0; ++$i) {
            @stream_socket_enable_crypto($verbindung, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            $ergebnis = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            usleep(5000);
        }
        fclose($client);
        fclose($verbindung);
        fclose($server);

        return $ergebnis;
    }
}
