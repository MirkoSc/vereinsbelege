<?php

declare(strict_types=1);

/**
 * The web host side of the worker end-to-end test (docs/spec/07-worker.md
 * "Pflicht-Tests", issue #55): run by tests/e2e/worker/run.sh inside the
 * web host container, as www-data, against the same database and
 * shared/config.php the web host serves `/api/worker/*` from. It plays what
 * a signed-in admin/session would do there - the worker container talks to
 * the real web host over HTTPS only.
 *
 *   vorbereiten   schema, a vault, worker module on; prints a pairing code
 *   bestaetigen   confirms the paired worker; prints its fingerprint
 *   einreichen    waits for the worker's heartbeat, submits the fixture
 *                 scan into the inbox and routes its `ocr` to the worker
 *   pruefen       waits for the worker's result, takes it over like a
 *                 session does and checks it: OCR text and PDF/A in the inbox
 *
 * The vault's private key lives between the phases in a file in shared/var
 * (a directory of the run that run.sh removes afterwards) - throwaway test
 * data, and it survives the web host container being recreated. The scan is synthetic (tests/fixtures/worker/scan-e2e.jpg).
 */

use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Domain\ArtifactKind;
use App\Domain\BlobMeta;
use App\Domain\DocumentArtifact;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\OcrStatus;
use App\Domain\WorkerStatus;
use App\Domain\Zugriffsbereich;
use App\Repository\AuditLogRepository;
use App\Repository\BlobRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\RateLimitRepository;
use App\Repository\SettingRepository;
use App\Repository\VaultRepository;
use App\Repository\WorkerGrantRepository;
use App\Repository\WorkerRepository;
use App\Repository\WorkerUploadRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\Texterkennung;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Service\Upload\UploadService;
use App\Service\Worker\WorkerArtefakte;
use App\Service\Worker\WorkerFreigaben;
use App\Service\Worker\WorkerKopplung;
use App\Service\Worker\WorkerProtokoll;
use App\Service\Worker\WorkerRouting;
use App\Service\Worker\WorkerUebergabe;
use App\Service\Worker\WorkerUebernahme;
use App\Tests\Support\KiAuslesungFabrik;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
date_default_timezone_set('Europe/Berlin');
set_error_handler(static function (int $nr, string $text, string $datei, int $zeile): bool {
    // An @-silenced call (a phase's file not there yet) ends in fehler() below.
    if ((error_reporting() & $nr) === 0) {
        return false;
    }
    throw new ErrorException($text, 0, $nr, $datei, $zeile);
});

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

/** What the fixture scan says - Tesseract has to find it in the image. */
const ERWARTET = ['Testrechnung', '4711', 'Gesamtbetrag'];

function fehler(string $text): never
{
    fwrite(STDERR, 'E2E-FEHLER: ' . $text . "\n");
    exit(1);
}

function info(string $text): void
{
    fwrite(STDERR, '[treiber] ' . $text . "\n");
}

/** @param \Closure(): bool $bedingung */
function warten(string $was, int $sekunden, \Closure $bedingung): void
{
    $bis = time() + $sekunden;
    while (!$bedingung()) {
        if (time() >= $bis) {
            fehler(sprintf('Zeitüberschreitung nach %d s: %s', $sekunden, $was));
        }
        sleep(2);
    }
}

$paths = new Paths($root);
define('TRESOR_DATEI', $paths->varDir() . '/e2e-tresor.json');
define('DOKUMENT_DATEI', $paths->varDir() . '/e2e-dokument.json');
$config = Config::fromFile($paths->configFile());
$pdo = ConnectionFactory::create($config);
$crypto = new ServerCrypto($config->serverKey);
$now = static fn(): DateTimeImmutable => new DateTimeImmutable();

