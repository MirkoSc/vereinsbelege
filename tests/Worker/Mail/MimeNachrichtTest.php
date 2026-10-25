<?php

declare(strict_types=1);

namespace App\Tests\Worker\Mail;

use App\Worker\Mail\MimeNachricht;
use PHPUnit\Framework\TestCase;

/**
 * Taking a receipt mail apart (issue #87): sender, subject, Message-ID,
 * spam mark, attachments - tolerant, never throwing.
 */
final class MimeNachrichtTest extends TestCase
{
    public const string PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    public static function mail(string $kopf, string $rumpf): string
    {
        return str_replace("\n", "\r\n", $kopf) . "\r\n\r\n" . $rumpf;
    }

    public function testAPdfAttachmentWithEncodedSubjectAndName(): void
    {
        $roh = self::mail(
            "From: =?UTF-8?Q?M=C3=BCller_Getr=C3=A4nke?= <Rechnung@Mueller-Getraenke.de>\nSubject: =?ISO-8859-1?Q?Rechnung_f=FCr?= =?UTF-8?B?IFNvbW1lcmZlc3Q=?=\nMessage-ID: <abc.123@mueller-getraenke.de>\nMIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"XYZ\"",
            "--XYZ\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nAnbei die Rechnung.\r\n"
            . "--XYZ\r\nContent-Type: application/pdf; name=\"x.pdf\"\r\nContent-Disposition: attachment; filename*=UTF-8''Rechnung%20M%C3%A4rz.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode(self::PDF), 20, "\r\n")
            . "--XYZ--\r\n",
        );

        $n = MimeNachricht::lesen($roh);

        self::assertSame('Müller Getränke', $n->absenderName);
        self::assertSame('rechnung@mueller-getraenke.de', $n->absenderAdresse);
        self::assertSame('Rechnung für Sommerfest', $n->betreff);
        self::assertSame('abc.123@mueller-getraenke.de', $n->messageId);
        self::assertFalse($n->spam);
        self::assertCount(1, $n->anhaenge, 'the text body is no attachment');
        self::assertSame('Rechnung März.pdf', $n->anhaenge[0]->name);
        self::assertSame(self::PDF, $n->anhaenge[0]->inhalt);
        self::assertFalse($n->anhaenge[0]->inline);
    }

    public function testInlineImagesAndForwardedMailsAreRecognised(): void
    {
        $weitergeleitet = self::mail(
            "From: lieferant@example.org\nSubject: Rechnung\nContent-Type: multipart/mixed; boundary=inner",
            "--inner\r\nContent-Type: text/plain\r\n\r\nText\r\n--inner\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=r.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode(self::PDF) . "\r\n--inner--\r\n",
        );
        $roh = self::mail(
            "From: Kassenwart <kasse@verein.de>\nSubject: Fwd: Rechnung\nX-Spam-Flag: NO\nContent-Type: multipart/mixed; boundary=outer",
            "--outer\r\nContent-Type: multipart/related; boundary=rel\r\n\r\n"
            . "--rel\r\nContent-Type: text/html\r\n\r\n<p>Hallo <img src=cid:logo></p>\r\n"
            . "--rel\r\nContent-Type: image/png\r\nContent-ID: <logo>\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode("\x89PNG\r\n\x1A\nlogo") . "\r\n--rel--\r\n"
            . "--outer\r\nContent-Type: message/rfc822\r\n\r\n" . $weitergeleitet . "\r\n--outer--\r\n",
        );

        $n = MimeNachricht::lesen($roh);

        self::assertSame('Kassenwart', $n->absenderName);
        self::assertNull($n->messageId);
        self::assertCount(2, $n->anhaenge);
        self::assertTrue($n->anhaenge[0]->inline, 'the logo with Content-ID');
        self::assertSame(self::PDF, $n->anhaenge[1]->inhalt, 'the forwarded mail is opened');
        self::assertSame('r.pdf', $n->anhaenge[1]->name);
    }

    public function testSpamMarksAndBrokenMailsDoNotThrow(): void
    {
        self::assertTrue(MimeNachricht::lesen(self::mail("From: x@y.de\nX-Spam-Flag: YES", 'Kaufen!'))->spam);
        self::assertTrue(MimeNachricht::lesen(self::mail("From: x@y.de\nX-Spam-Status: Yes, score=9.1", 'Kaufen!'))->spam);

        $kaputt = MimeNachricht::lesen("Content-Type: multipart/mixed\r\n\r\n--a\r\nohne Ende");
        self::assertSame([], $kaputt->anhaenge);
        self::assertSame('', $kaputt->absenderAdresse);
        self::assertSame([], MimeNachricht::lesen('')->anhaenge);
    }

    public function testQuotedPrintableAndFoldedHeaders(): void
    {
        $roh = self::mail(
            "From: \"Meier, Hans\"\n <hans@meier.de>\nSubject: Quittung\n Grillfest\nContent-Type: multipart/mixed; boundary=b",
            "--b\r\nContent-Type: application/xml\r\nContent-Disposition: attachment; filename=\"rechnung.xml\"\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<?xml version=3D\"1.0\"?>\r\n--b--\r\n",
        );

        $n = MimeNachricht::lesen($roh);

        self::assertSame('Meier, Hans', $n->absenderName);
        self::assertSame('hans@meier.de', $n->absenderAdresse);
        self::assertSame('Quittung Grillfest', $n->betreff);
        self::assertSame('<?xml version="1.0"?>', $n->anhaenge[0]->inhalt);
    }

    /**
     * Review of #87: a crafted, deeply nested mail near the size limit must
     * not multiply in memory (the worker would die of it on every start).
     */
    public function testANestedLargeMailStaysWithinASmallMultipleOfItsSize(): void
    {
        $pdf = self::PDF . str_repeat('A', 6 * 1024 * 1024);
        foreach ([4, 8] as $ebenen) {
            $rumpf = "Content-Type: application/pdf\r\nContent-Disposition: attachment; filename=r.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($pdf), 76, "\r\n");
            for ($i = $ebenen; $i >= 1; --$i) {
                $rumpf = "Content-Type: multipart/mixed; boundary=g{$i}\r\n\r\n--g{$i}\r\n" . $rumpf . "\r\n--g{$i}--\r\n";
            }
            $roh = "From: x@y.de\r\nSubject: gross\r\n" . $rumpf;

            gc_collect_cycles();
            memory_reset_peak_usage();
            $vorher = memory_get_usage();
            $n = MimeNachricht::lesen($roh);
            $spitze = memory_get_peak_usage() - $vorher;

            self::assertLessThan(3 * strlen($roh), $spitze, $ebenen . ' levels: ' . intdiv($spitze, 1024 * 1024) . ' MiB for ' . intdiv(strlen($roh), 1024 * 1024) . ' MiB');
            if ($ebenen === 4) {
                self::assertCount(1, $n->anhaenge);
                self::assertSame(strlen($pdf), strlen($n->anhaenge[0]->inhalt));
            } else {
                self::assertSame([], $n->anhaenge, 'deeper than four levels is not opened');
            }
            unset($n, $roh, $rumpf);
        }
    }
}
