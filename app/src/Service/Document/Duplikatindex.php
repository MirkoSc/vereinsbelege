<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Domain\Document;
use App\Domain\Job;
use App\Domain\Permission;
use App\Repository\DocumentRepository;
use App\Service\Crypto\BlindIndex;
use App\Service\Crypto\Vault;
use App\Service\Job\JobHandler;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\Processing\Inhaltsindex;
use App\Service\Processing\ProcessingException;
use App\Service\Storage\BlobService;

/**
 * The `detect_duplicate` job (issue #40/M6-6, docs/spec/03-erfassung-und-ki.md
 * section 5): computes `document.content_bi` from the original files, once a
 * signed-in session can decrypt them. The suspicion itself is not this job's
 * output - App\Repository\DocumentDuplicateRepository derives it on every
 * read from this index and from supplier + invoice number.
 *
 * One original per call (CLAUDE.md section 1: no request runs long - an
 * internal capture may carry up to 50 pages of up to 32 MB each). Until the
 * last one, `job.state` holds the file indexes done so far: blind indexes
 * like every `*_bi` column (App\Service\Processing\Inhaltsindex::datei()),
 * not a plain hash and nothing readable. The last call writes the document's
 * index - only into a document without one (DocumentRepository::
 * setzeContentBi()), so a repeated run changes nothing.
 *
 * Queued next to `pdf_erzeugen` by every new document
 * (App\Service\Submission\SubmissionService, InterneErfassung), and by
 * migrations/023 for every document that existed before.
 */
final readonly class Duplikatindex implements JobHandler
{
    public const string JOB_TYP = 'detect_duplicate';

    private const string REF_TYPE = 'document';

    private const string SCHRITT_DATEI = 'datei';

    public function __construct(
        private DocumentRepository $documents,
        private BlobService $blobs,
    ) {
    }

    public function typ(): string
    {
        return self::JOB_TYP;
    }

    /** `document.edit`, like `pdf_erzeugen`: who decides on receipts drives their jobs. */
    public function recht(): Permission
    {
        return Permission::DocumentEdit;
    }

    public function schritt(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        if ($job->step !== '' && $job->step !== self::SCHRITT_DATEI) {
            throw new ProcessingException(sprintf('Unbekannter Schritt "%s".', $job->step));
        }

        $document = $this->dokument($job);
        if ($document->contentBi !== null) {
            return JobSchrittErgebnis::fertig();
        }
        $originale = $document->originalBlobIds;
        if ($originale === []) {
            return JobSchrittErgebnis::uebersprungen();
        }

        $fertig = $job->step === '' ? [] : self::dateien($job->state['dateien'] ?? null);
        if (count($fertig) >= count($originale)) {
            throw new ProcessingException('Zwischenstand passt nicht zum Dokument.');
        }

        $blob = $this->blobs->find($originale[count($fertig)]) ?? throw new ProcessingException('Originaldatei nicht gefunden.');
        $index = $vault->blindIndex();
        $fertig[] = Inhaltsindex::datei($index, $this->blobs->openRead($blob, $vault));

        if (count($fertig) < count($originale)) {
            return JobSchrittErgebnis::weiter(self::SCHRITT_DATEI, ['dateien' => array_map(bin2hex(...), $fertig)]);
        }

        $this->documents->setzeContentBi($document->id, Inhaltsindex::beleg($index, $fertig));

        return JobSchrittErgebnis::fertig();
    }

    private function dokument(Job $job): Document
    {
        if ($job->refType !== self::REF_TYPE || $job->refId === null) {
            throw new ProcessingException('Job ohne zugehöriges Dokument.');
        }

        return $this->documents->find($job->refId) ?? throw new ProcessingException('Dokument nicht gefunden.');
    }

    /**
     * @return list<string> raw file indexes from the job state
     */
    private static function dateien(mixed $wert): array
    {
        if (!is_array($wert) || !array_is_list($wert)) {
            throw new ProcessingException('Zwischenstand fehlt.');
        }

        $dateien = [];
        foreach ($wert as $hex) {
            $roh = is_string($hex) && preg_match('/^[0-9a-f]+$/', $hex) === 1 ? hex2bin($hex) : false;
            if ($roh === false || strlen($roh) !== BlindIndex::BYTES) {
                throw new ProcessingException('Zwischenstand ungültig.');
            }
            $dateien[] = $roh;
        }

        return $dateien;
    }
}
