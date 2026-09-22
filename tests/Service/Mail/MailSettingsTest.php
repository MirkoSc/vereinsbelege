<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Repository\SettingRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\SmtpSecurity;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * SMTP settings through the `setting` table (docs/spec/06-betrieb.md
 * section 3, issue #14): the password never touches that table as
 * plaintext, only as ServerCrypto ciphertext (CLAUDE.md section 4).
 */
final class MailSettingsTest extends DatabaseTestCase
{
    private MailSettingsRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->repo = new MailSettingsRepository(
            new SettingRepository($this->pdo()),
            new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES)),
        );
    }

    public function testDefaultsBeforeAnythingWasSaved(): void
    {
        $settings = $this->repo->get();

        self::assertSame('smtp', $settings->transport);
        self::assertSame(587, $settings->port);
        self::assertSame(SmtpSecurity::Starttls, $settings->sicherheit);
        self::assertFalse($settings->passwortGesetzt);
        self::assertSame('', $settings->passwort);
        self::assertFalse($settings->istVollstaendig());
    }

    public function testPasswordRoundTrips(): void
    {
        $this->repo->save(
            transport: 'smtp',
            host: 'smtp.example.org',
            port: 465,
            sicherheit: SmtpSecurity::Implizit,
            benutzer: 'kontobenutzer',
            neuesPasswort: 'sehr-geheim',
            absender: 'verein@example.org',
            antwortAn: '',
            vereinsname: 'Verein',
        );

        $settings = $this->repo->get();

        self::assertSame('sehr-geheim', $settings->passwort);
        self::assertTrue($settings->passwortGesetzt);
        self::assertSame('smtp.example.org', $settings->host);
        self::assertSame(465, $settings->port);
        self::assertSame(SmtpSecurity::Implizit, $settings->sicherheit);
        self::assertTrue($settings->istVollstaendig());
    }

    /**
     * `setting.value` is TEXT and plaintext by SettingRepository's own
     * contract - the password row may only ever hold ciphertext.
     */
    public function testStoredPasswordSettingContainsNoPlaintext(): void
    {
        $this->repo->save(
            transport: 'smtp',
            host: 'h',
            port: 587,
            sicherheit: SmtpSecurity::Starttls,
            benutzer: '',
            neuesPasswort: 'sehr-geheim',
            absender: 'a@example.org',
            antwortAn: '',
            vereinsname: '',
        );

        $roh = (new SettingRepository($this->pdo()))->get('mail_smtp_passwort_enc');

        self::assertStringNotContainsString('sehr-geheim', $roh);
    }

    public function testNullKeepsTheStoredPasswordUnchanged(): void
    {
        $this->repo->save('smtp', 'h', 587, SmtpSecurity::Starttls, '', 'ursprünglich', 'a@example.org', '', '');

        $this->repo->save('smtp', 'h', 587, SmtpSecurity::Starttls, '', null, 'a@example.org', '', '');

        self::assertSame('ursprünglich', $this->repo->get()->passwort);
    }

    public function testEmptyStringClearsThePassword(): void
    {
        $this->repo->save('smtp', 'h', 587, SmtpSecurity::Starttls, '', 'ursprünglich', 'a@example.org', '', '');

        $this->repo->save('smtp', 'h', 587, SmtpSecurity::Starttls, '', '', 'a@example.org', '', '');

        $settings = $this->repo->get();
        self::assertFalse($settings->passwortGesetzt);
        self::assertSame('', $settings->passwort);
    }

    public function testDebugOutputMasksThePassword(): void
    {
        $this->repo->save('smtp', 'h', 587, SmtpSecurity::Starttls, '', 'sehr-geheim', 'a@example.org', '', '');

        $dump = print_r($this->repo->get()->__debugInfo(), true);

        self::assertStringNotContainsString('sehr-geheim', $dump);
    }

    public function testPhpMailTransportDoesNotRequireAHost(): void
    {
        $this->repo->save('php_mail', '', 587, SmtpSecurity::Starttls, '', null, 'a@example.org', '', '');

        self::assertTrue($this->repo->get()->istVollstaendig());
    }
}
