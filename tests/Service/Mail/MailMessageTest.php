<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailException;
use App\Service\Mail\MailMessage;
use App\Service\Mail\MailSettings;
use App\Service\Mail\SmtpSecurity;
use PHPUnit\Framework\TestCase;

/**
 * Building the RFC 5322 representation (App\Service\Mail\MailMessage): the
 * base64 body sidesteps line-length and dot-stuffing entirely (see that
 * class's docblock), so what is left to test is header encoding and
 * injection safety.
 */
final class MailMessageTest extends TestCase
{
    private function settings(string $vereinsname = '', string $antwortAn = ''): MailSettings
    {
        return new MailSettings(
            transport: 'smtp',
            host: 'smtp.example.org',
            port: 587,
            sicherheit: SmtpSecurity::Starttls,
            benutzer: '',
            passwort: '',
            passwortGesetzt: false,
            absender: 'verein@example.org',
            antwortAn: $antwortAn,
            vereinsname: $vereinsname,
        );
    }

    public function testPlainAsciiSubjectIsNotEncoded(): void
    {
        $mail = new MailMessage('to@example.org', 'Testmail', 'Text');

        self::assertSame('Testmail', $mail->encodedSubject());
    }

    /** RFC 2047 encoded-word - required as soon as the subject leaves ASCII. */
    public function testSubjectWithUmlautsIsRfc2047Encoded(): void
    {
        $mail = new MailMessage('to@example.org', 'Prüfung nötig', 'Text');

        self::assertSame('=?UTF-8?B?' . base64_encode('Prüfung nötig') . '?=', $mail->encodedSubject());
    }

    public function testBodyIsBase64EncodedAndLineWrapped(): void
    {
        $mail = new MailMessage('to@example.org', 'Betreff', str_repeat('x', 200));

        $body = $mail->encodedBody();

        self::assertSame(base64_encode(str_repeat('x', 200)), str_replace("\r\n", '', $body));
        foreach (explode("\r\n", rtrim($body, "\r\n")) as $zeile) {
            self::assertLessThanOrEqual(76, strlen($zeile));
        }
    }

    public function testRawMessageUsesCrlfThroughoutAndEndsInABlankLineThenTheBody(): void
    {
        $mail = new MailMessage('to@example.org', 'Betreff', 'Text');

        $raw = $mail->raw($this->settings());

        self::assertStringNotContainsString("\r\n\r\n\r\n", $raw);
        [$kopf, $rumpf] = explode("\r\n\r\n", $raw, 2);
        self::assertStringContainsString("\r\n", $kopf);
        self::assertStringStartsWith('To: to@example.org', $kopf);
        self::assertSame(base64_encode('Text') . "\r\n", $rumpf);
    }

    public function testFromHeaderIncludesTheClubName(): void
    {
        $mail = new MailMessage('to@example.org', 'Betreff', 'Text');

        $raw = $mail->raw($this->settings(vereinsname: 'Sportverein Musterstadt'));

        self::assertStringContainsString('From: Sportverein Musterstadt <verein@example.org>', $raw);
    }

    /** Same as the subject: a non-ASCII club name gets the RFC 2047 treatment too. */
    public function testFromHeaderEncodesANonAsciiClubName(): void
    {
        $mail = new MailMessage('to@example.org', 'Betreff', 'Text');

        $raw = $mail->raw($this->settings(vereinsname: 'Sportverein Müllerstädt'));

        self::assertStringContainsString(
            'From: =?UTF-8?B?' . base64_encode('Sportverein Müllerstädt') . '?= <verein@example.org>',
            $raw,
        );
    }

    public function testReplyToIsOmittedWhenNotConfigured(): void
    {
        $mail = new MailMessage('to@example.org', 'Betreff', 'Text');

        self::assertStringNotContainsString('Reply-To:', $mail->raw($this->settings()));
    }

    public function testReplyToIsIncludedWhenConfigured(): void
    {
        $mail = new MailMessage('to@example.org', 'Betreff', 'Text');

        self::assertStringContainsString(
            'Reply-To: antwort@example.org',
            $mail->raw($this->settings(antwortAn: 'antwort@example.org')),
        );
    }

    public function testAdditionalHeadersForPhpMailExcludeToAndSubject(): void
    {
        $mail = new MailMessage('to@example.org', 'Betreff', 'Text');

        $headers = $mail->additionalHeaders($this->settings());

        self::assertStringNotContainsString('To:', $headers);
        self::assertStringNotContainsString('Subject:', $headers);
        self::assertStringContainsString('From: verein@example.org', $headers);
        self::assertStringContainsString('Content-Transfer-Encoding: base64', $headers);
    }

    /** A CRLF in a user-controlled value is header injection - never accepted (CLAUDE.md section 4). */
    public function testCarriageReturnInRecipientIsRejected(): void
    {
        $this->expectException(MailException::class);

        new MailMessage("to@example.org\r\nBcc: dritte@example.org", 'Betreff', 'Text');
    }

    public function testLineFeedInSubjectIsRejected(): void
    {
        $this->expectException(MailException::class);

        new MailMessage('to@example.org', "Betreff\nX-Injected: 1", 'Text');
    }
}
