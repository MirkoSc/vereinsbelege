<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Default transport (docs/spec/06-betrieb.md section 3): a raw-socket SMTP
 * client, no dependency added for it (CLAUDE.md section 8 - the vetting note
 * lives in this issue's pull request). The pure-PHP protocol handling itself
 * is App\Service\Mail\StreamSmtpConnection; this class only drives the
 * conversation (EHLO, optional STARTTLS, optional AUTH LOGIN, the envelope,
 * DATA) and turns an unexpected reply code into a safe MailException.
 */
final readonly class SmtpTransport implements MailTransport
{
    private const int TIMEOUT_SECONDS = 15;

    /**
     * @param null|\Closure(SmtpSecurity, string, int): SmtpConnection $connect
     *        production passes null and gets StreamSmtpConnection::open();
     *        tests hand in a closure that returns an in-memory fake.
     */
    public function __construct(private ?\Closure $connect = null)
    {
    }

    public function send(MailMessage $mail, MailSettings $settings): void
    {
        $connection = ($this->connect ?? self::defaultConnect())(
            $settings->sicherheit,
            $settings->host,
            $settings->port,
        );

        try {
            $this->expect($connection, 220, 'Begrüßung');
            $this->run($connection, 'EHLO vereinsbelege', 250, 'EHLO');

            if ($settings->sicherheit === SmtpSecurity::Starttls) {
                $this->run($connection, 'STARTTLS', 220, 'STARTTLS');
                if (!$connection->startTls()) {
                    throw new MailException('Die STARTTLS-Verschlüsselung konnte nicht aufgebaut werden.');
                }
                $this->run($connection, 'EHLO vereinsbelege', 250, 'EHLO nach STARTTLS');
            }

            if ($settings->benutzer !== '') {
                $this->run($connection, 'AUTH LOGIN', 334, 'AUTH LOGIN');
                $this->run($connection, base64_encode($settings->benutzer), 334, 'Benutzername');
                $this->run($connection, base64_encode($settings->passwort), 235, 'Passwort');
            }

            $this->run($connection, 'MAIL FROM:<' . $settings->absender . '>', 250, 'Absenderadresse');
            $code = $this->command($connection, 'RCPT TO:<' . $mail->to . '>');
            if (!in_array($code, [250, 251], true)) {
                throw new MailException(sprintf('Der Empfänger wurde abgelehnt (Code %d).', $code));
            }

            $this->run($connection, 'DATA', 354, 'DATA');
            // raw() already ends in CRLF (MailMessage::encodedBody() via
            // chunk_split()), so the "." terminator line only needs its own
            // line - anything more would add a blank line to the body.
            $connection->writeRaw($mail->raw($settings));
            $connection->writeLine('.');
            $code = $connection->readResponseCode();
            if ($code !== 250) {
                throw new MailException(sprintf('Der SMTP-Schritt „%s" ist fehlgeschlagen (Code %d).', 'Mail-Übertragung', $code));
            }

            $connection->writeLine('QUIT');
            $connection->readResponseCode();
        } finally {
            $connection->close();
        }
    }

    private function run(SmtpConnection $connection, string $line, int $expected, string $schritt): void
    {
        $code = $this->command($connection, $line);
        if ($code !== $expected) {
            throw new MailException(sprintf('Der SMTP-Schritt „%s" ist fehlgeschlagen (Code %d).', $schritt, $code));
        }
    }

    private function command(SmtpConnection $connection, string $line): int
    {
        $connection->writeLine($line);

        return $connection->readResponseCode();
    }

    private function expect(SmtpConnection $connection, int $expected, string $schritt): void
    {
        $code = $connection->readResponseCode();
        if ($code !== $expected) {
            throw new MailException(sprintf('Der SMTP-Schritt „%s" ist fehlgeschlagen (Code %d).', $schritt, $code));
        }
    }

    private static function defaultConnect(): \Closure
    {
        return static fn (SmtpSecurity $sicherheit, string $host, int $port): SmtpConnection => StreamSmtpConnection::open(
            $sicherheit === SmtpSecurity::Implizit ? 'ssl://' : 'tcp://',
            $host,
            $port,
            self::TIMEOUT_SECONDS,
        );
    }
}
