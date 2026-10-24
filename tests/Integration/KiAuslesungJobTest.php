<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BlobMeta;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\JobExecutor;
use App\Domain\OcrStatus;
use App\Domain\SystemRole;
use App\Repository\AiProviderRepository;
use App\Repository\BlobRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\RoleRepository;
use App\Repository\SubmissionRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\KiAuslesung;
use App\Service\Document\KiAuszug;
use App\Service\Document\Texterkennung;
use App\Service\Job\JobLaufStatus;
use App\Service\Job\JobRunner;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Ki\KiVerbindungsfehler;
use App\Service\Ki\LlmFehler;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeChatHttp;
use App\Tests\Support\FakeJpeg;
use App\Tests\Support\FakeLlmClient;
use App\Tests\Support\KiAuslesungFabrik;

/**
 * The `ai_extract` job (issue #47/M7-5, docs/spec/03-erfassung-und-ki.md
 * sections 5 and 6) through the real job runner and schema, with recorded
 * AI answers (FakeLlmClient, CLAUDE.md section 6 - no real AI): one call per
 * step, the repair and the fallback as steps of their own, the result and
 * the raw answer only encrypted in `document_artifact`, the document moved
 * to `ausgewertet`/`ki_fehler` only from `bereit_zur_auswertung` - and the
 * migration that queues it for documents accepted before.
 *
 * schritt() drives a step the way App\Api\JobController does: when the
 * runner hands back a pending external call, the call runs and a runner
 * built anew finishes the step.
 */
final class KiAuslesungJobTest extends DatabaseTestCase
{
    private const string KEY = 'sk-test-standard-0000-nicht-echt';

    private const string FALLBACK_KEY = 'sk-test-fallback-1111-nicht-echt';

    /** Business data of the recorded receipt - never readable outside the vault. */
    private const array INHALTE = ['Sporthaus', 'RE-2026-0815', 'DE89370400440532013000', 'Trainingsbälle', '238.00'];

