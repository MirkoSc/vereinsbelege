<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * One outgoing mail: recipient, subject, plain-text body. Builds the RFC 5322
 * representation both transports need.
 *
 * The body always travels as base64 (Content-Transfer-Encoding: base64),
 * which sidesteps two classic raw-SMTP pitfalls at once: line-length limits
 * (RFC 5321 wants lines under 1000 octets) and dot-stuffing (a line starting
 * with "." would end the DATA phase early) - the base64 alphabet contains
 * neither a line break nor a leading dot, so there is nothing left to escape.
 */
final readonly class MailMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {
        self::assertSingleLine($to, 'Die Empfängeradresse');
        self::assertSingleLine($subject, 'Der Betreff');
    }

    public function encodedSubject(): string
    {
        return $this->encodeWord($this->subject);
    }

    public function encodedBody(): string
    {
        return chunk_split(base64_encode($this->body), 76, "\r\n");
    }

    /**
     * Full message for the SMTP DATA phase: To + Subject + the rest of the
     * headers, a blank line, the base64 body. CRLF throughout (RFC 5321).
     */
    public function raw(MailSettings $settings): string
    {
        $zeilen = ['To: ' . $this->to, 'Subject: ' . $this->encodedSubject()];
        foreach ($this->headers($settings) as $name => $value) {
            $zeilen[] = $name . ': ' . $value;
        }

        return implode("\r\n", $zeilen) . "\r\n\r\n" . $this->encodedBody();
    }

    /**
     * Headers for PHP's mail(), which supplies To and Subject itself
     * (App\Service\Mail\PhpMailTransport).
     */
    public function additionalHeaders(MailSettings $settings): string
    {
        $zeilen = [];
        foreach ($this->headers($settings) as $name => $value) {
            $zeilen[] = $name . ': ' . $value;
        }

        return implode("\r\n", $zeilen);
    }

    /**
     * @return array<string, string> header name => value, To/Subject excluded
     */
    private function headers(MailSettings $settings): array
    {
        $headers = [
            'From' => $settings->vereinsname !== ''
                ? sprintf('%s <%s>', $this->encodeWord($settings->vereinsname), $settings->absender)
                : $settings->absender,
        ];
        if ($settings->antwortAn !== '') {
            $headers['Reply-To'] = $settings->antwortAn;
        }
        $headers['Date'] = new \DateTimeImmutable()->format(DATE_RFC2822);
        $headers['Message-ID'] = sprintf('<%s@%s>', bin2hex(random_bytes(16)), $this->messageIdHost($settings));
        $headers['MIME-Version'] = '1.0';
        $headers['Content-Type'] = 'text/plain; charset=utf-8';
        $headers['Content-Transfer-Encoding'] = 'base64';

        return $headers;
    }

    private function messageIdHost(MailSettings $settings): string
    {
        $at = strrpos($settings->absender, '@');

        return $at === false ? 'localhost' : substr($settings->absender, $at + 1);
    }

    /** RFC 2047 encoded-word - only when the value is not plain ASCII. */
    private function encodeWord(string $value): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $value) === 1
            ? $value
            : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * A CR or LF inside a header value is header injection: it lets the
     * value that reaches here inject an arbitrary extra header or a second
     * message. `to` and `subject` come from user input (the test mail form,
     * or - once M4 adds submitter mail - the public submission), so this is
     * checked at construction, not left to the transport.
     */
    private static function assertSingleLine(string $value, string $feld): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new MailException(sprintf('%s enthält ungültige Zeilenumbrüche.', $feld));
        }
    }
}
