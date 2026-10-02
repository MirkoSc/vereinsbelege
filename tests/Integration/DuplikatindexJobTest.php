<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\JobExecutor;
use App\Domain\SystemRole;
use App\Repository\BlobRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Crypto\BlindIndex;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\Duplikatindex;
use App\Service\Job\JobLaufStatus;
use App\Service\Job\JobRunner;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Tests\Support\DatabaseTestCase;

/**
 * The `detect_duplicate` job (issue #40/M6-6, docs/spec/03-erfassung-und-ki.md
 * section 5 and "Pflicht-Tests": Duplikaterkennung) through the real job
 * runner and schema: one original per step, the index written once, nothing
 * readable left in the job row - and the migration that queues it for every
 * document received before.
 */
final class DuplikatindexJobTest extends DatabaseTestCase
{
    private Vault $tresor;
    private int $userId;
    private string $blobDir;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_duplikatindex_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);

        $crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());

        $this->userId = new UserRepository($this->pdo())->insert(
            $crypto->encrypt('finanzen@example.org'),
            random_bytes(32),
            $crypto->encrypt('Fritz Finanzen'),
            'hash',
            mfaRequired: false,
        );
        $rollen = new RoleRepository($this->pdo());
        $finanzen = $rollen->findSystem(SystemRole::Finanzen)?->id;
        assert($finanzen !== null);
        $rollen->assignToUser($this->userId, [$finanzen]);
    }

    protected function tearDown(): void
    {
        // FsBlobBackend: <blobDir>/<two characters>/<name>.
        foreach (glob($this->blobDir . '/*/*') ?: [] as $datei) {
            unlink($datei);
        }
        foreach (glob($this->blobDir . '/*') ?: [] as $eintrag) {
            is_dir($eintrag) ? rmdir($eintrag) : unlink($eintrag);
        }
        rmdir($this->blobDir);
        parent::tearDown();
    }

    public function testTheSameOriginalsGetTheSameIndexOnEitherBackend(): void
    {
        $seiten = ["\xFF\xD8\xFF\xE0 Kassenbon Seite 1", "\xFF\xD8\xFF\xE0 Kassenbon Seite 2"];
        $imDateisystem = $this->dokument($seiten, BlobStorage::Fs);
        $inDerDatenbank = $this->dokument($seiten, BlobStorage::Db);
        $vertauscht = $this->dokument(array_reverse($seiten), BlobStorage::Fs);
        $anders = $this->dokument(["\xFF\xD8\xFF\xE0 ein anderer Bon"], BlobStorage::Fs);

        $this->allesAbarbeiten();

        $index = $this->contentBi($imDateisystem);
        self::assertNotNull($index);
        self::assertSame(BlindIndex::BYTES, strlen($index));
        self::assertSame($index, $this->contentBi($inDerDatenbank), 'the backend does not matter, the bytes do');
        self::assertNotSame($index, $this->contentBi($vertauscht), 'the page order is part of the document');
        self::assertNotSame($index, $this->contentBi($anders));
        self::assertNotNull($this->contentBi($anders));
    }

    public function testOneOriginalPerStep(): void
    {
        $seiten = ["\xFF\xD8\xFF\xE0 eins", "\xFF\xD8\xFF\xE0 zwei", "\xFF\xD8\xFF\xE0 drei"];
        $id = $this->dokument($seiten, BlobStorage::Fs);

        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt());
        self::assertNull($this->contentBi($id));
        $job = $this->job($id);
        self::assertSame('offen', $job['status']);
        self::assertSame('datei', $job['step']);
        self::assertCount(1, $this->zwischenstand($job));

        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt());
        self::assertCount(2, $this->zwischenstand($this->job($id)));
        self::assertNull($this->contentBi($id));

        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt());
        self::assertNotNull($this->contentBi($id));
        self::assertSame('fertig', $this->job($id)['status']);
        self::assertSame(JobLaufStatus::Leer, $this->schritt());
    }

    /**
     * The job row is plaintext: between steps it holds blind indexes of the
     * files done so far - no content, no plain hash anyone could confirm a
     * file against.
     */
    public function testTheJobStateHoldsNothingReadable(): void
    {
        $seiten = ["\xFF\xD8\xFF\xE0 Rechnung Muster GmbH", "\xFF\xD8\xFF\xE0 Lieferschein"];
        $id = $this->dokument($seiten, BlobStorage::Fs);

        $this->schritt();
        $job = $this->job($id);
        $roh = (string) $job['state'];

        foreach ($seiten as $seite) {
            self::assertStringNotContainsString('Muster', $roh);
            self::assertStringNotContainsString(hash('sha256', $seite), $roh);
            self::assertStringNotContainsString(bin2hex(hash('sha256', $seite, true)), $roh);
        }
        foreach ($this->zwischenstand($job) as $hex) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hex);
        }
        self::assertNull($job['last_error']);
    }

    public function testAnIndexOnceWrittenStays(): void
    {
        $id = $this->dokument(["\xFF\xD8\xFF\xE0 Seite"], BlobStorage::Fs);
        $vorher = random_bytes(BlindIndex::BYTES);
        self::assertTrue(new DocumentRepository($this->pdo())->setzeContentBi($id, $vorher));

        $this->allesAbarbeiten();

        self::assertSame($vorher, $this->contentBi($id));
        self::assertSame('fertig', $this->job($id)['status']);
        self::assertFalse(new DocumentRepository($this->pdo())->setzeContentBi($id, random_bytes(BlindIndex::BYTES)));
        self::assertSame($vorher, $this->contentBi($id));
    }

    /**
     * Only so does a receipt submitted again long after it was locked get
     * noticed - the index is derived from files that never change
     * (docs/spec/01-sicherheit.md section 7).
     */
    public function testALockedDocumentGetsItsIndexToo(): void
    {
        $gesperrt = $this->dokument(["\xFF\xD8\xFF\xE0 alter Bon"], BlobStorage::Fs, DocumentStatus::Festgeschrieben);
        $neu = $this->dokument(["\xFF\xD8\xFF\xE0 alter Bon"], BlobStorage::Fs);

        $this->allesAbarbeiten();

        self::assertNotNull($this->contentBi($gesperrt));
        self::assertSame($this->contentBi($gesperrt), $this->contentBi($neu));
        self::assertSame(DocumentStatus::Festgeschrieben->value, $this->dokumentZeile($gesperrt)['status']);
    }

    public function testADocumentWithoutOriginalsIsSkipped(): void
    {
        $id = $this->dokument([], BlobStorage::Fs);

        $this->allesAbarbeiten();

        self::assertNull($this->contentBi($id));
        self::assertSame('uebersprungen', $this->job($id)['status']);
    }

    /**
     * migrations/023 queues one job for every document that existed before -
     * applied on top of a database at 022 with documents in it.
     */
    public function testTheMigrationQueuesAJobForEveryDocumentReceivedBefore(): void
    {
        $this->dropAllTables($this->pdo());
        $bis022 = sys_get_temp_dir() . '/vb_migrations_022_' . uniqid('', true);
        mkdir($bis022);
        foreach (glob($this->migrationsDir() . '/*.sql') ?: [] as $datei) {
            if ((int) substr(basename($datei), 0, 3) <= 22) {
                copy($datei, $bis022 . '/' . basename($datei));
            }
        }

        try {
            new Migrator($this->pdo(), $bis022)->migrate();
            new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
            $documents = new DocumentRepository($this->pdo());
            $alt = [
                $documents->insert(DocumentSource::Einreichung, null, [], $this->tresor->sealDataKey(DataKey::generate()), new \DateTimeImmutable()),
                $documents->insert(DocumentSource::Intern, null, [], $this->tresor->sealDataKey(DataKey::generate()), new \DateTimeImmutable(), DocumentStatus::Festgeschrieben),
            ];
        } finally {
            array_map(unlink(...), glob($bis022 . '/*.sql') ?: []);
            rmdir($bis022);
        }

        $ergebnis = new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        self::assertSame(22, $ergebnis->fromVersion);
        $jobs = $this->pdo()->query("SELECT * FROM job WHERE typ = 'detect_duplicate' ORDER BY ref_id")->fetchAll();
        self::assertSame($alt, array_map(static fn(array $j): int => (int) $j['ref_id'], $jobs));
        foreach ($jobs as $job) {
            self::assertSame('document', $job['ref_type']);
            self::assertSame('session', $job['executor']);
            self::assertSame('offen', $job['status']);
            self::assertSame('', $job['step']);
        }
    }

    // ------------------------------------------------------------ helpers

    /**
     * A document with these originals and its `detect_duplicate` job, as
     * App\Service\Submission\SubmissionService queues it.
     *
     * @param list<string> $seiten
     */
    private function dokument(array $seiten, BlobStorage $storage, DocumentStatus $status = DocumentStatus::Eingegangen): int
    {
        $blobIds = [];
        foreach ($seiten as $inhalt) {
            $blobIds[] = $this->blobService()->storeString($inhalt, new BlobMeta(MagicBytes::JPEG, 'original.jpg'), $this->tresor, $storage)->id;
        }

        $id = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Einreichung,
            null,
            $blobIds,
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
            $status,
        );
        new JobRepository($this->pdo())->enqueue(Duplikatindex::JOB_TYP, JobExecutor::Session, 'document', $id);

        return $id;
    }

    private function schritt(): JobLaufStatus
    {
        $runner = new JobRunner(new JobRepository($this->pdo()), [new Duplikatindex(new DocumentRepository($this->pdo()), $this->blobService())]);

        return $runner->schritt(new UserAccessRepository($this->pdo())->berechtigungen($this->userId), $this->tresor, new \DateTimeImmutable())->status;
    }

    private function allesAbarbeiten(): void
    {
        for ($i = 0; $i < 50 && $this->schritt() === JobLaufStatus::Gearbeitet; $i++) {
        }
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM job WHERE status IN ('offen', 'laeuft')")->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM job WHERE status = 'fehler'")->fetchColumn());
    }

    /**
     * @return array<string, mixed>
     */
    private function job(int $documentId): array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM job WHERE typ = 'detect_duplicate' AND ref_id = ?");
        $stmt->execute([$documentId]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return list<string>
     */
    private function zwischenstand(array $job): array
    {
        $state = json_decode((string) $job['state'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertSame(['dateien'], array_keys($state));
        self::assertIsList($state['dateien']);

        return $state['dateien'];
    }

    private function contentBi(int $id): ?string
    {
        $wert = $this->dokumentZeile($id)['content_bi'];

        return $wert === null ? null : (string) $wert;
    }

    /**
     * @return array<string, mixed>
     */
    private function dokumentZeile(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM document WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }
}
