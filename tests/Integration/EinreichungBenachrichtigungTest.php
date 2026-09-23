<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\SystemRole;
use App\Domain\UserStatus;
use App\Repository\MailQueueRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\EinreichungBenachrichtigung;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * "Mail an die Rolle Finanzen bei neuer Einreichung" (issue #27/M4-5): who
 * gets the notice, and that it carries nothing of the receipt.
 */
final class EinreichungBenachrichtigungTest extends DatabaseTestCase
{
    private ServerCrypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->crypto = new ServerCrypto(str_repeat('b', ServerCrypto::KEY_BYTES));
    }

    public function testEveryActiveAccountWithDocumentEditIsNotified(): void
    {
        $this->konto('finanzen@example.test', SystemRole::Finanzen);
        $this->konto('admin@example.test', SystemRole::Admin);
        $this->konto('vorstand@example.test', SystemRole::Vorstand);
        $this->konto('pruefer@example.test', SystemRole::Kassenpruefer);
        $this->konto('gesperrt@example.test', SystemRole::Finanzen, UserStatus::Gesperrt);
        $this->konto('eingeladen@example.test', SystemRole::Finanzen, UserStatus::Eingeladen);

        $anzahl = $this->benachrichtigung()->senden('R-2026-0042', 17, 'https://verein.example');

        self::assertSame(2, $anzahl);
        $empfaenger = array_map(static fn($mail): string => $mail->to, $this->mails());
        sort($empfaenger);
        self::assertSame(['admin@example.test', 'finanzen@example.test'], $empfaenger);
    }

    public function testTheNoticeCarriesOnlyTheReferenceAndTheLink(): void
    {
        $this->konto('finanzen@example.test', SystemRole::Finanzen);

        $this->benachrichtigung()->senden('R-2026-0042', 17, 'https://verein.example');

        $mail = $this->mails()[0];
        self::assertSame('Neue Einreichung R-2026-0042', $mail->subject);
        self::assertStringContainsString('R-2026-0042', $mail->body);
        self::assertStringContainsString('https://verein.example/app/posteingang/17', $mail->body);
    }

    public function testWithoutABaseUrlTheMailNamesTheMenuEntry(): void
    {
        $this->konto('finanzen@example.test', SystemRole::Finanzen);

        $this->benachrichtigung()->senden('R-2026-0042', 17, null);

        $mail = $this->mails()[0];
        self::assertStringNotContainsString('http', $mail->body);
        self::assertStringContainsString('unter Posteingang', $mail->body);
    }

    /** The internal capture (issue #28/M4-6) spares whoever captured it. */
    public function testTheCapturingAccountIsLeftOut(): void
    {
        $erfasser = $this->konto('finanzen@example.test', SystemRole::Finanzen);
        $this->konto('admin@example.test', SystemRole::Admin);

        self::assertSame(1, $this->benachrichtigung()->senden('R-2026-0042', 17, null, ausser: $erfasser));
        self::assertSame(['admin@example.test'], array_map(static fn($mail): string => $mail->to, $this->mails()));
    }

    public function testNobodyToNotifyQueuesNothing(): void
    {
        $this->konto('vorstand@example.test', SystemRole::Vorstand);

        self::assertSame(0, $this->benachrichtigung()->senden('R-2026-0042', 17, null));
        self::assertSame([], $this->mails());
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

    private function benachrichtigung(): EinreichungBenachrichtigung
    {
        $pdo = $this->pdo();

        return new EinreichungBenachrichtigung(
            new Mailer(
                new MailQueueRepository($pdo, $this->crypto),
                new MailSettingsRepository(new SettingRepository($pdo), $this->crypto),
                new MailTemplates(dirname(__DIR__, 2) . '/app/views/mail'),
            ),
            $this->crypto,
            new UserRepository($pdo),
            new UserAccessRepository($pdo),
        );
    }

    /**
     * @return list<\App\Domain\QueuedMail>
     */
    private function mails(): array
    {
        return new MailQueueRepository($this->pdo(), $this->crypto)->recent();
    }
}
