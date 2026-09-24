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
use App\Service\Processing\PdfAusBildern;
use App\Service\Processing\PngZuJpeg;
use App\Service\Processing\ProcessingException;
use App\Service\Storage\BlobService;
use App\Service\Upload\MagicBytes;

/**
 * The `pdf_erzeugen` job (issue #26/M4-4, docs/spec/03-erfassung-und-ki.md
 * section 3): builds the PDF working copy of a document from its original
 * pages, once a signed-in session can decrypt them. The originals are never
 * touched (decision E-10) - this only ever adds `document.pdf_blob_id`.
 *
 * Three short, idempotent steps, none of which does more work than fits a
 * shared-hosting request (CLAUDE.md section 1):
 *
 *   '' (pruefen) - decides what this document even needs. Only images ->
 *      go on to `seite`. Any PDF among the originals -> queues the
 *      `render_pages` browser job (issue #30/M4-8, App\Service\Document\
 *      PdfRasterung) once, since none of them has page images yet; exactly
 *      one PDF and nothing else -> that upload already IS the working copy
 *      too, done immediately. Anything mixed or more than one PDF ->
 *      App\Service\Job\JobSchrittErgebnis::uebersprungen(), the originals
 *      stay the only thing there is (docs/spec/03-erfassung-und-ki.md
 *      section 3: "Hochgeladene PDFs werden nicht umgebaut").
 *   'seite' - turns every PNG page into a JPEG (App\Service\Processing\
 *      PngZuJpeg), at most one conversion per call; JPEG pages need nothing
 *      and are free to collect in the same call.
 *   'pdf' - streams App\Service\Processing\PdfAusBildern::erzeuge() straight
 *      into BlobService::store(), attaches it to the document, and drops the
 *      converted JPEGs `seite` produced (never an original).
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

        $bilder = array_filter(
            $typen,
            static fn (string $typ): bool => $typ === MagicBytes::JPEG || $typ === MagicBytes::PNG,
        );
        if (count($bilder) === count($typen)) {
            return JobSchrittErgebnis::weiter('seite', ['arbeit' => [], 'zwischen' => []]);
        }

        $pdfs = array_filter($typen, static fn (string $typ): bool => $typ === MagicBytes::PDF);
        if ($pdfs === []) {
            // Neither all-image nor any PDF: an unexpected mix mimeType()
            // would already have rejected, so this cannot be reached.
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
     * One PNG conversion per call at most; a run of plain JPEGs is free and
     * collected in the same call (class docblock).
     */
    private function seite(Job $job, Vault $vault): JobSchrittErgebnis
    {
        $document = $this->dokument($job);
        $arbeit = self::intListe($job->state['arbeit'] ?? []);
        $zwischen = self::intListe($job->state['zwischen'] ?? []);

        for ($i = count($arbeit); $i < count($document->originalBlobIds); $i++) {
            $blobId = $document->originalBlobIds[$i];
            $typ = $this->mimeType($blobId, $vault);

            if ($typ === MagicBytes::JPEG) {
                $arbeit[] = $blobId;

                continue;
            }

            if ($typ !== MagicBytes::PNG) {
                throw new ProcessingException('Unerwarteter Bildtyp in der PDF-Erzeugung.');
            }

            $original = $this->findBlob($blobId);
            $jpeg = PngZuJpeg::konvertiere(self::volltext($this->blobService, $original, $vault));
            $konvertiert = $this->blobService->storeString($jpeg, new BlobMeta(MagicBytes::JPEG), $vault);
            $arbeit[] = $konvertiert->id;
            $zwischen[] = $konvertiert->id;

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
            if (in_array($blobId, $document->originalBlobIds, true)) {
                continue; // never the original (decision E-10)
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