// The same pieces app/src/bootstrap.php puts together for the web host.
$blobs = new BlobRepository($pdo);
$blobService = new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend($paths->blobDir()));
$vaults = new VaultRepository($pdo);
$workers = new WorkerRepository($pdo);
$jobs = new JobRepository($pdo);
$documents = new DocumentRepository($pdo);
$artifacts = new DocumentArtifactRepository($pdo);
$settings = new SettingRepository($pdo);
$artefakte = new WorkerArtefakte(
    $artifacts,
    new WorkerUploadRepository($pdo),
    new UploadService($paths->uploadDir() . '/worker', UploadService::CHUNK_BYTES, WorkerArtefakte::BLOB_MAX_BYTES),
    $blobService,
    $vaults,
    $jobs,
    $settings,
);
$freigaben = new WorkerFreigaben(
    $workers,
    new WorkerGrantRepository($pdo),
    new WorkerUploadRepository($pdo),
    $jobs,
    $blobs,
    $vaults,
    $vaults->rotationInProgress(...),
    $artefakte,
);
$routing = new WorkerRouting($workers, $settings);
$kopplung = new WorkerKopplung(
    $workers,
    $jobs,
    $crypto,
    new RateLimiter(new RateLimitRepository($pdo), WorkerKopplung::FEHLER_FENSTER_SEKUNDEN),
    new AuditLog(new AuditLogRepository($pdo), $vaults, $crypto),
    $freigaben,
);

$tresor = static function (): Vault {
    $daten = json_decode((string) @file_get_contents(TRESOR_DATEI), true);
    if (!is_array($daten) || !is_string($daten['public'] ?? null) || !is_string($daten['secret'] ?? null)) {
        fehler('Tresor fehlt – zuerst `vorbereiten`.');
    }

    return Vault::unlocked((string) base64_decode($daten['public'], true), (string) base64_decode($daten['secret'], true));
};

$worker = static function () use ($workers): App\Domain\Worker {
    return $workers->aktueller() ?? fehler('Kein Worker gekoppelt.');
};

