<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Repository\SettingRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;

/**
 * Reads and writes the mail settings through App\Repository\SettingRepository
 * (docs/spec/06-betrieb.md section 3). SettingRepository itself only ever
 * sees plaintext operating data (its own docblock) or, for the password,
 * ciphertext produced here with the server key - never the password itself.
 *
 * The stored value is base64 of ServerCrypto::encrypt(): `setting.value` is
 * TEXT and a binary secretbox output does not survive that column type.
 */
final readonly class MailSettingsRepository
{
    private const string TRANSPORT = 'mail_transport';

    private const string HOST = 'mail_smtp_host';

    private const string PORT = 'mail_smtp_port';

    private const string SICHERHEIT = 'mail_smtp_sicherheit';

    private const string BENUTZER = 'mail_smtp_benutzer';

    private const string PASSWORT_ENC = 'mail_smtp_passwort_enc';

    private const string ABSENDER = 'mail_absender';

    private const string ANTWORT_AN = 'mail_antwort_an';

    private const string VEREINSNAME = 'mail_vereinsname';

    public function __construct(
        private SettingRepository $settings,
        private ServerCrypto $crypto,
    ) {
    }

    public function get(): MailSettings
    {
        $passwortEnc = $this->settings->get(self::PASSWORT_ENC);
        $passwort = '';
        if ($passwortEnc !== '') {
            try {
                $decoded = base64_decode($passwortEnc, true);
                $passwort = $decoded === false ? '' : $this->crypto->decrypt($decoded);
            } catch (CryptoException) {
                // A value from a different server key, or a corrupted row,
                // must not crash the settings page: it just shows up as "not
                // set" and the admin re-enters the password.
                $passwort = '';
            }
        }

        return new MailSettings(
            transport: $this->settings->get(self::TRANSPORT, 'smtp'),
            host: $this->settings->get(self::HOST),
            port: (int) $this->settings->get(self::PORT, '587'),
            sicherheit: SmtpSecurity::tryFrom($this->settings->get(self::SICHERHEIT)) ?? SmtpSecurity::Starttls,
            benutzer: $this->settings->get(self::BENUTZER),
            passwort: $passwort,
            passwortGesetzt: $passwortEnc !== '',
            absender: $this->settings->get(self::ABSENDER),
            antwortAn: $this->settings->get(self::ANTWORT_AN),
            vereinsname: $this->settings->get(self::VEREINSNAME),
        );
    }

    /**
     * @param ?string $neuesPasswort null keeps the stored password, '' clears
     *        it, anything else replaces it. The form never carries the
     *        stored password back (MailController::page()), so there is no
     *        other way to say "leave it as is".
     */
    public function save(
        string $transport,
        string $host,
        int $port,
        SmtpSecurity $sicherheit,
        string $benutzer,
        ?string $neuesPasswort,
        string $absender,
        string $antwortAn,
        string $vereinsname,
    ): void {
        $this->settings->set(self::TRANSPORT, $transport);
        $this->settings->set(self::HOST, $host);
        $this->settings->set(self::PORT, (string) $port);
        $this->settings->set(self::SICHERHEIT, $sicherheit->value);
        $this->settings->set(self::BENUTZER, $benutzer);
        if ($neuesPasswort !== null) {
            $this->settings->set(
                self::PASSWORT_ENC,
                $neuesPasswort === '' ? '' : base64_encode($this->crypto->encrypt($neuesPasswort)),
            );
        }
        $this->settings->set(self::ABSENDER, $absender);
        $this->settings->set(self::ANTWORT_AN, $antwortAn);
        $this->settings->set(self::VEREINSNAME, $vereinsname);
    }
}
