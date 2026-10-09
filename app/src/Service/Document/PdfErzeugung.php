<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\Document;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\Permission;
use App\Repository\BlobRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\SubmissionRepository;
use App\Service\Crypto\Vault;
use App\Service\Job\JobHandler;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\Processing\JpegInfo;
use App\Service\Processing\PdfAusBildern;
use App\Service\Processing\ProcessingException;
use App\Service\Processing\SchwarzweissFallback;
use App\Service\Storage\BlobService;
use App\Service\Upload\MagicBytes;

/**
 * The `pdf_erzeugen` job (issue #26/M4-4, docs/spec/03-erfassung-und-ki.md
 * section 3): builds the PDF working copy of a document from its pages, once
 * a signed-in session can decrypt them - from the scanner's processed
 * version of a page where the browser uploaded one, otherwise from the
 * original run through the GD fallback (issue #34/M5-4). The originals are
 * never touched (decision E-10) - this only ever adds `document.pdf_blob_id`.
 *
 * Three short, idempotent steps, none of which does more work than fits a
 * shared-hosting request (CLAUDE.md section 1):
 *
 *   '' (pruefen) - decides what this document even needs. Only images ->
 *      go on to `seite`. Any PDF among the originals -> queues the
 *      `render_pages` browser job (issue #30/M4-8, App\Service\Document\
 *      PdfRasterung) once, since none of them has page images yet; exactly
 *      one PDF and nothing else (an e-invoice's XML aside) -> that upload
 *      already IS the working copy too, done immediately. Only XML, anything
 *      mixed or more than one PDF ->
 *      App\Service\Job\JobSchrittErgebnis::uebersprungen(), the originals
 *      stay the only thing there is (docs/spec/03-erfassung-und-ki.md
 *      section 3: "Hochgeladene PDFs werden nicht umgebaut").
 *   'seite' - picks the working image of every page: the processed version
 *      (`document.processed_blob_ids`) as it is, when it is a JPEG - these
 *      are free to collect in the same call; otherwise the original through
 *      App\Service\Processing\SchwarzweissFallback (greyscale + global
 *      threshold, docs/spec/03-erfassung-und-ki.md section 2), at most one
 *      such conversion per call.
 *   'pdf' - streams App\Service\Processing\PdfAusBildern::erzeuge() straight
 *      into BlobService::store(), attaches it to the document, and drops the
 *      fallback JPEGs `seite` produced (never an original, never a processed
 *      version the browser uploaded).
 *
 * Framework-free (CLAUDE.md section 6a): no Http, no Session. The runner that
 * calls schritt() from a logged-in browser is App\Service\Job\JobRunner
 * (issue #29/M4-7, `POST /api/jobs/step`).
 */
