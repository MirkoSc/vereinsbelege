<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAction;
use App\Installer\FirstAdminSetup;
use App\Repository\AuditLogRepository;
use App\Repository\RateLimitRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Account\LoginService;
use App\Service\Account\PasswordHasher;
use App\Service\Account\UserAdministration;
use App\Service\Account\VaultAccess;
use App\Service\Account\VaultRecovery;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\RecoveryKeyProblem;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\TresorAnkerException;
use App\Service\Crypto\UserKey;
use App\Service\Crypto\UserKeyPair;
use App\Service\Crypto\Vault;
use App\Service\Crypto\VaultGrant;
use App\Service\Migration\Migration;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Service\Tresor\Erstbeglaubigung;
use App\Service\Tresor\TresorAnkerAlarm;
use App\Tests\Support\DatabaseTestCase;

/**
 * The anchor of the vault public key against the real schema (issue #240,
 * finding N-01, docs/spec/01-sicherheit.md section 2 "Anker des
 * Tresor-Schlüssels"): whoever can write the database must not get new
 * receipts sealed to a key of their own.
 */
final class TresorAnkerTest extends DatabaseTestCase
{
    private const string ADMIN = 'admin@example.org';
    private const string KASSE = 'kasse@example.org';
    private const string PASSWORT = 'ein sehr gutes Passwort 2026';

