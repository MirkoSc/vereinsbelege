<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Domain\ArtifactKind;
use App\Domain\BlobMeta;
use App\Domain\DocumentSource;
use App\Domain\InboxItem;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\Worker;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\StreamResponse;
use App\Repository\SubmissionRepository;
use App\Service\Cron\WorkerCleanupTask;
use App\Repository\WorkerNonceRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Storage\BlobCipher;
use App\Service\Upload\MagicBytes;
use App\Service\Worker\WorkerArtefakte;
use App\Service\Worker\WorkerFreigabeVerweigert;
use App\Service\Worker\WorkerKonfiguration;
use App\Service\Worker\WorkerKrypto;

/**
 * Worker grants, worker context and the result upload (issue #52/M7b-2,
 * docs/spec/07-worker.md section 3 and the Pflicht-Tests there): the worker
 * reads only what was sealed to ITS key for a job it holds, never the vault
 * private key; its results are readable with the vault and not with W_priv;
 * grants go once the work is done, on unpairing and by deadline.
 */
final class WorkerGrantTest extends WorkerTestCase
{
    // ------------------------------------------------------------ granting

    public function testGrantsAreSealedToTheActiveWorkerOnly(): void
    {
        [$doc, $blobId] = $this->dokumentMitBlob();
        $this->koppeln(bestaetigen: false);
        try {
            $this->freigaben->ausSession($this->eintrag($doc), $this->vault, new \DateTimeImmutable());
            self::fail('An unconfirmed worker must not get grants.');
        } catch (WorkerFreigabeVerweigert $e) {
            self::assertSame('kein_worker', $e->grund);
        }
        self::assertSame([], $this->grants->blobIdsFuer((int) $this->workers->aktueller()?->id, $doc, new \DateTimeImmutable()));

        $this->kopplung->bestaetigen((int) $this->workers->aktueller()?->id, null, '', new \DateTimeImmutable());
        $workerId = (int) $this->workers->aktueller()?->id;
        self::assertSame(1, $this->freigaben->ausSession($this->eintrag($doc), $this->vault, new \DateTimeImmutable()));
        self::assertSame([$blobId], $this->grants->blobIdsFuer($workerId, $doc, new \DateTimeImmutable()));

        $sealed = $this->grantRoh($workerId, $blobId);
        $blob = $this->blobService->find($blobId);
        self::assertNotNull($blob);
        // W_priv opens the grant to exactly the blob's data key ...
        self::assertTrue(WorkerKrypto::grantOeffnen($sealed, $this->workerSchluesselpaar)->equals($this->vault->openDataKey($blob->dekSealed)));
        // ... and no other key does - neither another worker's nor the vault's.
        foreach ([sodium_crypto_box_keypair(), sodium_crypto_box_keypair_from_secretkey_and_publickey($this->vault->secretKey(), $this->vault->publicKey())] as $fremd) {
            try {
                WorkerKrypto::grantOeffnen($sealed, $fremd);
                self::fail('A foreign key opened the grant.');
            } catch (CryptoException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNoSessionGrantsDuringAVaultRotationOrFromAnOldGeneration(): void
    {
        [$doc] = $this->dokumentMitBlob();
        $this->koppeln();

        $this->rotation = true;
        $this->assertVerweigert('rotation', fn() => $this->freigaben->ausSession($this->eintrag($doc), $this->vault, new \DateTimeImmutable()));

        $this->rotation = false;
        $alt = Vault::create(2);
        $this->assertVerweigert('rotation', fn() => $this->freigaben->ausSession($this->eintrag($doc), $alt, new \DateTimeImmutable()));
        $this->assertVerweigert('tresor_gesperrt', fn() => $this->freigaben->ausSession($this->eintrag($doc), Vault::locked($this->vault->publicKey()), new \DateTimeImmutable()));
    }

    public function testTheWorkerContextNeverCarriesNameEmailOrIban(): void
    {
        [$doc, , $eintrag] = $this->dokumentMitEinreichung([
            'name' => 'Erika Mustermann',
            'email' => 'erika@example.org',
            'erstattung' => ['art' => 'ueberweisung', 'iban' => 'DE02120300000000202051', 'kontoinhaber' => 'Erika Mustermann'],
            'freitext' => 'Trikots fuer die E-Jugend',
            'kostenstelle_hinweis' => 3,
        ]);
        [$workerId] = $this->koppeln();
        $this->freigaben->ausSession($eintrag, $this->vault, new \DateTimeImmutable());

        $sealed = $this->grants->kontextFuer($workerId, $doc, new \DateTimeImmutable());
        self::assertNotNull($sealed);
        $json = sodium_crypto_box_seal_open($sealed, $this->workerSchluesselpaar);
        self::assertIsString($json);
        self::assertSame(['freitext', 'kostenstelle_hinweis'], array_keys((array) json_decode($json, true)));
        foreach (['Erika', 'Mustermann', 'erika@example.org', 'DE02120300000000202051', 'ueberweisung'] as $verboten) {
            self::assertStringNotContainsString($verboten, $json);
        }
        self::assertSame(['freitext' => 'Trikots fuer die E-Jugend', 'kostenstelle_hinweis' => 3], WorkerKrypto::kontextOeffnen($sealed, $this->workerSchluesselpaar));
    }

    // ------------------------------------------------------------- reading

    public function testABlobNeedsAGrantAndAClaimedJobOnItsDocument(): void
    {
        [$doc, $blobId] = $this->dokumentMitBlob('Kassenbon Inhalt');
        [$anderesDoc] = $this->dokumentMitBlob();
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);

        // A grant but no claimed job: 403.
        $this->freigaben->ausSession($this->eintrag($doc), $this->vault, new \DateTimeImmutable());
        self::assertSame(403, $this->blobAbruf($id, $schluessel, $blobId)->status);

        // A claimed job, but on another document: still 403.
        $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', $anderesDoc);
        $this->claimen($id, $schluessel);
        self::assertSame(403, $this->blobAbruf($id, $schluessel, $blobId)->status);

        // A claimed job on the blob's document: ciphertext plus grant.
        $jobId = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', $doc);
        $job = $this->claimen($id, $schluessel);
        self::assertSame($jobId, $job['id']);
        $antwort = $this->blobAbruf($id, $schluessel, $blobId);
        self::assertInstanceOf(StreamResponse::class, $antwort);
        $dek = WorkerKrypto::grantOeffnen((string) base64_decode($antwort->headers['X-Worker-Dek'], true), $this->workerSchluesselpaar);
        $reader = BlobCipher::reader($dek, (string) base64_decode($antwort->headers['X-Blob-Header'], true));
        $klartext = '';
        foreach ($antwort->chunks as $stueck) {
            $klartext .= $reader->read($stueck);
        }
        self::assertSame('Kassenbon Inhalt', $klartext . $reader->finish());

        // A blob without a grant, even of a held document: 403.
        $ohneGrant = $this->blobService->storeString('x', new BlobMeta(MagicBytes::JPEG), $this->vault)->id;
        self::assertSame(403, $this->blobAbruf($id, $schluessel, $ohneGrant)->status);

        // Done: grants gone, the blob closed again.
        $this->abschluss($id, $schluessel, $jobId, 'complete', ['lock' => $job['lock']]);
        self::assertSame([], $this->grants->blobIdsFuer($id, $doc, new \DateTimeImmutable()));
        self::assertSame(403, $this->blobAbruf($id, $schluessel, $blobId)->status);
    }

    public function testTheContextOfAHeldJobCarriesVaultKeyBlobsAndSealedContext(): void
    {
        [$doc, $blobId, $eintrag] = $this->dokumentMitEinreichung(['freitext' => 'Ball', 'name' => 'Maximilian Mustermann']);
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);
        $this->freigaben->ausSession($eintrag, $this->vault, new \DateTimeImmutable());
        $jobId = $this->jobs->enqueue('ai_extract', JobExecutor::Worker, 'document', $doc);

        self::assertSame(404, $this->kontext($id, $schluessel, $jobId)->status);
        $this->claimen($id, $schluessel);
        $antwort = $this->kontext($id, $schluessel, $jobId);
        self::assertSame(200, $antwort->status);
        $daten = self::daten($antwort);
        self::assertSame($this->vault->version, $daten['tresor']['version']);
        self::assertSame($this->vault->publicKey(), base64_decode((string) $daten['tresor']['public_key'], true));
        self::assertSame([$blobId], $daten['dokument']['blobs']);
        self::assertSame(['extraction'], $daten['artefakte']);
        self::assertSame('Ball', WorkerKrypto::kontextOeffnen((string) base64_decode((string) $daten['dokument']['kontext'], true), $this->workerSchluesselpaar)['freitext']);
        self::assertStringNotContainsString('Maximilian', $antwort->body);
    }

    // ------------------------------------------------------------ deleting

    public function testGrantsStayForARetryAndGoAfterTheFinalFailure(): void
    {
        [$doc] = $this->dokumentMitBlob();
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);
        $this->freigaben->ausSession($this->eintrag($doc), $this->vault, new \DateTimeImmutable());
        $jobId = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', $doc);

        $job = $this->claimen($id, $schluessel);
        $this->abschluss($id, $schluessel, $jobId, 'fail', ['lock' => $job['lock'], 'fehler' => 'Tesseract']);
        self::assertSame(JobStatus::Offen, $this->jobs->find($jobId)?->status);
        self::assertNotSame([], $this->grants->blobIdsFuer($id, $doc, new \DateTimeImmutable()));

        $job = $this->claimen($id, $schluessel);
        $this->abschluss($id, $schluessel, $jobId, 'fail', ['lock' => $job['lock'], 'fehler' => 'Tesseract', 'endgueltig' => true]);
        self::assertSame(JobStatus::Fehler, $this->jobs->find($jobId)?->status);
        self::assertSame([], $this->grants->blobIdsFuer($id, $doc, new \DateTimeImmutable()));
    }

