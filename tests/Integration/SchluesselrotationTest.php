<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAction;
use App\Domain\BankAccountKind;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSource;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\DocumentSource;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
use App\Domain\SupplierKeyKind;
use App\Domain\SupplierRole;
use App\Installer\FirstAdminSetup;
use App\Repository\AuditLogRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankImportRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\BlobRepository;
use App\Repository\CategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\RateLimitRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Repository\VaultRotationRepository;
use App\Service\Account\LoginService;
use App\Service\Account\PasswordHasher;
use App\Service\Account\UserAdministration;
use App\Service\Account\VaultRecovery;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Import\Dedupschluessel;
use App\Service\Bank\Import\KontoauszugImport;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\RecoveryKeyException;
use App\Service\Crypto\RecoveryKeyProblem;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\UserKey;
use App\Service\Crypto\UserKeyPair;
use App\Service\Crypto\Vault;
use App\Service\Crypto\VaultGrant;
use App\Service\Document\Duplikatindex;
use App\Service\Invoice\Pruefung;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Tresor\Indexpflege;
use App\Service\Tresor\Indexpflegen;
use App\Service\Tresor\RotationException;
use App\Service\Tresor\Schluesselrotation;
use App\Tests\Support\DatabaseTestCase;

/**
 * Vault key rotation end to end against the real schema (issue #90,
 * docs/spec/01-sicherheit.md section 2 "Schlüsselrotation"): every kind of
 * sealed data key and blind index is written under generation 1, the chain
 * runs (interrupted wherever it can be), and afterwards generation 2 ALONE
 * opens and finds everything.
 */
final class SchluesselrotationTest extends DatabaseTestCase
{
    private const string ADMIN = 'admin@example.org';
    private const string PASSWORT = 'ein sehr gutes Passwort 2026';
    private const string IP = '198.51.100.90';
    private const string SUPPLIER_IBAN = 'DE89370400440532013000';
    private const string KONTO_IBAN = 'DE02120300000000202051';
    private const string GEGEN_IBAN = 'DE44500105175407324931';
    private const string GELERNTE_IBAN = 'DE75512108001245126199';

    /** For the stand-in handler of one test (anonymous classes cannot capture). */
    public static ?\PDO $pdoFuerHandler = null;

    private ServerCrypto $crypto;
    private Vault $alt;
    private RecoveryKey $alterSchluessel;
    private int $adminId;
    private int $kassiererId;
    private string $blobDir;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->blobDir = sys_get_temp_dir() . '/vb_rotation_' . uniqid('', true);

        /** @var array{server_key: string} $config */
        $config = self::configData();
        $this->crypto = new ServerCrypto((string) base64_decode($config['server_key'], true));
        $erstellt = new FirstAdminSetup($this->pdo())->create(self::ADMIN, 'Anna Admin', self::PASSWORT, $config['server_key']);
        $this->adminId = $erstellt['userId'];
        $this->alterSchluessel = $erstellt['recoveryKey'];
        $aktuell = new VaultRepository($this->pdo())->current();
        self::assertNotNull($aktuell);
        $this->alt = $this->alterSchluessel->openVault($aktuell->publicKey(), $aktuell->version);
        $this->pdo()->prepare('UPDATE vault SET recovery_confirmed_at = created_at')->execute();