    private ServerCrypto $crypto;
    private RecoveryKey $schluessel;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        /** @var array{server_key: string} $config */
        $config = self::configData();
        $this->crypto = new ServerCrypto((string) base64_decode($config['server_key'], true));
        $erstellt = new FirstAdminSetup($this->pdo())->create(self::ADMIN, 'Anna Admin', self::PASSWORT, $config['server_key']);
        $this->adminId = $erstellt['userId'];
        $this->schluessel = $erstellt['recoveryKey'];
    }

    private function vaults(): VaultRepository
    {
        return new VaultRepository(
            $this->pdo(),
            $this->crypto->tresorAnker(),
            \Closure::fromCallable(new TresorAnkerAlarm($this->pdo(), $this->crypto)),
        );
    }

    /** What an attacker with write access to the database does: their own key, and a grant to match. */
    private function schluesselAustauschen(): Vault
    {
        $fremd = Vault::create();
        $stmt = $this->pdo()->prepare('UPDATE vault SET public_key = ? WHERE version = 1');
        $stmt->bindValue(1, $fremd->publicKey(), \PDO::PARAM_LOB);
        $stmt->execute();

        return $fremd;
    }

    private function macLoeschen(): void
    {
        $this->pdo()->exec('UPDATE vault SET public_key_mac = NULL');
    }

    /** @return list<string> */
    private function aktionen(): array
    {
        return array_map('strval', $this->pdo()->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function kontoMitFreigabe(string $email, Vault $tresor): int
    {
        $id = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt($email),
            $this->crypto->blindIndex()->forValue('user.email', $email),
            $this->crypto->encrypt('Konto'),
            new PasswordHasher()->hash(self::PASSWORT),
            mfaRequired: false,
        );
        $paar = UserKeyPair::create();
        new UserKeyRepository($this->pdo())->insert($id, UserKey::wrap($paar, self::PASSWORT));
        new VaultGrantRepository($this->pdo())->insert($id, VaultGrant::seal($tresor, $paar->publicKey()), $this->adminId);

        return $id;
    }

    private function login(string $email): \App\Service\Account\LoginResult
    {
        return new LoginService(
            new UserRepository($this->pdo()),
            new UserKeyRepository($this->pdo()),
            new VaultGrantRepository($this->pdo()),
            $this->vaults(),
            $this->crypto,
            new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::LOGIN_WINDOW_SECONDS),
            new PasswordHasher(),
        )->attempt($email, self::PASSWORT, '198.51.100.40');
    }

    private function recovery(): VaultRecovery
    {
        return new VaultRecovery(
            $this->vaults(),
            new VaultGrantRepository($this->pdo()),
            new UserAdministration($this->pdo(), $this->crypto),
            new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::LOGIN_WINDOW_SECONDS),
        );
    }

    public function testTheInstallerAnchorsTheKeyAndCurrentHandsItOut(): void
    {
        $aktuell = $this->vaults()->current();

        self::assertNotNull($aktuell);
        self::assertTrue(hash_equals($this->schluessel->publicKey(), $aktuell->publicKey()));
        self::assertSame([1 => true], $this->vaults()->ankerStand());
        $mac = $this->pdo()->query('SELECT public_key_mac FROM vault')->fetchColumn();
        self::assertIsString($mac);
        self::assertSame(32, strlen($mac));
    }

    public function testASwappedPublicKeyIsRefusedAndAuditedOncePerHour(): void
    {
        $this->schluesselAustauschen();

        foreach ([1, 2, 3] as $_) {
            try {
                $this->vaults()->current();
                self::fail('a key without a valid anchor was handed out');
            } catch (TresorAnkerException $e) {
                self::assertSame(1, $e->version);
                self::assertStringNotContainsString(bin2hex($this->schluessel->publicKey()), $e->getMessage());
            }
        }

        self::assertSame([1 => false], $this->vaults()->ankerStand());
        $alarme = array_filter($this->aktionen(), static fn(string $a): bool => $a === AuditAction::TresorAnkerAbweichung->value);
        self::assertCount(1, $alarme, 'one entry, however often the page is opened');
        $kopf = new AuditLogRepository($this->pdo())->head();
        self::assertSame(1, $kopf?->entityId);
        self::assertNull($kopf->detailsEnc, 'no details - they would be sealed to the failing key');
    }

    public function testAKeyOfAnotherInstallationWithItsOwnMacIsRefused(): void
    {
        // The attacker copies a whole row - key and MAC - from an
        // installation with another server key.
        $fremd = Vault::create();
        $anderer = new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES))->tresorAnker();
        $stmt = $this->pdo()->prepare('UPDATE vault SET public_key = ?, public_key_mac = ?');
        $stmt->bindValue(1, $fremd->publicKey(), \PDO::PARAM_LOB);
        $stmt->bindValue(2, $anderer->fuer(1, $fremd->publicKey()), \PDO::PARAM_LOB);
        $stmt->execute();

        $this->expectException(TresorAnkerException::class);
        $this->vaults()->current();
    }

    public function testTheMacOfAnotherGenerationDoesNotFit(): void
    {
        $stmt = $this->pdo()->prepare('UPDATE vault SET public_key_mac = ?');
        $stmt->bindValue(1, $this->crypto->tresorAnker()->fuer(2, $this->schluessel->publicKey()), \PDO::PARAM_LOB);
        $stmt->execute();

        $this->expectException(TresorAnkerException::class);
        $this->vaults()->current();
    }

    /**
     * A MAC that goes missing later is not taken over on trust again - not
     * even when the migrations run once more.
     */
    public function testAMissingMacIsRefusedAndNotFilledInAgain(): void
    {
        $this->macLoeschen();

        new Migrator($this->pdo(), $this->migrationsDir(), \Closure::fromCallable(new Erstbeglaubigung($this->vaults())))->migrate();

        $this->expectException(TresorAnkerException::class);
        $this->vaults()->current();
    }

    /**
     * An installation from before migration 047: the update that applies it
     * anchors the existing generations, in the same request, and audits it.
     */
    public function testTheAnchorMigrationAnchorsExistingGenerationsOnFirstUse(): void
    {
        $this->dropAllTables($this->pdo());
        $alt = sys_get_temp_dir() . '/vb_anker_' . uniqid('', true);
        mkdir($alt);
        try {
            $ankerVersion = null;
            foreach (Migration::discover($this->migrationsDir()) as $migration) {
                if (Erstbeglaubigung::betrifft($migration)) {
                    $ankerVersion = $migration->version;
                } elseif ($ankerVersion === null) {
                    copy($migration->path, $alt . '/' . basename($migration->path));
                }
            }
            self::assertNotNull($ankerVersion);
            new Migrator($this->pdo(), $alt)->migrate();
        } finally {
            array_map('unlink', glob($alt . '/*') ?: []);
            rmdir($alt);
        }
        // What the previous release wrote: a row without a MAC column.
        $vault = Vault::create();
        new VaultRepository($this->pdo())->insert($vault->publicKey());

        $vaults = $this->vaults();
        $audit = new AuditLog(new AuditLogRepository($this->pdo()), $vaults, $this->crypto);
        $ergebnis = new Migrator($this->pdo(), $this->migrationsDir(), \Closure::fromCallable(new Erstbeglaubigung($vaults, $audit)))->migrate();

        self::assertContains($ankerVersion, array_map(static fn(Migration $m): int => $m->version, $ergebnis->applied));
        self::assertTrue(hash_equals($vault->publicKey(), $this->vaults()->current()?->publicKey() ?? ''));
        self::assertSame([AuditAction::TresorAnkerGesetzt->value], $this->aktionen());
    }

    public function testFirstUseLeavesAWrongMacAlone(): void
    {
        $this->schluesselAustauschen();

        self::assertSame([], $this->vaults()->erstbeglaubigen());
        self::assertSame([1 => false], $this->vaults()->ankerStand());
    }

    /**
     * The grant is no proof: box_seal needs only the user's public key, so
     * the attacker who swapped the key seals a matching grant as well. The
     * login opens it - and still leaves the vault locked.
     */
    public function testTheLoginLeavesTheVaultLockedForASwappedKeyEvenWithAMatchingGrant(): void
    {
        $kasse = $this->kontoMitFreigabe(self::KASSE, $this->schluessel->openVault($this->schluessel->publicKey()));
        self::assertSame(VaultAccess::Entsperrt, $this->login(self::KASSE)->vaultAccess);

        $fremd = $this->schluesselAustauschen();
        $this->pdo()->prepare('DELETE FROM vault_grant WHERE user_id = ?')->execute([$kasse]);
        $userKey = new UserKeyRepository($this->pdo())->forUser($kasse);
        self::assertNotNull($userKey);
        new VaultGrantRepository($this->pdo())->insert($kasse, VaultGrant::seal($fremd, $userKey->publicKey()), $this->adminId);

        $ergebnis = $this->login(self::KASSE);

        self::assertTrue($ergebnis->istErfolg(), 'the login itself works');
        self::assertSame(VaultAccess::Unbeglaubigt, $ergebnis->vaultAccess);
        self::assertNull($ergebnis->vault, 'nothing in this session seals to the swapped key');
        self::assertNotNull(VaultAccess::Unbeglaubigt->meldung());
        self::assertContains(AuditAction::TresorAnkerAbweichung->value, $this->aktionen());
    }

    /** The way out: the paper key proves the key, and it is anchored again. */
    public function testTheRecoveryKeyAnchorsAKeyWhoseMacWentMissing(): void
    {
        $this->macLoeschen();

        $ergebnis = $this->recovery()->wiederherstellen($this->adminId, $this->schluessel->formatted());

        self::assertTrue($ergebnis->istErfolg(), (string) $ergebnis->fehler);
        self::assertTrue($ergebnis->ankerErneuert);
        self::assertNotNull($this->vaults()->current());

        $nochmal = $this->recovery()->wiederherstellen($this->adminId, $this->schluessel->formatted());
        self::assertTrue($nochmal->istErfolg());
        self::assertFalse($nochmal->ankerErneuert, 'an anchored key stays as it is');
    }

    /** ... but never a key that was swapped: the paper key does not fit it. */
    public function testTheRecoveryKeyDoesNotAnchorASwappedKey(): void
    {
        $this->schluesselAustauschen();

        $ergebnis = $this->recovery()->wiederherstellen($this->adminId, $this->schluessel->formatted());

        self::assertFalse($ergebnis->istErfolg());
        self::assertSame(RecoveryKeyProblem::FremderTresor->value, $ergebnis->grund);
        self::assertSame([1 => false], $this->vaults()->ankerStand());
    }

    /** The audit log goes on while the anchor fails - its details are left out. */
    public function testTheAuditLogKeepsWritingWithoutDetailsWhileTheAnchorFails(): void
    {
        $this->schluesselAustauschen();
        $audit = new AuditLog(new AuditLogRepository($this->pdo()), $this->vaults(), $this->crypto);

        $eintrag = $audit->record(AuditAction::LoginErfolg, $this->adminId, '198.51.100.40', $this->adminId, ['art' => 'passwort']);

        self::assertNull($eintrag->detailsEnc);
        self::assertNull($eintrag->dekSealed);
    }

    /** Rows that only count or list (banner, user pages) keep working without the check. */
    public function testTheUncheckedLookupStillAnswers(): void
    {
        $this->schluesselAustauschen();

        self::assertSame(1, $this->vaults()->currentUngeprueft()?->version);
        self::assertSame([], new UserAdministration($this->pdo(), $this->crypto)->ausstehend());
    }

    /** Upload, submission and worker answer "kein Tresor" instead of a bare 500 - and seal nothing. */
    public function testTheSealingCallersGetNoKeyInsteadOfAnError(): void
    {
        self::assertSame(1, $this->vaults()->zumVersiegeln()?->version);

        $this->schluesselAustauschen();

        self::assertNull($this->vaults()->zumVersiegeln());
        self::assertContains(AuditAction::TresorAnkerAbweichung->value, $this->aktionen(), 'reported all the same');
    }

    public function testWritingAMacNeedsTheAnchor(): void
    {
        $this->expectException(\LogicException::class);
        new VaultRepository($this->pdo())->beglaubige(1, $this->schluessel->publicKey());
    }
}