    public function testReleaseGivesTheJobBackWithoutUsingUpAnAttemptAndDropsItsResults(): void
    {
        [$doc] = $this->dokumentMitBlob();
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);
        $this->freigaben->ausSession($this->eintrag($doc), $this->vault, new \DateTimeImmutable());
        $jobId = $this->jobs->enqueue('render_pages', JobExecutor::Worker, 'document', $doc);
        $blobsVorher = $this->blobAnzahl();

        $job = $this->claimen($id, $schluessel);
        self::assertSame(1, $job['versuch']);
        $this->blobHochladen($id, $schluessel, $job, 'page_image', 0, random_bytes(5000));
        // A second page only announced, not committed.
        $dek = DataKey::generate();
        $writer = BlobCipher::writer($dek);
        $chiffrat = $writer->write('Seite 2') . $writer->finish();
        self::assertSame(201, $this->anmelden($id, $schluessel, $job, [
            'kind' => 'page_image',
            'seq' => 1,
            'dek_sealed' => base64_encode(WorkerKrypto::fuerTresorVersiegeln($dek, $this->vault->publicKey(), $this->vault->version)),
            'header' => base64_encode($writer->header()),
            'groesse' => strlen($chiffrat),
            'sha256' => hash('sha256', $chiffrat),
        ])->status);
        self::assertSame($blobsVorher + 1, $this->blobAnzahl());

