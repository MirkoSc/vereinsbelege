<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * SMTP / sender settings (docs/spec/06-betrieb.md section 3). Read and
 * written through App\Service\Mail\MailSettingsRepository, never directly
 * from App\Repository\SettingRepository - the password column there holds
 * only ciphertext.
 */
final readonly class MailSettings
{
    public function __construct(
        /** 'smtp' or 'php_mail' - the fallback the spec calls "wählbar". */
        public string $transport,
        public string $host,
        public int $port,
        public SmtpSecurity $sicherheit,
        public string $benutzer,
        /** Decrypted password. Never rendered back into a form (MailController::page()). */
        public string $passwort,
        public bool $passwortGesetzt,
        public string $absender,
        public string $antwortAn,
        public string $vereinsname,
        /**
         * Public address of this installation, e.g. https://belege.verein.de
         * - the base of every link a mail carries (password reset, M3-5).
         * Empty means "derive it from the request, but only when the host
         * matches the sender's domain" (App\Service\Mail\PublicUrl).
         */
        public string $oeffentlicheUrl = '',
    ) {
    }

    /**
     * What is wrong with a submitted form, or null - shared by /admin/mail
     * and the installer's mail step (issue #249), so both accept the same.
     */
    public static function eingabeFehler(
        string $transport,
        string $host,
        int $port,
        ?SmtpSecurity $sicherheit,
        string $absender,
        string $antwortAn,
    ): ?string {
        if (filter_var($absender, FILTER_VALIDATE_EMAIL) === false) {
            return 'Bitte eine gültige Absenderadresse angeben.';
        }
        if ($antwortAn !== '' && filter_var($antwortAn, FILTER_VALIDATE_EMAIL) === false) {
            return 'Die Antwort-an-Adresse ist keine gültige E-Mail-Adresse.';
        }
        if ($transport === 'smtp') {
            if ($host === '') {
                return 'Bitte einen SMTP-Server angeben.';
            }
            if ($port < 1 || $port > 65535) {
                return 'Der Port muss zwischen 1 und 65535 liegen.';
            }
            if ($sicherheit === null) {
                return 'Bitte eine Verschlüsselung auswählen.';
            }
        }

        return null;
    }

    /** Whether sending can be attempted at all - checked before every attempt. */
    public function istVollstaendig(): bool
    {
        if ($this->absender === '') {
            return false;
        }

        return $this->transport === 'php_mail' || $this->host !== '';
    }

    /**
     * Keeps the password out of var_dump() output, like Config::__debugInfo().
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'transport' => $this->transport,
            'host' => $this->host,
            'port' => (string) $this->port,
            'sicherheit' => $this->sicherheit->value,
            'benutzer' => $this->benutzer,
            'passwort' => $this->passwortGesetzt ? '*** gesetzt ***' : '(nicht gesetzt)',
            'absender' => $this->absender,
            'antwortAn' => $this->antwortAn,
            'vereinsname' => $this->vereinsname,
            'oeffentlicheUrl' => $this->oeffentlicheUrl,
        ];
    }
}
