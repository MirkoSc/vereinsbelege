<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailException;
use App\Service\Mail\MailMessage;
use App\Service\Mail\MailSettings;
use App\Service\Mail\SmtpConnection;
use App\Service\Mail\SmtpSecurity;
use App\Service\Mail\SmtpTransport;
use PHPUnit\Framework\TestCase;

/**
 * The SMTP command sequence (docs/spec/06-betrieb.md section 3) against an
 * in-memory fake connection (App\Service\Mail\SmtpConnection) - no socket, no
 * network, so the suite stays fast while still exercising the exact protocol
 * handling SmtpTransport is responsible for.
 */
final class SmtpTransportTest extends TestCase
{
    private function settings(SmtpSecurity $sicherheit, string $benutzer = 'kontobenutzer', string $passwort = 'geheimes-passwort'): MailSettings
    {
        return new MailSettings(
            transport: 'smtp',
            host: 'smtp.example.org',
            port: 465,
            sicherheit: $sicherheit,
            benutzer: $benutzer,
            passwort: $passwort,
            passwortGesetzt: $passwort !== '',
            absender: 'absender@example.org',
            antwortAn: '',
            vereinsname: '',
        );
    }

    private function message(): MailMessage
    {
        return new MailMessage('empfaenger@example.org', 'Betreff', 'Text');
    }

    private function transport(FakeSmtpConnection $fake): SmtpTransport
    {
        return new SmtpTransport(static fn(SmtpSecurity $s, string $h, int $p): SmtpConnection => $fake);
    }

    public function testFullConversationWithStarttlsAndAuthLogin(): void
    {
        $fake = new FakeSmtpConnection([220, 250, 220, 250, 334, 334, 235, 250, 250, 354, 250, 221]);

        $this->transport($fake)->send($this->message(), $this->settings(SmtpSecurity::Starttls));

        self::assertSame([
            'EHLO vereinsbelege',
            'STARTTLS',
            'EHLO vereinsbelege',
            'AUTH LOGIN',
            base64_encode('kontobenutzer'),
            base64_encode('geheimes-passwort'),
            'MAIL FROM:<absender@example.org>',
            'RCPT TO:<empfaenger@example.org>',
            'DATA',
            '.',
            'QUIT',
        ], $fake->commands);
        self::assertNotNull($fake->raw);
        self::assertStringContainsString('To: empfaenger@example.org', (string) $fake->raw);
    }

    public function testImplicitTlsSkipsStarttlsAndSendsOnlyOneEhlo(): void
    {
        $fake = new FakeSmtpConnection([220, 250, 250, 250, 354, 250, 221]);

        $this->transport($fake)->send($this->message(), $this->settings(SmtpSecurity::Implizit, benutzer: ''));

        self::assertSame([
            'EHLO vereinsbelege',
            'MAIL FROM:<absender@example.org>',
            'RCPT TO:<empfaenger@example.org>',
            'DATA',
            '.',
            'QUIT',
        ], $fake->commands);
    }

    public function testNoAuthWhenNoUsernameIsConfigured(): void
    {
        $fake = new FakeSmtpConnection([220, 250, 250, 250, 354, 250, 221]);

        $this->transport($fake)->send($this->message(), $this->settings(SmtpSecurity::Implizit, benutzer: ''));

        self::assertNotContains('AUTH LOGIN', $fake->commands);
    }

    /** A server that rejects STARTTLS must abort the conversation, not fall back to plaintext. */
    public function testStarttlsRejectedByTheServerAbortsCleanly(): void
    {
        $fake = new FakeSmtpConnection([220, 250, 500]);

        try {
            $this->transport($fake)->send($this->message(), $this->settings(SmtpSecurity::Starttls));
            self::fail('expected a MailException');
        } catch (MailException $e) {
            self::assertStringNotContainsString('geheimes-passwort', $e->getMessage());
        }
        self::assertSame(['EHLO vereinsbelege', 'STARTTLS'], $fake->commands);
    }

    /** The command succeeds (220) but the TLS handshake itself fails. */
    public function testStarttlsHandshakeFailureAbortsCleanly(): void
    {
        $fake = new FakeSmtpConnection([220, 250, 220], tlsErfolg: false);

        $this->expectException(MailException::class);

        $this->transport($fake)->send($this->message(), $this->settings(SmtpSecurity::Starttls));
    }

    public function testRejectedCredentialsThrowWithoutLeakingThePassword(): void
    {
        $fake = new FakeSmtpConnection([220, 250, 334, 334, 535]);

        try {
            $this->transport($fake)->send($this->message(), $this->settings(SmtpSecurity::Implizit));
            self::fail('expected a MailException');
        } catch (MailException $e) {
            self::assertStringNotContainsString('geheimes-passwort', $e->getMessage());
            self::assertStringNotContainsString('kontobenutzer', $e->getMessage());
        }
    }

    public function testRejectedRecipientReportsTheCode(): void
    {
        $fake = new FakeSmtpConnection([220, 250, 550]);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('550');

        $this->transport($fake)->send($this->message(), $this->settings(SmtpSecurity::Implizit, benutzer: ''));
    }

    public function testConnectionEndingMidConversationIsReportedSafely(): void
    {
        $fake = new FakeSmtpConnection([220]);

        $this->expectException(MailException::class);

        $this->transport($fake)->send($this->message(), $this->settings(SmtpSecurity::Implizit, benutzer: ''));
    }
}

/**
 * In-memory SmtpConnection double: plays back a fixed script of reply codes
 * and records every command written, without a socket.
 */
final class FakeSmtpConnection implements SmtpConnection
{
    /** @var list<string> */
    public array $commands = [];

    public ?string $raw = null;

    /**
     * @param list<int> $antworten reply codes, consumed in order
     */
    public function __construct(
        private array $antworten,
        private readonly bool $tlsErfolg = true,
    ) {
    }

    public function writeLine(string $line): void
    {
        $this->commands[] = $line;
    }

    public function writeRaw(string $data): void
    {
        $this->raw = $data;
    }

    public function readResponseCode(): int
    {
        if ($this->antworten === []) {
            throw new MailException('Die SMTP-Verbindung wurde unerwartet beendet.');
        }

        return array_shift($this->antworten);
    }

    public function startTls(): bool
    {
        return $this->tlsErfolg;
    }

    public function close(): void
    {
    }
}