        // Only the holder's lock.
        self::assertSame(409, $this->abschluss($id, $schluessel, $jobId, 'release', ['lock' => $job['lock'] . 'x'])->status);
        self::assertSame(404, $this->abschluss($id, $schluessel, $jobId + 99, 'release', ['lock' => $job['lock']])->status);

        $antwort = $this->abschluss($id, $schluessel, $jobId, 'release', ['lock' => $job['lock']]);
        self::assertSame(200, $antwort->status, $antwort->body);
        self::assertSame('offen', self::daten($antwort)['status']);
        $nachher = $this->jobs->find($jobId);
        self::assertSame(JobStatus::Offen, $nachher?->status);
        self::assertSame(0, $nachher->attempts, 'A release does not use up an attempt.');
        self::assertNull($nachher->lockedUntil);
        // Results of the cut-off attempt are gone - committed and announced -,
        // the grants stay for the next one.
        self::assertSame([], $this->artifacts->fuerDokument($doc, ArtifactKind::PageImage));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM worker_upload')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM document_artifact WHERE kind LIKE '~%'")->fetchColumn());
        self::assertSame($blobsVorher, $this->blobAnzahl());
        self::assertNotSame([], $this->grants->blobIdsFuer($id, $doc, new \DateTimeImmutable()));

        // Idempotent: the same release again (its answer got lost).
        $wieder = $this->abschluss($id, $schluessel, $jobId, 'release', ['lock' => $job['lock']]);
        self::assertSame(200, $wieder->status, $wieder->body);
        self::assertSame(0, $this->jobs->find($jobId)?->attempts);