    private Vault $tresor;
    private int $userId;
    private string $blobDir;
    private ServerCrypto $crypto;
    private int $standardId;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_kiauslesung_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);

        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());

        $this->userId = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt('finanzen@example.org'),
            random_bytes(32),
            $this->crypto->encrypt('Fritz Finanzen'),
            'hash',
            mfaRequired: false,
        );
        $rollen = new RoleRepository($this->pdo());
        $finanzen = $rollen->findSystem(SystemRole::Finanzen)?->id;
        assert($finanzen !== null);
        $rollen->assignToUser($this->userId, [$finanzen]);

        // The seeded default profile (migrations/024: OpenAI, no key) gets a
        // key - the AI is switched on.
        $standard = $this->anbieter()->standard();
        assert($standard !== null);
        $this->standardId = $standard->id;
        $this->profilAendern(new KiFaehigkeiten(true, true, 2, 4000), self::KEY);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->blobDir . '/*/*') ?: [] as $datei) {
            unlink($datei);
        }
        foreach (glob($this->blobDir . '/*') ?: [] as $eintrag) {
            is_dir($eintrag) ? rmdir($eintrag) : unlink($eintrag);
        }
        rmdir($this->blobDir);
        parent::tearDown();
    }

    public function testAValidAnswerIsStoredEncryptedAsASuggestionAndTheDocumentIsEvaluated(): void
    {
        $original = FakeJpeg::bauen(40, 30);
        $aufbereitet = FakeJpeg::bauen(30, 40);
        $id = $this->beleg([[MagicBytes::JPEG, $original, $aufbereitet]]);
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $this->allesAbarbeiten($client);

        self::assertSame(DocumentStatus::Ausgewertet->value, $this->dokumentZeile($id)['status']);
        self::assertSame('fertig', $this->job($id)['status']);
        self::assertCount(1, $client->anfragen);
        $anfrage = $client->anfragen[0]['anfrage'];
        self::assertSame([$aufbereitet], $anfrage->bilder, 'the scanner\'s processed version, not the original');
        self::assertSame(self::KEY, $client->anfragen[0]['apiKey']);
        self::assertSame('extract-v1', $anfrage->schema?->name);

        $auszug = $this->auszug($id);
        self::assertNotNull($auszug);
        self::assertTrue($auszug->gueltig());
        self::assertSame('RE-2026-0815', $auszug->extraktion['invoice_number'] ?? null);
        self::assertSame(['category'], $auszug->unsicher, 'confidence 0.6 is below 0.7');
        self::assertSame('gpt-6-luna-2026-08-01', $auszug->modell);
        self::assertSame('KI-Antwort gültig.', $auszug->meldung);
        self::assertFalse($auszug->fallback);

        $artefakte = $this->artefakte($id);
        self::assertCount(1, $artefakte);
        self::assertSame(0, (int) $artefakte[0]['seq']);
        self::assertSame('session', $artefakte[0]['producer']);
        $inhalt = $this->entschluesselt($artefakte[0]);
        self::assertSame('extract-v1', $inhalt['versuch']['aufruf']['prompt_version']);
        self::assertSame('gpt-6-luna-2026-08-01', $inhalt['versuch']['aufruf']['modell']);
        self::assertStringContainsString('chatcmpl-', (string) $inhalt['versuch']['aufruf']['rohantwort'], 'the raw answer is kept');
        self::assertSame(2090, $inhalt['versuch']['aufruf']['tokens_gesamt']);

        $this->assertKeinKlartext($id);

        // M7-8: the call log has one operating-data row, nothing of the receipt.
        $protokoll = $this->pdo()->query('SELECT * FROM ai_call_log')->fetchAll();
        self::assertCount(1, $protokoll);
        self::assertSame($this->standardId, (int) $protokoll[0]['provider_id']);
        self::assertSame('gpt-6-luna-2026-08-01', $protokoll[0]['model']);
        self::assertSame('extract-v1', $protokoll[0]['prompt_version']);
        self::assertSame('gueltig', $protokoll[0]['status']);
        self::assertSame(2090, (int) $protokoll[0]['total_tokens']);
        self::assertSame(0, (int) $protokoll[0]['is_repair']);
        self::assertStringNotContainsString('RE-2026-0815', implode('|', array_map('strval', $protokoll[0])), 'no business data in the log');
    }

    public function testEachCallIsAStepOfItsOwnAndARepairFollowsAnAnswerThatDoesNotFit(): void
    {
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]]);
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/ungueltig-schema.json'), FakeLlmClient::fixture('extract-v1/gueltig-repariert.json')]);

        self::assertSame(JobLaufStatus::Gearbeitet, $this->schritt($client));
        self::assertCount(0, $client->anfragen, 'the first step only checks');
        self::assertSame('fragen', $this->job($id)['step']);

        $this->schritt($client);
        self::assertCount(1, $client->anfragen);
        self::assertSame('reparieren', $this->job($id)['step']);
        self::assertSame(['n' => 1], json_decode((string) $this->job($id)['state'], true), 'only a counter in the plaintext job row');

        $this->schritt($client);
        self::assertCount(2, $client->anfragen);
        self::assertSame(DocumentStatus::Ausgewertet->value, $this->dokumentZeile($id)['status']);
        self::assertCount(2, $client->anfragen[1]['anfrage']->nachtrag, 'the repair call carries the old answer and the errors');
        self::assertSame([0, 1], array_map(static fn(array $a): int => (int) $a['seq'], $this->artefakte($id)));
        self::assertSame('KI-Antwort nach einem Korrekturversuch gültig.', $this->auszug($id)?->meldung);

        $this->assertKeinKlartext($id);
    }

    public function testStillNotFittingAfterTheRepairIsKiFehlerWithoutAThirdCall(): void
    {
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]]);
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/ungueltig-schema.json'), FakeLlmClient::fixture('extract-v1/kein-json.json')]);

        $this->allesAbarbeiten($client);

        self::assertCount(2, $client->anfragen);
        self::assertSame(DocumentStatus::KiFehler->value, $this->dokumentZeile($id)['status']);
        $auszug = $this->auszug($id);
        self::assertNotNull($auszug);
        self::assertFalse($auszug->gueltig());
        self::assertStringContainsString('Auch der Korrekturversuch ist gescheitert.', $auszug->meldung);
    }

    public function testAnOutageFallsBackToTheFallbackProfileWithItsOwnKey(): void
    {
        $fallbackId = $this->anbieter()->insert('Ausweich', 'https://api.anthropic.example/v1', 'claude-test', new KiFaehigkeiten(true, false, 4, 4000), 60, true, self::FALLBACK_KEY);
        $this->anbieter()->setFallback($fallbackId);
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]]);
        $client = new FakeLlmClient([
            LlmFehler::http(503, FakeChatHttp::fixture('verbindungstest/429-limit.json')->body, self::KEY),
            FakeLlmClient::fixture('extract-v1/gueltig.json', 'claude-test'),
        ]);

        $this->allesAbarbeiten($client);

        self::assertCount(2, $client->anfragen);
        self::assertSame($fallbackId, $client->anfragen[1]['profil']->id);
        self::assertSame(self::FALLBACK_KEY, $client->anfragen[1]['apiKey']);
        self::assertSame(DocumentStatus::Ausgewertet->value, $this->dokumentZeile($id)['status']);
        $auszug = $this->auszug($id);
        self::assertTrue($auszug?->fallback);
        self::assertSame('Ausweich', $auszug->anbieter);
        self::assertStringContainsString('Das Fallback-Profil wurde verwendet.', $auszug->meldung);
    }

    public function testAnOutageWithoutFallbackIsKiFehler(): void
    {
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]]);
        $client = new FakeLlmClient([LlmFehler::ausVerbindungsfehler(KiVerbindungsfehler::zeitueberschreitung(60), self::KEY)]);

        $this->allesAbarbeiten($client);

        self::assertCount(1, $client->anfragen);
        self::assertSame(DocumentStatus::KiFehler->value, $this->dokumentZeile($id)['status']);
        self::assertNotSame('', $this->auszug($id)?->meldung);
        self::assertSame('fertig', $this->job($id)['status'], '"KI fehlgeschlagen" is an outcome, not a job error');
    }

    /**
     * A request that died after the call stored its attempt but before the
     * job row moved on: the step runs again and goes on from the stored
     * attempt - the AI is not asked twice.
     */
    public function testAStoredAttemptIsNotAskedForAgain(): void
    {
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]]);
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/gueltig.json')]);
        $this->schritt($client);

        $runner = $this->runner($client);
        $lauf = $runner->schritt($this->berechtigungen(), $this->tresor, new \DateTimeImmutable());
        self::assertTrue($lauf->aufrufAusstehend());
        $ergebnis = ($lauf->aufruf?->ausfuehren)();
        $job = new JobRepository($this->pdo())->find((int) $lauf->job?->id);
        self::assertNotNull($job);
        $this->handler($client)->nachAufruf($job, $this->tresor, $ergebnis, new \DateTimeImmutable());
        new JobRepository($this->pdo())->release($job->id);

        $this->allesAbarbeiten($client);

        self::assertCount(1, $client->anfragen);
        self::assertSame(DocumentStatus::Ausgewertet->value, $this->dokumentZeile($id)['status']);
        self::assertCount(1, $this->artefakte($id));
    }

    public function testADocumentSomeoneTookUpIsNotRead(): void
    {
        $inPruefung = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]], DocumentStatus::InPruefung);
        $festgeschrieben = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]], DocumentStatus::Festgeschrieben);
        $client = new FakeLlmClient([]);

        $this->allesAbarbeiten($client);

        self::assertCount(0, $client->anfragen);
        self::assertSame('uebersprungen', $this->job($inPruefung)['status']);
        self::assertSame(DocumentStatus::InPruefung->value, $this->dokumentZeile($inPruefung)['status']);
        self::assertSame(DocumentStatus::Festgeschrieben->value, $this->dokumentZeile($festgeschrieben)['status']);
    }

    public function testADocumentTakenUpBetweenTwoStepsIsNotAskedFor(): void
    {
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]]);
        $client = new FakeLlmClient([]);
        $this->schritt($client);
        $this->pdo()->prepare("UPDATE document SET status = 'in_pruefung' WHERE id = ?")->execute([$id]);

        $this->allesAbarbeiten($client);

        self::assertCount(0, $client->anfragen);
        self::assertSame('uebersprungen', $this->job($id)['status']);
        self::assertSame(DocumentStatus::InPruefung->value, $this->dokumentZeile($id)['status']);
    }

    public function testWithoutAUsableProfileTheJobSkipsItselfAndTheReceiptIsCapturedByHand(): void
    {
        $this->profilAendern(new KiFaehigkeiten(true, true, 2, 4000), '');
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]]);
        $client = new FakeLlmClient([]);

        $this->allesAbarbeiten($client);

        self::assertCount(0, $client->anfragen);
        self::assertSame('uebersprungen', $this->job($id)['status']);
        self::assertSame(DocumentStatus::BereitZurAuswertung->value, $this->dokumentZeile($id)['status']);
        self::assertSame([], $this->artefakte($id));
    }

    public function testItWaitsForTheTextLayerAndThePageImages(): void
    {
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]]);
        $rasterung = new JobRepository($this->pdo())->enqueue('render_pages', JobExecutor::Browser, 'document', $id);
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/gueltig.json')]);
        $vorher = new \DateTimeImmutable();

        $this->schritt($client);

        $job = $this->job($id);
        self::assertSame('offen', $job['status']);
        self::assertSame('', $job['step']);
        self::assertGreaterThanOrEqual($vorher->modify('+' . (KiAuslesung::WARTEN_SEKUNDEN - 1) . ' seconds')->format('Y-m-d H:i:s'), $job['locked_until']);
        self::assertSame(JobLaufStatus::Leer, $this->schritt($client), 'parked while the page images are rendered');

        new JobRepository($this->pdo())->finish($rasterung, \App\Domain\JobStatus::Fertig);
        $this->pdo()->prepare('UPDATE job SET locked_until = NULL WHERE id = ?')->execute([(int) $job['id']]);
        $this->allesAbarbeiten($client);

        self::assertSame(DocumentStatus::Ausgewertet->value, $this->dokumentZeile($id)['status']);
    }

    public function testADigitalPdfGoesToTheAiAsTextWithoutImages(): void
    {
        $pdf = (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/rechnung-standardschrift.pdf');
        $id = $this->beleg([[MagicBytes::PDF, $pdf, null]], ocrStatus: OcrStatus::Ausstehend, mitTextjob: true);
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $this->allesAbarbeiten($client);

        self::assertSame('fertig', $this->dokumentZeile($id)['ocr_status']);
        self::assertCount(1, $client->anfragen);
        $anfrage = $client->anfragen[0]['anfrage'];
        self::assertSame([], $anfrage->bilder);
        self::assertStringContainsString("Text des Belegs (aus der Textebene des PDFs):\n--- Seite 1 ---", $anfrage->text);
        self::assertStringContainsString('Rechnung', $anfrage->text);
    }

    public function testAPngIsConvertedAndAtMostMaxImagesAreSent(): void
    {
        $id = $this->beleg([
            [MagicBytes::PNG, self::png(), null],
            [MagicBytes::JPEG, FakeJpeg::bauen(20, 21), null],
            [MagicBytes::JPEG, FakeJpeg::bauen(20, 22), null],
        ]);
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $this->allesAbarbeiten($client);

        $bilder = $client->anfragen[0]['anfrage']->bilder;
        self::assertCount(2, $bilder, 'max_images of the profile');
        self::assertStringStartsWith("\xFF\xD8\xFF", $bilder[0], 'the PNG went as JPEG');
        self::assertContains('Die KI hat nur die ersten 2 von 3 Seiten gesehen – bitte die übrigen selbst prüfen.', $this->auszug($id)->hinweise ?? []);
    }

    public function testAReceiptTheProfileCannotReadIsKiFehlerWithItsReason(): void
    {
        $this->profilAendern(new KiFaehigkeiten(false, true, 0, 4000), null);
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]]);
        $client = new FakeLlmClient([]);

        $this->allesAbarbeiten($client);

        self::assertCount(0, $client->anfragen);
        self::assertSame(DocumentStatus::KiFehler->value, $this->dokumentZeile($id)['status']);
        self::assertStringContainsString('kann keine Bilder lesen', (string) $this->auszug($id)?->meldung);
    }

    public function testTheFreeTextOfTheSubmissionGoesAlong(): void
    {
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]], freitext: 'Bälle für die E-Jugend, bar bezahlt');
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/gueltig.json')]);

        $this->allesAbarbeiten($client);

        self::assertStringContainsString("<<<FREITEXT\nBälle für die E-Jugend, bar bezahlt\nFREITEXT>>>", $client->anfragen[0]['anfrage']->text);
    }

    public function testANewRunReplacesTheOldOne(): void
    {
        $id = $this->beleg([[MagicBytes::JPEG, FakeJpeg::bauen(20, 20), null]]);
        $client = new FakeLlmClient([FakeLlmClient::fixture('extract-v1/kein-json.json'), FakeLlmClient::fixture('extract-v1/kein-json.json'), FakeLlmClient::fixture('extract-v1/gueltig.json')]);
        $this->allesAbarbeiten($client);
        self::assertSame(DocumentStatus::KiFehler->value, $this->dokumentZeile($id)['status']);
        $ersterLauf = (int) $this->job($id)['id'];

        $this->pdo()->prepare("UPDATE document SET status = 'bereit_zur_auswertung' WHERE id = ?")->execute([$id]);
        self::assertTrue(KiAuslesung::einreihen(new JobRepository($this->pdo()), $id, new \DateTimeImmutable()));
        self::assertFalse(KiAuslesung::einreihen(new JobRepository($this->pdo()), $id, new \DateTimeImmutable()), 'never two pending runs');
        $this->allesAbarbeiten($client);

        self::assertSame(DocumentStatus::Ausgewertet->value, $this->dokumentZeile($id)['status']);
        $artefakte = $this->artefakte($id);
        self::assertCount(1, $artefakte);
        self::assertNotSame($ersterLauf, (int) $artefakte[0]['job_id']);
        self::assertTrue($this->auszug($id)?->gueltig());
    }

    /**
     * migrations/031 queues the job for every document accepted before -
     * applied on top of a database at 030 with documents in it.
     */
    public function testTheMigrationQueuesAJobForEveryDocumentWaitingForTheAi(): void
    {
        $this->dropAllTables($this->pdo());
        $bis030 = sys_get_temp_dir() . '/vb_migrations_030_' . uniqid('', true);
        mkdir($bis030);
        foreach (glob($this->migrationsDir() . '/*.sql') ?: [] as $datei) {
            if ((int) substr(basename($datei), 0, 3) <= 30) {
                copy($datei, $bis030 . '/' . basename($datei));
            }
        }

        try {
            new Migrator($this->pdo(), $bis030)->migrate();
            $documents = new DocumentRepository($this->pdo());
            $alt = [];
            foreach (DocumentStatus::cases() as $status) {
                $alt[$status->value] = $documents->insert(DocumentSource::Einreichung, null, [], $this->tresor->sealDataKey(DataKey::generate()), new \DateTimeImmutable(), $status);
            }
        } finally {
            array_map(unlink(...), glob($bis030 . '/*.sql') ?: []);
            rmdir($bis030);
        }

        $ergebnis = new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        self::assertSame(30, $ergebnis->fromVersion);
        $jobs = $this->pdo()->query("SELECT * FROM job WHERE typ = 'ai_extract'")->fetchAll();
        self::assertCount(1, $jobs);
        self::assertSame($alt['bereit_zur_auswertung'], (int) $jobs[0]['ref_id']);
        self::assertSame('document', $jobs[0]['ref_type']);
        self::assertSame('session', $jobs[0]['executor']);
        self::assertSame('offen', $jobs[0]['status']);
        self::assertSame('', $jobs[0]['step']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * A document with these originals (MIME type, bytes, the scanner's
     * processed JPEG or null) and its `ai_extract` job.
     *
     * @param list<array{string, string, ?string}> $originale
     */
    private function beleg(
        array $originale,
        DocumentStatus $status = DocumentStatus::BereitZurAuswertung,
        OcrStatus $ocrStatus = OcrStatus::Uebersprungen,
        bool $mitTextjob = false,
        ?string $freitext = null,
    ): int {
        $blobIds = [];
        $aufbereitet = [];
        foreach ($originale as [$typ, $inhalt, $fassung]) {
            $blobIds[] = $this->blobService()->storeString($inhalt, new BlobMeta($typ, 'original'), $this->tresor)->id;
            $aufbereitet[] = $fassung === null ? null : $this->blobService()->storeString($fassung, new BlobMeta(MagicBytes::JPEG), $this->tresor)->id;
        }

        $id = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Einreichung,
            $freitext === null ? null : $this->einreichung($freitext),
            $blobIds,
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
            $status,
            $ocrStatus,
            processedBlobIds: $aufbereitet,
        );
        $jobs = new JobRepository($this->pdo());
        if ($mitTextjob) {
            $jobs->enqueue(Texterkennung::JOB_TYP, JobExecutor::Session, 'document', $id);
        }
        KiAuslesung::einreihen($jobs, $id, new \DateTimeImmutable());

        return $id;
    }

    /** A submission with this free text, as App\Service\Submission\SubmissionService stores it. */
    private function einreichung(string $freitext): int
    {
        $repository = new SubmissionRepository($this->pdo());
        $id = $repository->insertDraft(bin2hex(random_bytes(16)), new \DateTimeImmutable());
        $schluessel = DataKey::generate();
        $payload = FieldCipher::encrypt(
            $schluessel,
            json_encode(['name' => 'Erika Muster', 'freitext' => $freitext], JSON_THROW_ON_ERROR),
            new FieldContext('submission', $id, 'payload_enc'),
        );
        $repository->complete($id, 'B-2026-' . $id, $this->tresor->sealDataKey($schluessel), $payload);

        return $id;
    }

    private function handler(FakeLlmClient $client): KiAuslesung
    {
        return KiAuslesungFabrik::bauen($this->pdo(), $this->blobService(), $this->crypto, $client);
    }

    private function texterkennung(): Texterkennung
    {
        return new Texterkennung(new DocumentRepository($this->pdo()), new DocumentArtifactRepository($this->pdo()), $this->blobService());
    }

    private function runner(FakeLlmClient $client): JobRunner
    {
        return new JobRunner(new JobRepository($this->pdo()), [$this->texterkennung(), $this->handler($client)]);
    }

    /** One `POST /api/jobs/step`, phases as in App\Api\JobController. */
    private function schritt(FakeLlmClient $client): JobLaufStatus
    {
        $lauf = $this->runner($client)->schritt($this->berechtigungen(), $this->tresor, new \DateTimeImmutable());
        if ($lauf->aufrufAusstehend()) {
            try {
                $ergebnis = ($lauf->aufruf?->ausfuehren)();
            } catch (\Throwable $e) {
                $ergebnis = $e;
            }
            $lauf = $this->runner($client)->nachAufruf($this->berechtigungen(), $this->tresor, $lauf, $ergebnis, new \DateTimeImmutable());
        }

        return $lauf->status;
    }

    private function allesAbarbeiten(FakeLlmClient $client): void
    {
        for ($i = 0; $i < 30 && $this->schritt($client) === JobLaufStatus::Gearbeitet; $i++) {
        }
        self::assertSame([], $this->pdo()->query("SELECT typ, step, last_error FROM job WHERE status = 'fehler'")->fetchAll(), 'no job error');
    }

    private function berechtigungen(): \App\Domain\Berechtigungen
    {
        return new UserAccessRepository($this->pdo())->berechtigungen($this->userId);
    }

    private function anbieter(): AiProviderRepository
    {
        return new AiProviderRepository($this->pdo(), $this->crypto);
    }

    /** @param ?string $key null keeps it, '' removes it */
    private function profilAendern(KiFaehigkeiten $faehigkeiten, ?string $key): void
    {
        $profil = $this->anbieter()->find($this->standardId);
        assert($profil !== null);
        $this->anbieter()->update($profil->id, $profil->name, $profil->baseUrl, $profil->model, $faehigkeiten, $profil->timeoutS, true, $key);
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    private function auszug(int $id): ?KiAuszug
    {
        $document = new DocumentRepository($this->pdo())->find($id);
        self::assertNotNull($document);

        return $this->handler(new FakeLlmClient([]))->auszug($document, $this->tresor);
    }

    /**
     * Nothing of the receipt in plaintext: not in the artifact's columns,
     * the job row or the audit log.
     */
    private function assertKeinKlartext(int $id): void
    {
        $zeilen = [
            ...$this->artefakte($id),
            ...$this->pdo()->query('SELECT * FROM job')->fetchAll(),
            ...$this->pdo()->query('SELECT * FROM audit_log')->fetchAll(),
        ];
        foreach ($zeilen as $zeile) {
            foreach ($zeile as $wert) {
                foreach (self::INHALTE as $klartext) {
                    self::assertStringNotContainsString($klartext, (string) $wert);
                }
                self::assertStringNotContainsString(self::KEY, (string) $wert);
            }
        }
    }

    /**
     * @param array<string, mixed> $zeile
     *
     * @return array<string, mixed>
     */
    private function entschluesselt(array $zeile): array
    {
        $json = FieldCipher::decrypt(
            $this->tresor->openDataKey((string) $zeile['dek_sealed']),
            (string) $zeile['data_enc'],
            new FieldContext('document_artifact', (int) $zeile['id'], 'data_enc'),
        );
        $daten = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);

        return $daten;
    }

    /**
     * The newest `ai_extract` job of the document.
     *
     * @return array<string, mixed>
     */
    private function job(int $documentId): array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM job WHERE typ = 'ai_extract' AND ref_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$documentId]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function artefakte(int $documentId): array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM document_artifact WHERE document_id = ? AND kind = 'extraction' ORDER BY seq, id");
        $stmt->execute([$documentId]);

        return $stmt->fetchAll();
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

    private static function png(): string
    {
        $bild = imagecreatetruecolor(12, 9);
        imagefill($bild, 0, 0, (int) imagecolorallocate($bild, 200, 30, 30));
        ob_start();
        imagepng($bild);

        return (string) ob_get_clean();
    }
}
