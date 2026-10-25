<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\SystemRole;
use App\Domain\UserStatus;
use App\Repository\DocumentRepository;
use App\Repository\MailQueueRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Service\Cron\ErinnerungTask;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Mail\OffeneBelegeErinnerung;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * The cron's reminder "5 Belege warten auf Prüfung – ohne Details"
 * (issue #249, docs/spec/06-betrieb.md section 4): who gets it, what it
 * says, how often - and that it works from structure columns alone.
 */
final class OffeneBelegeErinnerungTest extends DatabaseTestCase
{
    private ServerCrypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->crypto = new ServerCrypto(str_repeat('e', ServerCrypto::KEY_BYTES));
        // Complete mail settings - without them nothing is queued (below).
        $settings = new SettingRepository($this->pdo());
        $settings->set('mail_transport', 'php_mail');
        $settings->set('mail_absender', 'belege@example.test');
    }

    public function testNothingIsQueuedWhileMailIsNotSetUp(): void
    {
        $this->konto('finanzen@example.test', SystemRole::Finanzen);
        $this->beleg(DocumentStatus::InPruefung);
        $settings = new SettingRepository($this->pdo());
        $settings->set('mail_absender', '');

        self::assertSame(0, $this->erinnerung()->ausfuehren(self::zeit('2026-10-26 08:00')));
        self::assertSame([], $this->mails());
        self::assertSame('', $settings->get(OffeneBelegeErinnerung::SETTING_LETZTER_LAUF), 'due again once mail works');

        $settings->set('mail_absender', 'belege@example.test');
        self::assertSame(1, $this->erinnerung()->ausfuehren(self::zeit('2026-10-26 09:00')));
    }

    public function testEveryActiveAccountWithDocumentEditGetsTheTwoCounts(): void
    {
        $this->konto('finanzen@example.test', SystemRole::Finanzen);
        $this->konto('admin@example.test', SystemRole::Admin);
        $this->konto('vorstand@example.test', SystemRole::Vorstand);
        $this->konto('gesperrt@example.test', SystemRole::Finanzen, UserStatus::Gesperrt);
        $this->beleg(DocumentStatus::Eingegangen);
        $this->beleg(DocumentStatus::Eingegangen);
        $this->beleg(DocumentStatus::InPruefung);
        $this->beleg(DocumentStatus::Ausgewertet);
        $this->beleg(DocumentStatus::KiFehler);
        $this->beleg(DocumentStatus::Festgeschrieben);
        $this->beleg(DocumentStatus::Abgelehnt);

        self::assertSame(2, $this->task()->run(self::zeit('2026-10-26 08:00')));

        $mails = $this->mails();
        $empfaenger = array_map(static fn($mail): string => $mail->to, $mails);
        sort($empfaenger);
        self::assertSame(['admin@example.test', 'finanzen@example.test'], $empfaenger);
        self::assertSame('Erinnerung: offene Belege', $mails[0]->subject);
        self::assertStringContainsString('2 Einreichungen warten im Posteingang', $mails[0]->body);
        self::assertStringContainsString('3 Belege warten auf Prüfung', $mails[0]->body);
    }

    public function testAWiedervorlageCountsOnlyOnceItsDateHasCome(): void
    {
        $this->konto('finanzen@example.test', SystemRole::Finanzen);
        $this->beleg(DocumentStatus::Wiedervorlage, '2026-10-26');
        $this->beleg(DocumentStatus::Wiedervorlage, '2026-11-30');

        $this->erinnerung()->ausfuehren(self::zeit('2026-10-26 08:00'));

        $body = $this->mails()[0]->body;
        self::assertStringContainsString('1 Einreichung wartet im Posteingang', $body);
        self::assertStringNotContainsString('Prüfung', $body);
    }

    public function testNothingWaitingMeansNoMail(): void
    {
        $this->konto('finanzen@example.test', SystemRole::Finanzen);
        $this->beleg(DocumentStatus::Festgeschrieben);

        self::assertSame(0, $this->erinnerung()->ausfuehren(self::zeit('2026-10-26 08:00')));
        self::assertSame([], $this->mails());
    }

    public function testWeeklyByDefaultOnceADayAtMostAndNotBeforeSeven(): void
    {
        $this->konto('finanzen@example.test', SystemRole::Finanzen);
        $this->beleg(DocumentStatus::InPruefung);
        $erinnerung = $this->erinnerung();

        self::assertSame(0, $erinnerung->ausfuehren(self::zeit('2026-10-26 06:59')));
        self::assertSame(1, $erinnerung->ausfuehren(self::zeit('2026-10-26 07:00')));
        self::assertSame(0, $erinnerung->ausfuehren(self::zeit('2026-10-26 07:01')));
        self::assertSame(0, $erinnerung->ausfuehren(self::zeit('2026-11-01 23:59')));
        self::assertSame(1, $erinnerung->ausfuehren(self::zeit('2026-11-02 09:30')));
        self::assertCount(2, $this->mails());
    }

    public function testDailyAndOff(): void
    {
        $this->konto('finanzen@example.test', SystemRole::Finanzen);
        $this->beleg(DocumentStatus::InPruefung);
        $settings = new SettingRepository($this->pdo());
        $erinnerung = $this->erinnerung();

        $settings->set(OffeneBelegeErinnerung::SETTING_INTERVALL, '1');
        self::assertSame(1, $erinnerung->ausfuehren(self::zeit('2026-10-26 08:00')));
        self::assertSame(1, $erinnerung->ausfuehren(self::zeit('2026-10-27 08:00')));

        $settings->set(OffeneBelegeErinnerung::SETTING_INTERVALL, '0');
        self::assertFalse($erinnerung->faellig(self::zeit('2026-11-30 08:00')));
        self::assertSame(0, $erinnerung->ausfuehren(self::zeit('2026-11-30 08:00')));

        // An unknown value falls back to the weekly default instead of
        // mailing every minute.
        foreach (['-3', '3', 'abc', '', ' 1'] as $unbekannt) {
            $settings->set(OffeneBelegeErinnerung::SETTING_INTERVALL, $unbekannt);
            self::assertSame(OffeneBelegeErinnerung::STANDARD_TAGE, OffeneBelegeErinnerung::intervall($settings), $unbekannt);
        }
    }

    public function testTheMailCarriesNoReceiptDataAndALinkOnlyWithTheConfiguredAddress(): void
    {
        $this->konto('finanzen@example.test', SystemRole::Finanzen);
        $id = $this->beleg(DocumentStatus::InPruefung);
        $this->pdo()->prepare('UPDATE document SET created_at = ? WHERE id = ?')->execute(['2026-10-01 12:00:00', $id]);
        $settings = new SettingRepository($this->pdo());
        $settings->set(OffeneBelegeErinnerung::SETTING_INTERVALL, '1');

        $this->erinnerung()->ausfuehren(self::zeit('2026-10-26 08:00'));
        $ohne = $this->mails()[0]->body;
        self::assertStringNotContainsString('http', $ohne);
        self::assertStringContainsString('auf der Startseite', $ohne);
        // No id, no date, no reference - only the counts.
        self::assertStringNotContainsString('/' . $id, $ohne);
        self::assertStringNotContainsString('01.10.2026', $ohne);
        self::assertStringNotContainsString('R-', $ohne);

        $settings->set('oeffentliche_url', 'https://belege.example.test');
        $this->erinnerung()->ausfuehren(self::zeit('2026-10-27 08:00'));
        $bodies = array_map(static fn($mail): string => $mail->body, $this->mails());
        self::assertCount(1, array_filter($bodies, static fn(string $b): bool => str_contains($b, 'https://belege.example.test/')));
    }

    public function testAnAddressThatDoesNotOpenIsSkipped(): void
    {
        $id = $this->konto('finanzen@example.test', SystemRole::Finanzen);
        $this->pdo()->prepare('UPDATE `user` SET email_enc = ? WHERE id = ?')
            ->execute([new ServerCrypto(str_repeat('x', ServerCrypto::KEY_BYTES))->encrypt('alt@example.test'), $id]);
        $this->beleg(DocumentStatus::InPruefung);

        self::assertSame(0, $this->erinnerung()->ausfuehren(self::zeit('2026-10-26 08:00')));
        self::assertSame([], $this->mails());
    }

    private static function zeit(string $wert): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wert, new \DateTimeZone('Europe/Berlin'));
    }

    private function konto(string $email, SystemRole $rolle, UserStatus $status = UserStatus::Aktiv): int
    {
        $id = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt($email),
            random_bytes(32),
            $this->crypto->encrypt('Name'),
            'hash',
            $status,
            mfaRequired: false,
        );
        $rollen = new RoleRepository($this->pdo());
        $rollen->assignToUser($id, [(int) $rollen->findSystem($rolle)?->id]);

        return $id;
    }

    private function beleg(DocumentStatus $status, ?string $wiedervorlage = null): int
    {
        $id = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            random_bytes(80),
            self::zeit('2026-10-20 10:00'),
            $status,
        );
        if ($wiedervorlage !== null) {
            $this->pdo()->prepare('UPDATE document SET resubmit_on = ? WHERE id = ?')->execute([$wiedervorlage, $id]);
        }

        return $id;
    }

    private function erinnerung(): OffeneBelegeErinnerung
    {
        $pdo = $this->pdo();
        $mailSettings = new MailSettingsRepository(new SettingRepository($pdo), $this->crypto);

        return new OffeneBelegeErinnerung(
            new Mailer(
                new MailQueueRepository($pdo, $this->crypto),
                $mailSettings,
                new MailTemplates(dirname(__DIR__, 2) . '/app/views/mail'),
            ),
            $mailSettings,
            new SettingRepository($pdo),
            $this->crypto,
            new UserRepository($pdo),
            new UserAccessRepository($pdo),
            new DocumentRepository($pdo),
        );
    }

    private function task(): ErinnerungTask
    {
        $task = new ErinnerungTask($this->erinnerung());
        self::assertSame('erinnerung_offene_belege', $task->name());

        return $task;
    }

    /**
     * @return list<\App\Domain\QueuedMail>
     */
    private function mails(): array
    {
        return new MailQueueRepository($this->pdo(), $this->crypto)->recent();
    }
}