        // The next claim counts the same attempt again and starts clean.
        $neu = $this->claimen($id, $schluessel);
        self::assertSame(1, $neu['versuch']);
        self::assertSame(409, $this->abschluss($id, $schluessel, $jobId, 'release', ['lock' => $job['lock']])->status, 'The old lock is no longer the holder.');
        $this->blobHochladen($id, $schluessel, $neu, 'page_image', 0, random_bytes(5000));
        self::assertCount(1, $this->artifacts->fuerDokument($doc, ArtifactKind::PageImage));
    }

    public function testResultsOfAnAbortedAttemptAreDroppedBeforeTheNextAttempt(): void
    {
        [$doc] = $this->dokumentMitBlob();
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);
        $this->freigaben->ausSession($this->eintrag($doc), $this->vault, new \DateTimeImmutable());
        $jobId = $this->jobs->enqueue('render_pages', JobExecutor::Worker, 'document', $doc);
        $blobsVorher = $this->blobAnzahl();

        // Attempt 1 commits a page, then gives up for now.
        $job = $this->claimen($id, $schluessel);
        $this->blobHochladen($id, $schluessel, $job, 'page_image', 0, random_bytes(4000));
        $this->abschluss($id, $schluessel, $jobId, 'fail', ['lock' => $job['lock'], 'fehler' => 'Netz']);
        self::assertSame([], $this->artifacts->fuerDokument($doc, ArtifactKind::PageImage));
        self::assertSame($blobsVorher, $this->blobAnzahl());

        // Attempt 2 commits a page and dies: its lock runs out.
        $job = $this->claimen($id, $schluessel);
        self::assertSame(2, $job['versuch']);
        $this->blobHochladen($id, $schluessel, $job, 'page_image', 0, random_bytes(4000));
        $this->pdo()->prepare('UPDATE job SET locked_until = ? WHERE id = ?')->execute(['2000-01-01 00:00:00', $jobId]);

        // Attempt 3 does not find page 0 "vorhanden" - it starts clean.
        $job = $this->claimen($id, $schluessel);
        self::assertSame(3, $job['versuch']);
        self::assertSame([], $this->artifacts->fuerDokument($doc, ArtifactKind::PageImage));
        self::assertSame($blobsVorher, $this->blobAnzahl());
        $this->blobHochladen($id, $schluessel, $job, 'page_image', 0, random_bytes(4000));
        self::assertCount(1, $this->artifacts->fuerDokument($doc, ArtifactKind::PageImage));
    }

    public function testGrantsStayWhileAnotherWorkerJobOfTheDocumentWaits(): void
    {
        [$doc] = $this->dokumentMitBlob();
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);
        $this->freigaben->ausSession($this->eintrag($doc), $this->vault, new \DateTimeImmutable());
        $ocr = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', $doc);
        $this->jobs->enqueue('ai_extract', JobExecutor::Worker, 'document', $doc);

        $job = $this->claimen($id, $schluessel);
        self::assertSame($ocr, $job['id']);
        $this->abschluss($id, $schluessel, $ocr, 'complete', ['lock' => $job['lock']]);
        self::assertNotSame([], $this->grants->blobIdsFuer($id, $doc, new \DateTimeImmutable()));
    }

    public function testGrantsGoWhenTheAttemptsAreUsedUp(): void
    {
        [$doc] = $this->dokumentMitBlob();
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);
        $this->freigaben->ausSession($this->eintrag($doc), $this->vault, new \DateTimeImmutable());
        $jobId = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', $doc);
        $this->pdo()->prepare('UPDATE job SET attempts = 3 WHERE id = ?')->execute([$jobId]);

        $antwort = $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/jobs/claim', '{"max":1}'),
            fn(Request $r, array $p, Worker $w): Response => $this->api->claim($r, $w),
        );
        self::assertSame([], self::daten($antwort)['jobs']);
        self::assertSame(JobStatus::Fehler, $this->jobs->find($jobId)?->status);
        self::assertSame([], $this->grants->blobIdsFuer($id, $doc, new \DateTimeImmutable()));
    }

    public function testUnpairingDeletesEverythingSealedToTheWorker(): void
    {
        [$doc, , $eintrag] = $this->dokumentMitEinreichung(['freitext' => 'Ball']);
        [$id] = $this->koppeln();
        $this->freigaben->ausSession($eintrag, $this->vault, new \DateTimeImmutable());
        $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', $doc);

        self::assertTrue($this->kopplung->entkoppeln($id, null, '', new \DateTimeImmutable()));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM worker_grant')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM worker_context')->fetchColumn());
    }

    public function testTheCronDropsExpiredAndOrphanedGrants(): void
    {
        [$wartet] = $this->dokumentMitBlob();
        [$verwaist] = $this->dokumentMitBlob();
        [$abgelaufen] = $this->dokumentMitBlob();
        $this->koppeln();
        $t0 = new \DateTimeImmutable('-2 hours');
        foreach ([$wartet, $verwaist, $abgelaufen] as $doc) {
            $this->freigaben->ausSession($this->eintrag($doc), $this->vault, $t0);
        }
        $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', $wartet);
        $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', $abgelaufen);
        $this->pdo()->prepare('UPDATE worker_grant SET expires_at = ? WHERE document_id = ?')->execute([$t0->format('Y-m-d H:i:s'), $abgelaufen]);

        (new WorkerCleanupTask(new WorkerNonceRepository($this->pdo()), $this->workers, $this->freigaben))->run(new \DateTimeImmutable());

        $uebrig = array_map(intval(...), $this->pdo()->query('SELECT DISTINCT document_id FROM worker_grant')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame([$wartet], $uebrig);
    }

    // --------------------------------------------------------- the results

    public function testABlobResultIsReadableWithTheVaultAndNotWithTheWorkerKey(): void
    {
        [$doc] = $this->dokumentMitBlob();
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);
        $jobId = $this->jobs->enqueue('render_pages', JobExecutor::Worker, 'document', $doc);
        $job = $this->claimen($id, $schluessel);

        $seite = random_bytes(150000);
        $artefakt = $this->blobHochladen($id, $schluessel, $job, 'page_image', 0, $seite);
        $zeile = $this->artifacts->rohzeile($artefakt);
        self::assertSame('page_image', $zeile['kind'] ?? null);
        $liste = $this->artifacts->fuerDokument($doc, ArtifactKind::PageImage);
        self::assertCount(1, $liste);
        $blob = $this->blobService->find((int) $liste[0]->blobId);
        self::assertNotNull($blob);
        self::assertSame(strlen($seite), $blob->size);
        self::assertSame($seite, implode('', iterator_to_array($this->blobService->openRead($blob, $this->vault), false)));

        // The worker sealed it to VK_pub: its own key cannot open the data key.
        self::assertFalse(@sodium_crypto_box_seal_open(substr($blob->dekSealed, 1), $this->workerSchluesselpaar));
        self::assertSame($jobId, $job['id']);
    }

    public function testADataResultIsBoundToItsRowAndReadableWithTheVault(): void
    {
        [$doc] = $this->dokumentMitBlob();
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);
        $this->jobs->enqueue('ai_extract', JobExecutor::Worker, 'document', $doc);
        $job = $this->claimen($id, $schluessel);
        $dek = DataKey::generate();
        $klartext = '{"betrag":1234}';
        // FieldCipher adds a fixed overhead, so the length is known before the id.
        $laenge = strlen(FieldCipher::encrypt($dek, $klartext, new FieldContext('document_artifact', 1, 'data_enc')));
        $anmeldung = static fn(string $kind, int $groesse): array => [
            'kind' => $kind,
            'seq' => 0,
            'dek_sealed' => '',
            'groesse' => $groesse,
        ];
        $versiegelt = base64_encode(WorkerKrypto::fuerTresorVersiegeln($dek, $this->vault->publicKey(), $this->vault->version));

        // Not a kind this job type delivers; too short to be a ciphertext.
        self::assertSame('kind_unzulaessig', self::daten($this->anmelden($id, $schluessel, $job, ['dek_sealed' => $versiegelt] + $anmeldung('text', $laenge)))['fehler']);
        self::assertSame('groesse_ungueltig', self::daten($this->anmelden($id, $schluessel, $job, ['dek_sealed' => $versiegelt] + $anmeldung('extraction', 10)))['fehler']);

        $angemeldet = $this->anmelden($id, $schluessel, $job, ['dek_sealed' => $versiegelt] + $anmeldung('extraction', $laenge));
        self::assertSame(201, $angemeldet->status, $angemeldet->body);
        $artefaktId = (int) self::daten($angemeldet)['artefakt_id'];
        self::assertSame('document_artifact|' . $artefaktId . '|data_enc', self::daten($angemeldet)['aad']);
        self::assertSame([], $this->artifacts->fuerDokument($doc, ArtifactKind::Extraction), 'Not visible before the commit.');

        // The same announcement again (lost answer): the same reservation.
        self::assertSame($artefaktId, (int) self::daten($this->anmelden($id, $schluessel, $job, ['dek_sealed' => $versiegelt] + $anmeldung('extraction', $laenge)))['artefakt_id']);

        $daten = FieldCipher::encrypt($dek, $klartext, new FieldContext('document_artifact', $artefaktId, 'data_enc'));
        self::assertSame(200, $this->chunk($id, $schluessel, $job, $artefaktId, 0, $daten)->status);
        $fertig = $this->festschreiben($id, $schluessel, $job, $artefaktId);
        self::assertSame('fertig', self::daten($fertig)['status'] ?? null, $fertig->body);

        $liste = $this->artifacts->fuerDokument($doc, ArtifactKind::Extraction);
        self::assertCount(1, $liste);
        self::assertSame($klartext, FieldCipher::decrypt(
            $this->vault->openDataKey($liste[0]->dekSealed),
            (string) $liste[0]->dataEnc,
            new FieldContext('document_artifact', $liste[0]->id, 'data_enc'),
        ));
        self::assertFalse(@sodium_crypto_box_seal_open(substr($liste[0]->dekSealed, 1), $this->workerSchluesselpaar), 'W_priv must not open a result.');

        // Idempotent: commit again, announce again.
        self::assertSame('vorhanden', self::daten($this->festschreiben($id, $schluessel, $job, $artefaktId))['status']);
        self::assertSame('vorhanden', self::daten($this->anmelden($id, $schluessel, $job, ['dek_sealed' => $versiegelt] + $anmeldung('extraction', $laenge)))['status']);
        self::assertCount(1, $this->artifacts->fuerDokument($doc, ArtifactKind::Extraction));
    }

    public function testUploadsOnlyForOwnJobsWithinLimits(): void
    {
        [$doc] = $this->dokumentMitBlob();
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);
        $jobId = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', $doc);
        $job = $this->claimen($id, $schluessel);
        $dek = DataKey::generate();
        $gut = static fn(array $mehr): array => $mehr + [
            'kind' => 'pdfa',
            'seq' => 0,
            'dek_sealed' => '',
            'header' => base64_encode(BlobCipher::writer(DataKey::generate())->header()),
            'groesse' => 1000,
            'sha256' => str_repeat('a', 64),
        ];
        $versiegelt = base64_encode(WorkerKrypto::fuerTresorVersiegeln($dek, $this->vault->publicKey(), $this->vault->version));

        // Wrong lock, a stale attempt, a foreign vault generation, too large.
        self::assertSame(409, $this->anmelden($id, $schluessel, ['lock' => 'w' . $id . '-falsch'] + $job, $gut(['dek_sealed' => $versiegelt]))->status);
        self::assertSame('versuch_veraltet', self::daten($this->anmelden($id, $schluessel, ['versuch' => $job['versuch'] - 1] + $job, $gut(['dek_sealed' => $versiegelt])))['fehler']);
        $fremd = base64_encode(WorkerKrypto::fuerTresorVersiegeln($dek, $this->vault->publicKey(), $this->vault->version + 1));
        self::assertSame('dek_ungueltig', self::daten($this->anmelden($id, $schluessel, $job, $gut(['dek_sealed' => $fremd])))['fehler']);
        self::assertSame(413, $this->anmelden($id, $schluessel, $job, $gut(['dek_sealed' => $versiegelt, 'groesse' => WorkerArtefakte::BLOB_MAX_BYTES + 1]))->status);
        self::assertSame(413, $this->anmelden($id, $schluessel, $job, ['kind' => 'text', 'seq' => 0, 'dek_sealed' => $versiegelt, 'groesse' => WorkerArtefakte::DATEN_MAX_BYTES + 1])->status);

        // A wrong checksum is caught at the commit.
        $angemeldet = $this->anmelden($id, $schluessel, $job, $gut(['dek_sealed' => $versiegelt, 'groesse' => 100]));
        self::assertSame(201, $angemeldet->status, $angemeldet->body);
        $artefaktId = (int) self::daten($angemeldet)['artefakt_id'];
        self::assertSame(200, $this->chunk($id, $schluessel, $job, $artefaktId, 0, random_bytes(100))->status);
        $blobsVorher = $this->blobAnzahl();
        self::assertSame('pruefsumme_falsch', self::daten($this->festschreiben($id, $schluessel, $job, $artefaktId))['fehler']);
        self::assertSame($blobsVorher, $this->blobAnzahl(), 'A refused upload leaves no blob behind.');
        self::assertNull($this->uploads->find($artefaktId));

        // After the job went back and was claimed again, the old claim's
        // upload is not the worker's to continue.
        $angemeldet = $this->anmelden($id, $schluessel, $job, $gut(['dek_sealed' => $versiegelt, 'groesse' => 100]));
        $artefaktId = (int) self::daten($angemeldet)['artefakt_id'];
        $this->abschluss($id, $schluessel, $jobId, 'fail', ['lock' => $job['lock'], 'fehler' => 'X']);
        $neu = $this->claimen($id, $schluessel);
        self::assertSame(404, $this->chunk($id, $schluessel, $neu, $artefaktId, 0, random_bytes(100))->status);
        self::assertNull($this->uploads->find($artefaktId), 'A failed attempt leaves no upload behind.');
    }

    public function testAnUploadAnnouncedUnderTheOldVaultGenerationIsRefusedAfterARotationStarted(): void
    {
        [$doc] = $this->dokumentMitBlob();
        [$id, $schluessel] = $this->koppeln();
        $this->heartbeat($id, $schluessel);
        $this->jobs->enqueue('render_pages', JobExecutor::Worker, 'document', $doc);
        $job = $this->claimen($id, $schluessel);

        $dek = DataKey::generate();
        $writer = BlobCipher::writer($dek);
        $chiffrat = $writer->write('Seite') . $writer->finish();
        $angemeldet = $this->anmelden($id, $schluessel, $job, [
            'kind' => 'page_image',
            'seq' => 0,
            'dek_sealed' => base64_encode(WorkerKrypto::fuerTresorVersiegeln($dek, $this->vault->publicKey(), $this->vault->version)),
            'header' => base64_encode($writer->header()),
            'groesse' => strlen($chiffrat),
            'sha256' => hash('sha256', $chiffrat),
        ]);
        self::assertSame(201, $angemeldet->status, $angemeldet->body);
        $artefaktId = (int) self::daten($angemeldet)['artefakt_id'];
        self::assertSame(200, $this->chunk($id, $schluessel, $job, $artefaktId, 0, $chiffrat)->status);

        // A rotation's new generation is confirmed and therefore current.
        $neu = Vault::create($this->vault->version + 1);
        (new \App\Repository\VaultRepository($this->pdo()))->insert($neu->publicKey(), $neu->version);
        $blobsVorher = $this->blobAnzahl();

        self::assertSame('dek_veraltet', self::daten($this->festschreiben($id, $schluessel, $job, $artefaktId))['fehler'] ?? null);
        self::assertSame($blobsVorher, $this->blobAnzahl());
        self::assertNull($this->uploads->find($artefaktId));
        self::assertSame([], $this->artifacts->fuerDokument($doc, ArtifactKind::PageImage));
    }

    // ---------------------------------------------------------- AI config

    public function testTheAiConfigurationIsSealedToTheWorkerAndResealedOnChange(): void
    {
        $anbieter = $this->anbieter->insert('Worker-Testanbieter', 'https://api.example.org/v1', 'gpt-test', new KiFaehigkeiten(true, true, 4, 1000), 60, true, 'sk-geheim-1');
        [$id, $schluessel] = $this->koppeln();

        $erste = self::daten($this->heartbeat($id, $schluessel));
        self::assertSame(1, $erste['config']['version']);
        $json = sodium_crypto_box_seal_open((string) base64_decode((string) $erste['config']['versiegelt'], true), $this->workerSchluesselpaar);
        self::assertIsString($json);
        $konfig = json_decode($json, true);
        self::assertSame(WorkerKonfiguration::FORMAT, $konfig['format']);
        $profil = array_values(array_filter($konfig['ki_anbieter'], static fn(array $a): bool => $a['id'] === $anbieter));
        self::assertCount(1, $profil);
        self::assertSame('sk-geheim-1', $profil[0]['api_key']);
        self::assertStringNotContainsString('sk-geheim', (string) $this->pdo()->query('SELECT config_sealed FROM worker')->fetchColumn());

        self::assertNull(self::daten($this->heartbeat($id, $schluessel, configVersion: 1))['config'], 'Unchanged: nothing new.');

        $this->anbieter->update($anbieter, 'Worker-Testanbieter', 'https://api.example.org/v1', 'gpt-test', new KiFaehigkeiten(true, true, 4, 1000), 60, true, 'sk-geheim-2');
        $zweite = self::daten($this->heartbeat($id, $schluessel, configVersion: 1));
        self::assertSame(2, $zweite['config']['version']);
    }

    // ------------------------------------------------------------- helpers

    /**
     * @return array{int, int} document id, blob id
     */
    private function dokumentMitBlob(string $inhalt = 'Bild'): array
    {
        $blob = $this->blobService->storeString($inhalt, new BlobMeta(MagicBytes::JPEG), $this->vault);
        $doc = $this->documents->insert(DocumentSource::Intern, null, [$blob->id], $this->vault->sealDataKey(DataKey::generate()), new \DateTimeImmutable());

        return [$doc, $blob->id];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{int, int, InboxItem}
     */
    private function dokumentMitEinreichung(array $payload): array
    {
        $submissions = new SubmissionRepository($this->pdo());
        $submissionId = $submissions->insertDraft(random_bytes(32), new \DateTimeImmutable());
        $dek = DataKey::generate();
        $dekSealed = $this->vault->sealDataKey($dek);
        $payloadEnc = FieldCipher::encrypt($dek, json_encode($payload, JSON_THROW_ON_ERROR), new FieldContext('submission', $submissionId, 'payload_enc'));
        $submissions->complete($submissionId, 'R-2026-' . str_pad((string) $submissionId, 4, '0', STR_PAD_LEFT), $dekSealed, $payloadEnc);
        $blob = $this->blobService->storeString('Bild', new BlobMeta(MagicBytes::JPEG), $this->vault);
        $doc = $this->documents->insert(DocumentSource::Einreichung, $submissionId, [$blob->id], $this->vault->sealDataKey(DataKey::generate()), new \DateTimeImmutable());
        $document = $this->documents->find($doc);
        self::assertNotNull($document);

        return [$doc, $blob->id, new InboxItem($document, null, null, $dekSealed, $payloadEnc)];
    }

    private function eintrag(int $doc): InboxItem
    {
        $document = $this->documents->find($doc);
        self::assertNotNull($document);

        return new InboxItem($document, null, null, null, null);
    }

    private function grantRoh(int $workerId, int $blobId): string
    {
        $stmt = $this->pdo()->prepare('SELECT sealed_dek FROM worker_grant WHERE worker_id = ? AND blob_id = ?');
        $stmt->execute([$workerId, $blobId]);

        return (string) $stmt->fetchColumn();
    }

    private function blobAnzahl(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob')->fetchColumn();
    }

    private function assertVerweigert(string $grund, \Closure $aufruf): void
    {
        try {
            $aufruf();
            self::fail('Expected the grant to be refused: ' . $grund);
        } catch (WorkerFreigabeVerweigert $e) {
            self::assertSame($grund, $e->grund);
        }
    }

    private function heartbeat(int $id, string $schluessel, int $configVersion = 0): Response
    {
        $body = json_encode(['protokoll_version' => 1, 'version' => '2.0.0', 'faehigkeiten' => ['ocr', 'render', 'ai'], 'config_version' => $configVersion], JSON_THROW_ON_ERROR);

        return $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/heartbeat', $body),
            fn(Request $r, array $p, Worker $w): Response => $this->api->heartbeat($r, $w),
        );
    }

    /**
     * @return array{id: int, lock: string, versuch: int}
     */
    private function claimen(int $id, string $schluessel): array
    {
        $antwort = $this->aufruf(
            $this->signiert($id, $schluessel, '/api/worker/jobs/claim', '{"max":1}'),
            fn(Request $r, array $p, Worker $w): Response => $this->api->claim($r, $w),
        );
        $jobs = self::daten($antwort)['jobs'];
        self::assertCount(1, $jobs, $antwort->body);

        return ['id' => (int) $jobs[0]['id'], 'lock' => (string) $jobs[0]['lock'], 'versuch' => (int) $jobs[0]['versuch']];
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function abschluss(int $id, string $schluessel, int $jobId, string $aktion, array $daten): Response
    {
        $pfad = sprintf('/api/worker/jobs/%d/%s', $jobId, $aktion);

        return $this->aufruf(
            $this->signiert($id, $schluessel, $pfad, json_encode($daten, JSON_THROW_ON_ERROR)),
            fn(Request $r, array $p, Worker $w): Response => match ($aktion) {
                'complete' => $this->api->complete($r, $p, $w),
                'release' => $this->api->release($r, $p, $w),
                default => $this->api->fail($r, $p, $w),
            },
            ['id' => (string) $jobId],
        );
    }

    private function blobAbruf(int $id, string $schluessel, int $blobId): Response|StreamResponse
    {
        $antwort = $this->auth->ausfuehren(
            $this->signiert($id, $schluessel, '/api/worker/blobs/' . $blobId, methode: HttpMethod::Get),
            ['id' => (string) $blobId],
            fn(Request $r, array $p, Worker $w) => $this->api->blob($r, $p, $w),
        );
        self::assertTrue($antwort instanceof Response || $antwort instanceof StreamResponse);

        return $antwort;
    }

    private function kontext(int $id, string $schluessel, int $jobId): Response
    {
        return $this->aufruf(
            $this->signiert($id, $schluessel, sprintf('/api/worker/jobs/%d/context', $jobId), methode: HttpMethod::Get),
            fn(Request $r, array $p, Worker $w): Response => $this->api->context($r, $p, $w),
            ['id' => (string) $jobId],
        );
    }

    /**
     * @param array{id: int, lock: string, versuch: int} $job
     * @param array<string, mixed> $daten
     */
    private function anmelden(int $id, string $schluessel, array $job, array $daten): Response
    {
        $body = json_encode(['lock' => $job['lock'], 'versuch' => $job['versuch']] + $daten, JSON_THROW_ON_ERROR);

        return $this->aufruf(
            $this->signiert($id, $schluessel, sprintf('/api/worker/jobs/%d/artifacts', $job['id']), $body),
            fn(Request $r, array $p, Worker $w): Response => $this->api->artefaktAnmelden($r, $p, $w),
            ['id' => (string) $job['id']],
        );
    }

    /**
     * @param array{id: int, lock: string, versuch: int} $job
     */
    private function chunk(int $id, string $schluessel, array $job, int $artefaktId, int $index, string $bytes): Response
    {
        $pfad = sprintf('/api/worker/jobs/%d/artifacts/%d/chunks/%d', $job['id'], $artefaktId, $index);

        return $this->aufruf(
            $this->signiert($id, $schluessel, $pfad, $bytes),
            fn(Request $r, array $p, Worker $w): Response => $this->api->artefaktChunk($r, $p, $w),
            ['id' => (string) $job['id'], 'artefakt' => (string) $artefaktId, 'index' => (string) $index],
        );
    }

    /**
     * @param array{id: int, lock: string, versuch: int} $job
     */
    private function festschreiben(int $id, string $schluessel, array $job, int $artefaktId): Response
    {
        $pfad = sprintf('/api/worker/jobs/%d/artifacts/%d/commit', $job['id'], $artefaktId);

        return $this->aufruf(
            $this->signiert($id, $schluessel, $pfad, json_encode(['lock' => $job['lock']], JSON_THROW_ON_ERROR)),
            fn(Request $r, array $p, Worker $w): Response => $this->api->artefaktFestschreiben($r, $p, $w),
            ['id' => (string) $job['id'], 'artefakt' => (string) $artefaktId],
        );
    }

    /**
     * Encrypts a page the way the worker does and uploads it in pieces.
     *
     * @param array{id: int, lock: string, versuch: int} $job
     */
    private function blobHochladen(int $id, string $schluessel, array $job, string $kind, int $seq, string $klartext): int
    {
        $dek = DataKey::generate();
        $writer = BlobCipher::writer($dek);
        $chiffrat = $writer->write($klartext) . $writer->finish();
        $antwort = $this->anmelden($id, $schluessel, $job, [
            'kind' => $kind,
            'seq' => $seq,
            'dek_sealed' => base64_encode(WorkerKrypto::fuerTresorVersiegeln($dek, $this->vault->publicKey(), $this->vault->version)),
            'header' => base64_encode($writer->header()),
            'groesse' => strlen($chiffrat),
            'sha256' => hash('sha256', $chiffrat),
        ]);
        self::assertSame(201, $antwort->status, $antwort->body);
        $daten = self::daten($antwort);
        $artefaktId = (int) $daten['artefakt_id'];
        foreach (str_split($chiffrat, (int) $daten['chunk_bytes']) as $index => $stueck) {
            self::assertSame(200, $this->chunk($id, $schluessel, $job, $artefaktId, $index, $stueck)->status);
        }
        $fertig = $this->festschreiben($id, $schluessel, $job, $artefaktId);
        self::assertSame('fertig', self::daten($fertig)['status'] ?? null, $fertig->body);

        return $artefaktId;
    }
}