        // A second account with its own grant - re-sealed without its password.
        $this->kassiererId = $this->kontoMitFreigabe('kasse@example.org', $this->alt);
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

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach (glob($this->blobDir . '/*/*') ?: [] as $datei) {
            unlink($datei);
        }
    }

    public function testDieRotationSchluesseltAllesUmUndDerNeueSchluesselAlleinOeffnetEsDanach(): void
    {
        $d = $this->bestand($this->alt);
        $rotation = $this->rotation();

        $ring = $rotation->starten($this->alt, $this->adminId, self::IP, new \DateTimeImmutable());

        self::assertSame(2, $ring->version);
        self::assertSame([1], $ring->previousVersions());
        self::assertTrue(new VaultRepository($this->pdo())->rotationInProgress());
        self::assertSame(1, new VaultRepository($this->pdo())->current()?->version, 'Vor der Bestätigung wird nichts an Generation 2 versiegelt.');
        try {
            $rotation->umschluesseln($ring, [], new \DateTimeImmutable());
            self::fail('Umschlüsseln vor der Bestätigung des neuen Papierschlüssels.');
        } catch (RotationException) {
            self::assertSame(0, (new VaultRotationRepository($this->pdo()))->anzahl('invoice', 2));
        }
        self::assertCount(2, new VaultGrantRepository($this->pdo())->holdersOf(2), 'Beide Konten haben eine Freigabe der neuen Generation.');

        // Not finished before the key is confirmed and everything re-sealed.
        $this->assertAbschlussVerweigert($rotation, $ring);
        self::assertFalse($rotation->bestaetigen($ring, 'XXXXXXXX', $this->adminId, self::IP, new \DateTimeImmutable()));
        $neuerSchluessel = $rotation->neuerWiederherstellungsschluessel($ring);
        self::assertFalse(hash_equals($this->alterSchluessel->formatted(), $neuerSchluessel->formatted()));
        self::assertTrue($rotation->bestaetigen($ring, strtolower($neuerSchluessel->lastGroup()), $this->adminId, self::IP, new \DateTimeImmutable()));
        self::assertSame(2, new VaultRepository($this->pdo())->current()?->version, 'Ab der Bestätigung wird an Generation 2 versiegelt.');
        $this->assertAbschlussVerweigert($rotation, $ring);
        self::assertNotNull($this->zeile('bank_import', $d['import'])['source_bi'], 'Erst der Abschluss leert source_bi.');

        // One row per step: the chain really is many short requests.
        $schritte = $this->umschluesseln($rotation, $ring, budget: 0.0);
        self::assertGreaterThan(5, $schritte);

        // A grant of the old generation that slipped in after the start
        // (granted just before the pages closed) is carried over at the end.
        $spaet = $this->kontoMitFreigabe('spaet@example.org', $this->alt);

        $ergebnis = $rotation->abschliessen($ring, $this->adminId, self::IP, new \DateTimeImmutable());
        self::assertSame([], $ergebnis['defekt']);
        self::assertContains($spaet, array_column(new VaultGrantRepository($this->pdo())->holdersOf(2), 'userId'));
        $grantedBy = $this->pdo()->query('SELECT granted_by FROM vault_grant WHERE vault_version = 2 AND user_id = ' . $this->kassiererId)->fetchColumn();
        self::assertSame($this->adminId, (int) $grantedBy, 'Wer ursprünglich freigegeben hat, bleibt vermerkt.');
        $neu = $ergebnis['tresor'];
        self::assertSame([], $neu->previousVersions());

        $this->assertAllesUnterNeuemSchluessel($neu, $d);

        // Generation 1 is gone for good: no grants, retired, every session ended.
        self::assertSame([], new VaultGrantRepository($this->pdo())->holdersOf(1));
        self::assertFalse(new VaultRepository($this->pdo())->rotationInProgress());
        self::assertNotNull(new VaultRepository($this->pdo())->generationOf(1)?->retiredAt);
        self::assertSame(1, new UserRepository($this->pdo())->findById($this->kassiererId)?->sessionEpoch);

        $aktionen = array_map(
            static fn($e): string => $e->action,
            new AuditLogRepository($this->pdo())->after(0, 1000),
        );
        self::assertContains(AuditAction::TresorRotationGestartet->value, $aktionen);
        self::assertContains(AuditAction::TresorRotationSchluesselBestaetigt->value, $aktionen);
        self::assertContains(AuditAction::TresorRotationAbgeschlossen->value, $aktionen);
        self::assertNull($this->audit()->pruefeAbschnitt(0)->bruchId, 'Die Hash-Kette bleibt intakt.');
    }

    public function testEinAbbruchAnJederStelleVerliertNichts(): void
    {
        $d = $this->bestand($this->alt);
        $rotation = $this->rotation();
        $ring = $rotation->starten($this->alt, $this->adminId, self::IP, new \DateTimeImmutable());
        $this->bestaetigen($rotation, $ring);

        // A few steps, then the session is gone: the progress it remembered
        // is lost, and the next login (password only) builds the ring again.
        $fortschritt = [];
        for ($i = 0; $i < 5; $i++) {
            [, $fortschritt] = $rotation->umschluesseln($ring, $fortschritt, new \DateTimeImmutable(), 0.0);
        }
        $login = $this->anmelden(self::ADMIN);
        self::assertNotNull($login);
        self::assertSame(2, $login->version);
        self::assertSame([1], $login->previousVersions(), 'Während der Rotation öffnet die Anmeldung beide Generationen.');
        $kasse = $this->anmelden('kasse@example.org');
        self::assertSame([1], $kasse?->previousVersions());

        // A step that dies in the middle of a row (here: the database fails
        // inside a blind index handler) changes nothing of that row.
        $kaputt = $this->rotation([...Indexpflegen::alle($this->pdo()), 'supplier' => new class implements Indexpflege {
            public function tabelle(): string
            {
                return 'supplier';
            }

            public function spalten(): array
            {
                return [];
            }

            public function pflege(int $id, array $zeile, Vault $tresor, int $alteVersion, \DateTimeImmutable $now): void
            {
                throw new \PDOException('Abbruch mitten in der Zeile');
            }

            public function verwerfen(int $id, \DateTimeImmutable $now): void
            {
                throw new \LogicException('not reached');
            }
        }]);
        $vorher = $this->zeile('supplier', $d['lieferant']);
        $vorherKeys = new SupplierRepository($this->pdo())->keys($d['lieferant']);
        $abgebrochen = false;
        try {
            for ($i = 0; $i < 200; $i++) {
                $kaputt->umschluesseln($login, [], new \DateTimeImmutable(), 0.0);
            }
        } catch (\PDOException $e) {
            $abgebrochen = $e->getMessage() === 'Abbruch mitten in der Zeile';
        }
        self::assertTrue($abgebrochen, 'Der Schritt hätte abbrechen müssen.');
        self::assertSame($vorher, $this->zeile('supplier', $d['lieferant']));
        self::assertSame($vorherKeys, new SupplierRepository($this->pdo())->keys($d['lieferant']));
        self::assertFalse($this->pdo()->inTransaction());

        // Data stays readable in the meantime, old rows and new alike.
        self::assertSame(self::KONTO_IBAN, new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->finde($login, $d['konto'])?->data->iban);

        $this->umschluesseln($rotation, $login, budget: 0.0);
        $neu = $rotation->abschliessen($login, $this->adminId, self::IP, new \DateTimeImmutable())['tresor'];

        $this->assertAllesUnterNeuemSchluessel($neu, $d);

        // After the rotation, the login opens only the new generation.
        $danach = $this->anmelden(self::ADMIN);
        self::assertSame(2, $danach?->version);
        self::assertSame([], $danach?->previousVersions());
    }

    public function testZeilenDieSchonVorherUnlesbarWarenHaltenDenAbschlussNichtAuf(): void
    {
        $this->bestand($this->alt);
        // Sealed to generation 1, but not by its key: unreadable before the rotation.
        $kaputt = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            chr(1) . sodium_crypto_box_seal(random_bytes(32), Vault::create()->publicKey()),
            new \DateTimeImmutable(),
        );
        $rotation = $this->rotation();
        $ring = $rotation->starten($this->alt, $this->adminId, self::IP, new \DateTimeImmutable());
        $this->bestaetigen($rotation, $ring);
        $this->umschluesseln($rotation, $ring, budget: 10.0);

        $ergebnis = $rotation->abschliessen($ring, $this->adminId, self::IP, new \DateTimeImmutable());

        self::assertSame(['document' => 1], $ergebnis['defekt']);
        self::assertSame(1, Vault::sealedVersion((string) $this->zeile('document', $kaputt)['dek_sealed']), 'Unverändert gelassen.');
    }

    /**
     * Before the new paper key is confirmed, the rotation can be dropped
     * without any key: nothing was sealed to the new generation.
     */
    public function testVorDerBestaetigungLaesstSichDieRotationFolgenlosAbbrechen(): void
    {
        $d = $this->bestand($this->alt);
        $rotation = $this->rotation();
        $rotation->starten($this->alt, $this->adminId, self::IP, new \DateTimeImmutable());
        // The public submission keeps sealing - to the old, confirmed key.
        self::assertSame(1, Vault::sealedVersion(new VaultRepository($this->pdo())->current()?->sealDataKey(DataKey::generate()) ?? ''));

        self::assertSame(1, $rotation->abbrechen($this->adminId, self::IP, new \DateTimeImmutable()));

        self::assertFalse(new VaultRepository($this->pdo())->rotationInProgress());
        self::assertNotNull(new VaultRepository($this->pdo())->generationOf(2)?->retiredAt, 'Zurückgezogen, nicht gelöscht.');
        self::assertSame([], new VaultGrantRepository($this->pdo())->holdersOf(2));
        self::assertSame(1, new UserRepository($this->pdo())->findById($this->kassiererId)?->sessionEpoch, 'Alle Sitzungen enden.');
        self::assertSame('Sportbedarf Müller GmbH', $this->supplierService()->finde($this->alt, $d['lieferant'])?->data->name);
        // ... and a new rotation can start again - with a fresh number.
        $ring = $rotation->starten($this->alt, $this->adminId, self::IP, new \DateTimeImmutable());
        self::assertSame(3, $ring->version);
        $this->bestaetigen($rotation, $ring);
        $this->expectException(RotationException::class);
        $rotation->abbrechen($this->adminId, self::IP, new \DateTimeImmutable());
    }

    /**
     * The only admin forgets the password in the middle of a confirmed
     * rotation: the reset deletes every grant. Both paper keys, entered one
     * after the other, give the account both generations back, and the
     * rotation finishes without loss.
     */
    public function testNachEinemPasswortResetMittenInDerRotationHelfenBeidePapierschluessel(): void
    {
        $d = $this->bestand($this->alt);
        $rotation = $this->rotation();
        $ring = $rotation->starten($this->alt, $this->adminId, self::IP, new \DateTimeImmutable());
        $neuerSchluessel = $rotation->neuerWiederherstellungsschluessel($ring);
        $this->bestaetigen($rotation, $ring);
        for ($i = 0; $i < 4; $i++) {
            $rotation->umschluesseln($ring, [], new \DateTimeImmutable(), 0.0);
        }

        // What a reset does to the grants (App\Service\Account\PasswordReset).
        new VaultGrantRepository($this->pdo())->deleteForUser($this->adminId);
        new VaultGrantRepository($this->pdo())->deleteForUser($this->kassiererId);
        self::assertNull($this->anmelden(self::ADMIN), 'Ohne Freigabe kein Tresor.');

        $recovery = new VaultRecovery(
            new VaultRepository($this->pdo()),
            new VaultGrantRepository($this->pdo()),
            new UserAdministration($this->pdo(), $this->crypto),
            new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::LOGIN_WINDOW_SECONDS),
        );
        self::assertTrue($recovery->rotationLaeuft());
        $alt = $recovery->wiederherstellen($this->adminId, $this->alterSchluessel->formatted());
        self::assertTrue($alt->istErfolg(), (string) $alt->fehler);
        self::assertSame(1, $alt->vault?->version);
        $neu = $recovery->wiederherstellen($this->adminId, $neuerSchluessel->formatted());
        self::assertTrue($neu->istErfolg(), (string) $neu->fehler);
        self::assertSame(2, $neu->vault?->version);

        // The next login holds the ring again and finishes the rotation.
        $wieder = $this->anmelden(self::ADMIN);
        self::assertSame(2, $wieder?->version);
        self::assertSame([1], $wieder?->previousVersions());
        $this->umschluesseln($rotation, $wieder);
        $ergebnis = $rotation->abschliessen($wieder, $this->adminId, self::IP, new \DateTimeImmutable());
        $this->assertAllesUnterNeuemSchluessel($ergebnis['tresor'], $d);
    }

    /**
     * A blind index handler that fails on a row's data (not the database)
     * must not stop the chain: the row is re-sealed, its indexes cleared.
     */
    public function testEinFehlerInDerIndexpflegeHaeltDieKetteNichtAuf(): void
    {
        $d = $this->bestand($this->alt);
        $rotation = $this->rotation([...Indexpflegen::alle($this->pdo()), 'invoice' => new class implements Indexpflege {
            public function tabelle(): string
            {
                return 'invoice';
            }

            public function spalten(): array
            {
                return [];
            }

            public function pflege(int $id, array $zeile, Vault $tresor, int $alteVersion, \DateTimeImmutable $now): void
            {
                throw new \ValueError('unerwartete Daten');
            }

            public function verwerfen(int $id, \DateTimeImmutable $now): void
            {
                new VaultRotationRepository(SchluesselrotationTest::$pdoFuerHandler ?? throw new \LogicException())->setzeSpalte('invoice', 'id', $id, 'number_bi', null);
            }
        }, 'bank_account' => new class implements Indexpflege {
            public function tabelle(): string
            {
                return 'bank_account';
            }

            public function spalten(): array
            {
                return [];
            }

            public function pflege(int $id, array $zeile, Vault $tresor, int $alteVersion, \DateTimeImmutable $now): void
            {
                // What a recomputed index that collides with a unique key raises.
                throw new \PDOException('Duplicate entry', 23000);
            }

            public function verwerfen(int $id, \DateTimeImmutable $now): void
            {
                new VaultRotationRepository(SchluesselrotationTest::$pdoFuerHandler ?? throw new \LogicException())->setzeSpalte('bank_account', 'id', $id, 'iban_bi', null);
            }
        }]);
        self::$pdoFuerHandler = $this->pdo();
        $ring = $rotation->starten($this->alt, $this->adminId, self::IP, new \DateTimeImmutable());
        $this->bestaetigen($rotation, $ring);
        $fortschritt = [];
        do {
            [$stand, $fortschritt] = $rotation->umschluesseln($ring, $fortschritt, new \DateTimeImmutable(), 0.0);
        } while (!$stand->umgeschluesselt());
        self::assertSame(2, $stand->indexeVerworfen, 'Gezählt, nicht verschwiegen.');
        $ergebnis = $rotation->abschliessen($ring, $this->adminId, self::IP, new \DateTimeImmutable(), $stand->indexeVerworfen);

        self::assertSame([], $ergebnis['defekt']);
        $rechnung = $this->zeile('invoice', $d['rechnung']);
        self::assertSame(2, Vault::sealedVersion((string) $rechnung['dek_sealed']));
        self::assertNull($rechnung['number_bi'], 'Index verworfen statt Kette abgebrochen.');
        self::assertNull($this->zeile('bank_account', $d['konto'])['iban_bi'], 'Eine Unique-Kollision hält die Kette ebenso wenig auf.');
    }

    public function testEinZweiterStartUndEinVeralteterSitzungstresorWerdenAbgelehnt(): void
    {
        $rotation = $this->rotation();
        $ring = $rotation->starten($this->alt, $this->adminId, self::IP, new \DateTimeImmutable());

        $this->expectException(RotationException::class);
        try {
            $rotation->starten($ring, $this->adminId, self::IP, new \DateTimeImmutable());
        } finally {
            // Steps with a vault that lacks the old generation are refused as well.
            try {
                $rotation->umschluesseln($ring->withoutPrevious(), [], new \DateTimeImmutable());
                self::fail('Ohne alten Schlüssel darf kein Schritt laufen.');
            } catch (RotationException) {
            }
        }
    }

    public function testDerAlteWiederherstellungsschluesselWirdAlsAbgeloestErkannt(): void
    {
        $rotation = $this->rotation();
        $ring = $rotation->starten($this->alt, $this->adminId, self::IP, new \DateTimeImmutable());
        $vaults = new VaultRepository($this->pdo());
        $eingabe = $this->alterSchluessel->formatted();
        try {
            RecoveryKey::parse($eingabe)->openVault($ring->publicKey(), $ring->version);
            self::fail();
        } catch (RecoveryKeyException $e) {
            self::assertSame(RecoveryKeyProblem::FremderTresor, VaultRecovery::problemFuer($e, $eingabe, $vaults), 'Während der Rotation ist der alte Schlüssel noch nicht abgelöst.');
        }
        $this->bestaetigen($rotation, $ring);
        $this->umschluesseln($rotation, $ring);
        $rotation->abschliessen($ring, $this->adminId, self::IP, new \DateTimeImmutable());

        $aktuell = $vaults->current();
        self::assertNotNull($aktuell);
        $eingabe = $this->alterSchluessel->formatted();
        try {
            RecoveryKey::parse($eingabe)->openVault($aktuell->publicKey(), $aktuell->version);
            self::fail('Der alte Schlüssel darf den neuen Tresor nicht öffnen.');
        } catch (RecoveryKeyException $e) {
            self::assertSame(RecoveryKeyProblem::Abgeloest, VaultRecovery::problemFuer($e, $eingabe, $vaults));
            self::assertStringContainsString('Schlüsselrotation abgelöst', VaultRecovery::meldungFuer(RecoveryKeyProblem::Abgeloest));
        }
        $fremd = RecoveryKey::forVault(Vault::create())->formatted();
        try {
            RecoveryKey::parse($fremd)->openVault($aktuell->publicKey(), $aktuell->version);
            self::fail();
        } catch (RecoveryKeyException $e) {
            self::assertSame(RecoveryKeyProblem::FremderTresor, VaultRecovery::problemFuer($e, $fremd, $vaults));
        }
    }

    /**
     * Between the updater's 'switch' and 'migrate' steps the new code runs on
     * the old schema: the guard and the login must not fail on the missing
     * columns of migration 039.
     */
    public function testVorMigration039GibtEsKeineRotationUndDieAnmeldungGeht(): void
    {
        $this->pdo()->exec('ALTER TABLE vault DROP COLUMN retired_at, DROP COLUMN recovery_confirmed_at');

        $vaults = new VaultRepository($this->pdo());
        self::assertFalse($vaults->rotationInProgress());
        self::assertCount(1, $vaults->active());
        self::assertSame(1, $this->anmelden(self::ADMIN)?->version);
    }

    /**
     * Every `_bi` column a migration creates is either recomputed by a
     * handler of the rotation or keyed with the server key - a new blind
     * index cannot be forgotten (issue #90).
     */
    public function testJederBlindIndexHatEinePflegeImSchluesselwechsel(): void
    {
        $gepflegt = Indexpflegen::ZU_LEEREN;
        foreach (Indexpflegen::alle($this->pdo()) as $handler) {
            array_push($gepflegt, ...$handler->spalten());
        }
        $serverSchluessel = ['user.email_bi'];

        $stmt = $this->pdo()->query(
            "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME LIKE '%\\_bi' ORDER BY 1",
        );
        $spalten = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotSame([], $spalten);
        foreach ($spalten as $spalte) {
            self::assertTrue(
                in_array($spalte, $gepflegt, true) || in_array($spalte, $serverSchluessel, true),
                $spalte . ' ist ein Blind Index ohne Pflege in App\Service\Tresor\Indexpflegen.',
            );
        }

        // Every row handler belongs to a table the chain visits; the others
        // are cleared at the end (Indexpflegen::ZU_LEEREN).
        $tabellen = new VaultRotationRepository($this->pdo())->tabellen();
        foreach (Indexpflegen::alle($this->pdo()) as $tabelle => $handler) {
            self::assertArrayHasKey($tabelle, $tabellen, $tabelle . ' hat kein dek_sealed - die Pflege liefe nie.');
        }
        foreach (Indexpflegen::ZU_LEEREN as $spalte) {
            self::assertArrayNotHasKey(explode('.', $spalte)[0], $tabellen);
        }

        // ... and every table with a sealed data key takes part.
        self::assertArrayHasKey('audit_log', new VaultRotationRepository($this->pdo())->tabellen());
        self::assertArrayHasKey('file_blob', new VaultRotationRepository($this->pdo())->tabellen());
    }

    // ------------------------------------------------------------- helpers

    /**
     * Writes one of everything under $tresor.
     *
     * @return array<string, int>
     */
    private function bestand(Vault $tresor): array
    {
        $now = new \DateTimeImmutable();
        $audit = $this->audit()->record(AuditAction::LieferantAngelegt, $this->adminId, self::IP, 1, ['name' => 'geheim'], $now);

        $lieferant = $this->supplierService()->anlegen($tresor, [
            'name' => 'Sportbedarf Müller GmbH',
            'rolle' => SupplierRole::Lieferant->value,
            'iban' => self::SUPPLIER_IBAN,
        ], $now)->id;
        // A confirmed receipt taught it an alias and a second IBAN (issue
        // #201): learnt keys must come out of the rotation as well.
        $service = $this->supplierService();
        $gelernt = $service->finde($tresor, $lieferant) ?? self::fail();
        $partner = ['name' => 'Müller Sport', 'iban' => self::GELERNTE_IBAN];
        $merkmale = $service->lernbar($tresor, $gelernt, $partner, '');
        self::assertSame('Müller Sport', $merkmale->alias);
        $this->pdo()->beginTransaction();
        $service->merkmaleErgaenzen($tresor, $lieferant, $partner, '', $merkmale->fingerabdruck($tresor->blindIndex()), $now);
        $this->pdo()->commit();

        $konto = new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen($tresor, BankAccountKind::Bank, [
            'name' => 'Girokonto',
            'iban' => self::KONTO_IBAN,
            'bic' => '',
            'bank' => '',
            'opening_balance' => '0,00',
            'opening_date' => '2026-01-01',
        ], $now)->id;

        // Two identical bookings (running numbers 0 and 1) and one whose key
        // no longer matches its values.
        $b0 = $this->buchung($tresor, $konto, 0);
        $b1 = $this->buchung($tresor, $konto, 1);
        $bx = $this->buchung($tresor, $konto, null);

        $auszug = $this->blobService()->storeString('Kontoauszug', new BlobMeta('text/plain', 'auszug.sta'), $tresor, BlobStorage::Db)->id;
        $import = new BankImportRepository($this->pdo())->insert($konto, 'mt940', $auszug, random_bytes(32), null, null, $this->adminId, $now);

        $blob = $this->blobService()->storeString('Originalbeleg', new BlobMeta('application/pdf', 'beleg.pdf'), $tresor, BlobStorage::Db)->id;
        $dokument = new DocumentRepository($this->pdo())->insert(DocumentSource::Intern, null, [$blob], $tresor->sealDataKey(DataKey::generate()), $now);
        new DocumentRepository($this->pdo())->setzeContentBi($dokument, random_bytes(32));

        $key = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $rechnung = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, $lieferant, new \DateTimeImmutable('2026-03-14'), null, null, null, null, null),
            $tresor->sealDataKey($key),
            $tresor->blindIndex()->forValue(Pruefung::NUMMER_ZWECK, 'RE-2026-0815'),
            $this->adminId,
            $now,
        );
        $rechnungen->setData($rechnung, FieldCipher::encrypt($key, (string) json_encode([
            'invoice_number' => 'RE 2026-0815', 'gross' => 11900, 'net' => 10000, 'taxes' => [],
            'currency' => 'EUR', 'purpose_short' => '', 'notes' => '',
        ]), new FieldContext('invoice', $rechnung, 'data_enc')));

        return [
            'audit' => $audit->id, 'lieferant' => $lieferant, 'konto' => $konto, 'b0' => $b0, 'b1' => $b1, 'bx' => $bx,
            'blob' => $blob, 'dokument' => $dokument, 'rechnung' => $rechnung, 'import' => $import,
        ];
    }

    private function buchung(Vault $tresor, int $konto, ?int $nummer): int
    {
        $datum = new \DateTimeImmutable('2026-02-03');
        $repository = new BankTransactionRepository($this->pdo());
        $key = DataKey::generate();
        $dedup = $nummer === null
            ? random_bytes(32)
            : Dedupschluessel::fuerBuchung($tresor->blindIndex(), $konto, $datum, -2500, 'Mitgliedsbeitrag 2026', self::GEGEN_IBAN, $nummer);
        $id = $repository->insert(
            $konto,
            null,
            $datum,
            null,
            BankTransactionDirection::Ausgabe,
            $tresor->sealDataKey($key),
            $dedup,
            $tresor->blindIndex()->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, self::GEGEN_IBAN),
            BankTransactionDocStatus::fuerNeueBuchung(true),
            true,
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
        ) ?? self::fail('Buchung nicht angelegt');
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => -2500, 'currency' => 'EUR', 'counterparty_name' => 'TSV', 'counterparty_iban' => self::GEGEN_IBAN,
            'purpose' => 'Mitgliedsbeitrag 2026', 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => '',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    /**
     * @param array<string, int> $d
     */
    private function assertAllesUnterNeuemSchluessel(Vault $neu, array $d): void
    {
        self::assertSame(2, $neu->version);
        self::assertFalse($neu->canOpen(1));

        // Every sealed data key carries generation 2.
        $rotation = new VaultRotationRepository($this->pdo());
        foreach ($rotation->tabellen() as $tabelle => $_) {
            self::assertSame(0, $rotation->anzahl($tabelle, 1), $tabelle);
        }

        // ... and opens with the new key alone.
        $eintrag = new AuditLogRepository($this->pdo())->find($d['audit']);
        self::assertNotNull($eintrag);
        self::assertSame(['name' => 'geheim'], $this->audit()->details($eintrag, $neu));
        self::assertSame(1, Vault::sealedVersion((string) $eintrag->dekSealed), 'Das Original in der Hash-Kette bleibt.');

        $lieferant = $this->supplierService()->finde($neu, $d['lieferant']);
        self::assertSame('Sportbedarf Müller GmbH', $lieferant?->data->name);
        $konten = new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()));
        self::assertSame(self::KONTO_IBAN, $konten->finde($neu, $d['konto'])?->data->iban);
        $blob = $this->blobService()->find($d['blob']);
        self::assertNotNull($blob);
        self::assertSame('Originalbeleg', implode('', iterator_to_array($this->blobService()->openRead($blob, $neu), false)));

        // Blind indexes are found under the new key.
        $index = $neu->blindIndex();
        self::assertSame(
            $this->supplierService()->keys($neu, $lieferant->data),
            new SupplierRepository($this->pdo())->keys($d['lieferant']),
        );
        self::assertContains(
            [SupplierKeyKind::Iban, $index->forValue(SupplierKeyKind::Iban->purpose(), self::GELERNTE_IBAN)],
            new SupplierRepository($this->pdo())->keys($d['lieferant']),
            'Die gelernte IBAN wird unter dem neuen Schlüssel gefunden.',
        );
        self::assertSame($d['konto'], new BankAccountRepository($this->pdo())->idWithIban($index->forValue(BankAccountService::IBAN_PURPOSE, self::KONTO_IBAN)));
        self::assertSame(
            $index->forValue(Pruefung::NUMMER_ZWECK, 'RE2026-0815'),
            $this->zeile('invoice', $d['rechnung'])['number_bi'],
        );
        foreach ([0 => $d['b0'], 1 => $d['b1']] as $nummer => $id) {
            $zeile = $this->zeile('bank_transaction', $id);
            self::assertSame(
                Dedupschluessel::fuerBuchung($index, $d['konto'], new \DateTimeImmutable('2026-02-03'), -2500, 'Mitgliedsbeitrag 2026', self::GEGEN_IBAN, $nummer),
                $zeile['dedup_bi'],
                'Die Laufnummer bleibt erhalten - ein erneuter Import erkennt die Buchung.',
            );
            self::assertSame($index->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, self::GEGEN_IBAN), $zeile['counterparty_bi']);
        }
        self::assertSame(
            Dedupschluessel::fuerBuchung($index, $d['konto'], new \DateTimeImmutable('2026-02-03'), -2500, 'Mitgliedsbeitrag 2026', self::GEGEN_IBAN, 2),
            $this->zeile('bank_transaction', $d['bx'])['dedup_bi'],
            'Ohne passende Laufnummer: die nächste freie.',
        );
        self::assertSame(-2500, Buchungen::entschluesseln($neu, new BankTransactionRepository($this->pdo())->find($d['bx']) ?? self::fail())->amount);

        self::assertNull($this->zeile('bank_import', $d['import'])['source_bi'], 'source_bi wird beim Abschluss geleert.');

        // The content index is computed again by the job queue.
        self::assertNull($this->zeile('document', $d['dokument'])['content_bi']);
        self::assertTrue(new \App\Repository\JobRepository($this->pdo())->offenFuer([Duplikatindex::JOB_TYP], 'document', $d['dokument']));

        // The old key opens none of it any more.
        try {
            $this->alt->openDataKey((string) $this->zeile('invoice', $d['rechnung'])['dek_sealed']);
            self::fail('Der alte Schlüssel öffnet nichts mehr.');
        } catch (CryptoException) {
        }
    }

    private function assertAbschlussVerweigert(Schluesselrotation $rotation, Vault $ring): void
    {
        try {
            $rotation->abschliessen($ring, $this->adminId, self::IP, new \DateTimeImmutable());
            self::fail('Abschluss hätte verweigert werden müssen.');
        } catch (RotationException) {
            self::assertTrue(new VaultRepository($this->pdo())->rotationInProgress());
            self::assertNotSame([], new VaultGrantRepository($this->pdo())->holdersOf(1));
        }
    }

    private function bestaetigen(Schluesselrotation $rotation, Vault $ring): void
    {
        self::assertTrue($rotation->bestaetigen($ring, $rotation->neuerWiederherstellungsschluessel($ring)->lastGroup(), $this->adminId, self::IP, new \DateTimeImmutable()));
    }

    /**
     * Runs the chain the way the browser does; returns the number of steps.
     */
    private function umschluesseln(Schluesselrotation $rotation, Vault $ring, float $budget = 10.0): int
    {
        $fortschritt = [];
        for ($schritte = 1; $schritte < 1000; $schritte++) {
            [$stand, $fortschritt] = $rotation->umschluesseln($ring, $fortschritt, new \DateTimeImmutable(), $budget);
            if ($stand->umgeschluesselt()) {
                return $schritte;
            }
        }

        self::fail('Die Umschlüsselung kommt nicht zum Ende.');
    }

    private function anmelden(string $email): ?Vault
    {
        $ergebnis = new LoginService(
            new UserRepository($this->pdo()),
            new UserKeyRepository($this->pdo()),
            new VaultGrantRepository($this->pdo()),
            new VaultRepository($this->pdo()),
            $this->crypto,
            new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::LOGIN_WINDOW_SECONDS),
            new PasswordHasher(),
        )->attempt($email, self::PASSWORT, self::IP);
        self::assertTrue($ergebnis->istErfolg());

        return $ergebnis->vault;
    }

    /**
     * @param array<string, Indexpflege>|null $indexpflegen
     */
    private function rotation(?array $indexpflegen = null): Schluesselrotation
    {
        return new Schluesselrotation(
            $this->pdo(),
            new VaultRepository($this->pdo()),
            new VaultGrantRepository($this->pdo()),
            new UserRepository($this->pdo()),
            new VaultRotationRepository($this->pdo()),
            $indexpflegen ?? Indexpflegen::alle($this->pdo()),
            $this->audit(),
        );
    }

    private function audit(): AuditLog
    {
        return new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $this->crypto);
    }

    private function supplierService(): SupplierService
    {
        return new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    /**
     * @return array<string, mixed>
     */
    private function zeile(string $tabelle, int $id): array
    {
        $stmt = $this->pdo()->prepare(sprintf('SELECT * FROM `%s` WHERE id = ?', $tabelle));
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($zeile);

        return $zeile;
    }
}
