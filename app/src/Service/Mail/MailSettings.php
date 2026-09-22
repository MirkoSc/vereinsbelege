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