switch ($argv[1] ?? '') {
    case 'vorbereiten':
        new Migrator($pdo, $paths->migrationsDir())->migrate();
        $vault = Vault::create();
        $vaults->insert($vault->publicKey(), $vault->version);
        $alt = umask(0o077);
        file_put_contents(TRESOR_DATEI, json_encode([
            'public' => base64_encode($vault->publicKey()),
            'secret' => base64_encode($vault->secretKey()),
        ], JSON_THROW_ON_ERROR));
        umask($alt);
        $settings->set(WorkerRouting::SETTING_AKTIV, '1');
        $code = $kopplung->codeErzeugen(null, '', $now()) ?? fehler('Kein Kopplungscode erzeugt.');
        info('Schema, Tresor und Worker-Modul bereit.');
        echo $code, "\n";
        break;

    case 'bestaetigen':
        $w = $worker();
        if ($w->status !== WorkerStatus::Gekoppelt) {
            fehler('Worker nicht im Status „gekoppelt“: ' . $w->status->value);
        }
        if (!$kopplung->bestaetigen($w->id, null, '', $now())) {
            fehler('Bestätigen fehlgeschlagen.');
        }
        info(sprintf('Worker %d bestätigt.', $w->id));
        echo WorkerProtokoll::fingerabdruckAnzeige($w->fingerprint), "\n";
        break;

    case 'einreichen':
        // Online with OCR = its first heartbeat after the confirmation.
        warten('Heartbeat des Workers mit Fähigkeit „ocr“', 120, static fn(): bool => $routing->workerFuer(WorkerUebergabe::OCR, $now()) !== null);
        $w = $worker();
        info(sprintf('Worker online: Version %s, Fähigkeiten %s.', $w->appVersion, implode(',', array_map(static fn($f): string => $f->value, $w->faehigkeiten))));

        $vault = $tresor();
        $scan = (string) file_get_contents($root . '/tests/fixtures/worker/scan-e2e.jpg');
        $blob = $blobService->storeString($scan, new BlobMeta(MagicBytes::JPEG), $vault);
        // As the public submission leaves it: in the inbox, OCR pending.
        $doc = $documents->insert(
            DocumentSource::Einreichung,
            null,
            [$blob->id],
            $vault->sealDataKey(DataKey::generate()),
            $now(),
            DocumentStatus::Eingegangen,
            OcrStatus::Ausstehend,
        );
        // What `extract_text` does for a scan without usable text layer.
        $uebergabe = new WorkerUebergabe($routing, $freigaben, $jobs, $documents);
        if ($uebergabe->einreihen(WorkerUebergabe::OCR, null, $doc, $vault, $now()) !== JobExecutor::Worker) {
            fehler('OCR wurde nicht an den Worker übergeben.');
        }
        $stmt = $pdo->prepare('SELECT MAX(id) FROM job WHERE typ = ? AND ref_id = ?');
        $stmt->execute([WorkerUebergabe::OCR, $doc]);
        $jobId = (int) $stmt->fetchColumn();
        file_put_contents(DOKUMENT_DATEI, json_encode(['dokument' => $doc, 'job' => $jobId], JSON_THROW_ON_ERROR));
        info(sprintf('Dokument %d eingereicht, OCR-Job %d an den Worker.', $doc, $jobId));
        break;

    case 'pruefen':
        $daten = json_decode((string) @file_get_contents(DOKUMENT_DATEI), true);
        if (!is_array($daten) || !is_int($daten['dokument'] ?? null) || !is_int($daten['job'] ?? null)) {
            fehler('Kein Dokument – zuerst `einreichen`.');
        }
        ['dokument' => $doc, 'job' => $jobId] = $daten;
        $sekunden = (int) ($argv[2] ?? 600);
        warten('Worker-Job ' . $jobId . ' abgeschlossen', $sekunden, static function () use ($jobs, $jobId): bool {
            $job = $jobs->find($jobId) ?? fehler('Job verschwunden.');
            if ($job->status === JobStatus::Fehler || $job->status === JobStatus::Uebersprungen) {
                fehler(sprintf('Worker-Job %s: %s', $job->status->value, (string) $job->lastError));
            }

            return $job->status === JobStatus::Fertig;
        });
        info('Worker-Job fertig.');

        // The takeover the worker API queued, run like a signed-in session.
        $vault = $tresor();
        $texterkennung = new Texterkennung($documents, $artifacts, $blobService);
        $uebernahme = new WorkerUebernahme($documents, $artifacts, $blobs, $jobs, $texterkennung, KiAuslesungFabrik::bauen($pdo, $blobService, $crypto));
        $job = $jobs->claim(JobExecutor::Session, 'e2e-sitzung', 60, WorkerUebernahme::JOB_TYP) ?? fehler('Keine Übernahme eingereiht.');
        $jobs->schrittErledigt($job->id, 'e2e-sitzung', $uebernahme->schritt($job, $vault, $now()));

        $dokument = $documents->find($doc) ?? fehler('Dokument verschwunden.');
        if ($dokument->ocrStatus !== OcrStatus::Fertig) {
            fehler('OCR-Status ist ' . $dokument->ocrStatus->value . ', erwartet fertig.');
        }
        if ($dokument->status !== DocumentStatus::Eingegangen || $documents->inboxItem($doc, Zugriffsbereich::unbeschraenkt()) === null) {
            fehler('Das Dokument ist nicht mehr im Posteingang.');
        }
        $vomWorker = static fn(ArtifactKind $kind): array => array_values(array_filter(
            $artifacts->fuerDokument($doc, $kind),
            static fn(DocumentArtifact $a): bool => $a->jobId === $jobId && $a->producer === JobExecutor::Worker,
        ));
        $pdfa = $vomWorker(ArtifactKind::Pdfa);
        if (count($pdfa) !== 1 || count($vomWorker(ArtifactKind::Text)) !== 1) {
            fehler('Erwartet je ein Text- und PDF/A-Artefakt vom Worker.');
        }
        $pdfaBlob = $blobService->find((int) $pdfa[0]->blobId) ?? fehler('PDF/A-Blob fehlt.');
        $kopf = '';
        foreach ($blobService->openRead($pdfaBlob, $vault) as $stueck) {
            $kopf .= $stueck;
            break;
        }
        if (!str_starts_with($kopf, '%PDF-')) {
            fehler('Das PDF/A-Artefakt ist kein PDF.');
        }
        $text = $texterkennung->textFuerKi($dokument, $vault) ?? '';
        foreach (ERWARTET as $wort) {
            if (!str_contains($text, $wort)) {
                // Only the synthetic fixture's text - no real receipt data.
                fehler(sprintf("„%s“ nicht im erkannten Text:\n%s", $wort, $text));
            }
        }
        info('Ergebnis im Posteingang: OCR-Text und PDF/A vom Worker, mit dem Tresor lesbar.');
        echo "OK\n";
        break;

    default:
        fwrite(STDERR, "php tests/e2e/worker/treiber.php vorbereiten|bestaetigen|einreichen|pruefen [sekunden]\n");
        exit(2);
}