final readonly class PdfErzeugung implements JobHandler
{
    public const string JOB_TYP = 'pdf_erzeugen';

    private const string REF_TYPE = 'document';

    public function __construct(
        private DocumentRepository $documents,
        private BlobRepository $blobs,
        private BlobService $blobService,
        private SubmissionRepository $submissions,
        private JobRepository $jobs,
    ) {
    }

    public function typ(): string
    {
        return self::JOB_TYP;
    }

    /**
     * `document.edit` (issue #29/M4-7): whoever decides on a receipt is who
     * drives the PDF working copy for it - the same right InboxController
     * checks for the decision itself.
     */
    public function recht(): Permission
    {
        return Permission::DocumentEdit;
    }

    public function schritt(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        return match ($job->step) {
            '' => $this->pruefen($job, $vault, $now),
            'seite' => $this->seite($job, $vault),
            'pdf' => $this->pdf($job, $vault),
            default => throw new ProcessingException(sprintf('Unbekannter Schritt "%s".', $job->step)),
        };
    }

    /**
     * Reads only each page's stored MIME type (App\Service\Storage\
     * BlobService::meta(), already known from the upload's magic-byte check
     * - no re-detection) to sort the document into one of the three cases
     * above.
     */
    private function pruefen(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        $document = $this->dokument($job);
        if ($document->pdfBlobId !== null) {
            return JobSchrittErgebnis::fertig();
        }

        $typen = [];
        foreach ($document->originalBlobIds as $blobId) {
            $typen[$blobId] = $this->mimeType($blobId, $vault);
        }

        // The XML of an e-invoice (issue #46/M7-4) is no page: it is read by
        // `extract_text`, not shown. Without anything else there is no
        // working copy to build; next to a single PDF (its visual
        // rendering), that PDF is the working copy; next to images it makes
        // a mix like any other - the `seite` step only knows images.
        $xml = array_filter($typen, static fn (string $typ): bool => $typ === MagicBytes::XML);
        $typen = array_diff_key($typen, $xml);
        if ($typen === []) {
            return JobSchrittErgebnis::uebersprungen();
        }

        $bilder = array_filter(
            $typen,
            static fn (string $typ): bool => $typ === MagicBytes::JPEG || $typ === MagicBytes::PNG,
        );
        if ($xml === [] && count($bilder) === count($typen)) {
            return JobSchrittErgebnis::weiter('seite', ['arbeit' => [], 'zwischen' => []]);
        }

        $pdfs = array_filter($typen, static fn (string $typ): bool => $typ === MagicBytes::PDF);
        if ($pdfs === []) {
            // Images next to an e-invoice's XML - the originals stay the
            // working copy.
            return JobSchrittErgebnis::uebersprungen();
        }

        // At least one PDF among the originals, and none of them has page
        // images yet (section 3: "render_pages nur für PDFs ohne
        // Seitenbilder") - queue the browser job that renders them
        // (issue #30/M4-8, App\Service\Document\PdfRasterung). One job per
        // document, covering every PDF original, not one job per file.
        if (!$this->jobs->gibtEs(PdfRasterung::JOB_TYP, self::REF_TYPE, $document->id)) {
            $this->jobs->enqueue(
                PdfRasterung::JOB_TYP,
                JobExecutor::Browser,
                self::REF_TYPE,
                $document->id,
                now: $now,
            );
        }

        if (count($pdfs) === 1 && count($typen) === 1) {
            $this->documents->setzePdfBlob($document->id, array_key_first($pdfs));

            return JobSchrittErgebnis::fertig();
        }

        // Mixed pages, or more than one PDF: nothing this handler can turn
        // into a single working PDF. The originals remain the working copy.
        return JobSchrittErgebnis::uebersprungen();
    }

    /**
     * One GD fallback per call at most; a run of processed pages is free and
     * collected in the same call (class docblock).
     */
    private function seite(Job $job, Vault $vault): JobSchrittErgebnis
    {
        $document = $this->dokument($job);
        $arbeit = self::intListe($job->state['arbeit'] ?? []);
        $zwischen = self::intListe($job->state['zwischen'] ?? []);

        for ($i = count($arbeit); $i < count($document->originalBlobIds); $i++) {
            // The browser's processed version, used unchanged. Its type is
            // only known here, with the vault open - the submission could not
            // check it (the blob meta is sealed). Anything but a JPEG is
            // ignored and the page falls back like an unprocessed one.
            $verarbeitet = $document->processedBlobId($i);
            if ($verarbeitet !== null && $this->mimeType($verarbeitet, $vault) === MagicBytes::JPEG) {
                $arbeit[] = $verarbeitet;

                continue;
            }

            $blobId = $document->originalBlobIds[$i];
            $typ = $this->mimeType($blobId, $vault);
            if ($typ !== MagicBytes::JPEG && $typ !== MagicBytes::PNG) {
                throw new ProcessingException('Unerwarteter Bildtyp in der PDF-Erzeugung.');
            }

            $original = $this->findBlob($blobId);
            $jpeg = SchwarzweissFallback::aufbereiten(self::volltext($this->blobService, $original, $vault));
            $jpegInfo = JpegInfo::aus($jpeg);
            $aufbereitet = $this->blobService->storeString(
                $jpeg,
                new BlobMeta(MagicBytes::JPEG, width: $jpegInfo->width, height: $jpegInfo->height),
                $vault,
            );
            $arbeit[] = $aufbereitet->id;
            $zwischen[] = $aufbereitet->id;

            return JobSchrittErgebnis::weiter('seite', ['arbeit' => $arbeit, 'zwischen' => $zwischen]);
        }

        return JobSchrittErgebnis::weiter('pdf', ['arbeit' => $arbeit, 'zwischen' => $zwischen]);
    }

    private function pdf(Job $job, Vault $vault): JobSchrittErgebnis
    {
        $document = $this->dokument($job);
        $arbeit = self::intListe($job->state['arbeit'] ?? []);
        $zwischen = self::intListe($job->state['zwischen'] ?? []);

        if ($arbeit === []) {
            throw new ProcessingException('Keine Arbeitsseiten für die PDF-Erzeugung.');
        }

        $titel = $document->submissionId !== null
            ? $this->submissions->findReferenzById($document->submissionId)
            : null;

        $seiten = function () use ($arbeit, $vault): \Generator {
            foreach ($arbeit as $blobId) {
                yield self::volltext($this->blobService, $this->findBlob($blobId), $vault);
            }
        };

        $pdfBlob = $this->blobService->store(
            PdfAusBildern::erzeuge($seiten(), $titel),
            new BlobMeta(MagicBytes::PDF, pages: count($arbeit)),
            $vault,
        );

        // Two racing attempts (two open tabs) can both reach this point; only
        // one may win the document's pdf_blob_id, the other cleans up after
        // itself instead of leaving an orphaned blob.
        if (!$this->documents->setzePdfBlob($document->id, $pdfBlob->id)) {
            $this->blobService->delete($pdfBlob);
        }

        foreach ($zwischen as $blobId) {
            if (in_array($blobId, $document->originalBlobIds, true) || in_array($blobId, $document->processedBlobIds, true)) {
                continue; // never the original (decision E-10), nor the browser's version
            }

            $blob = $this->blobs->find($blobId);
            if ($blob !== null) {
                $this->blobService->delete($blob);
            }
        }

        return JobSchrittErgebnis::fertig();
    }

    private function mimeType(int $blobId, Vault $vault): string
    {
        $blob = $this->findBlob($blobId);
        $meta = $this->blobService->meta($blob, $vault);

        return $meta?->mimeType ?? throw new ProcessingException('Blob ohne Metadaten.');
    }

    private function findBlob(int $blobId): Blob
    {
        return $this->blobs->find($blobId) ?? throw new ProcessingException('Blob nicht gefunden.');
    }

    private function dokument(Job $job): Document
    {
        if ($job->refType !== self::REF_TYPE || $job->refId === null) {
            throw new ProcessingException('Job ohne zugehöriges Dokument.');
        }

        return $this->documents->find($job->refId) ?? throw new ProcessingException('Dokument nicht gefunden.');
    }

    private static function volltext(BlobService $blobs, Blob $blob, Vault $vault): string
    {
        $inhalt = '';
        foreach ($blobs->openRead($blob, $vault) as $stueck) {
            $inhalt .= $stueck;
        }

        return $inhalt;
    }

    /**
     * @return list<int>
     */
    private static function intListe(mixed $wert): array
    {
        return is_array($wert) ? array_values(array_map(intval(...), $wert)) : [];
    }
}
