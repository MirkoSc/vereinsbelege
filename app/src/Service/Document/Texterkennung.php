<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Domain\ArtifactKind;
use App\Domain\Document;
use App\Domain\DocumentArtifact;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\OcrStatus;
use App\Domain\Permission;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Job\JobHandler;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\Processing\Pdf\PdfTextlayer;
use App\Service\Processing\ProcessingException;
use App\Service\Processing\Textlayer;
use App\Service\Storage\BlobService;
use App\Service\Upload\MagicBytes;

/**
 * The `extract_text` job (issue #45/M7-3, docs/spec/03-erfassung-und-ki.md
 * sections 3 and 5): reads the text layer of every PDF among a document's
 * originals, once a signed-in session can decrypt them, and decides whether
 * that text is enough for the AI - if it is, the AI reads the text and the
 * page images are not evaluated (textFuerKi(); rendering still runs, for the
 * previews).
 *
 *   '' (pruefen) - finds the PDF originals (by their stored MIME type, as
 *      PdfErzeugung does). None -> `ocr_status` `uebersprungen`, the job
 *      App\Service\Job\JobSchrittErgebnis::uebersprungen().
 *   'quelle' - one PDF per call (CLAUDE.md section 1: an original may be
 *      32 MB): App\Service\Processing\Pdf\PdfTextlayer, stored as a
 *      `document_artifact` of kind `text`, `seq` = the PDF's place among
 *      the PDF originals. The last call drops older runs' text artifacts
 *      and sets `ocr_status`: `fertig` when the text alone is enough (every
 *      original is a PDF and each has a usable text layer,
 *      App\Service\Processing\Textlayer::brauchbar()), else `uebersprungen`
 *      - the page images are needed.
 *
 * `job.state` holds blob ids and one usable/not usable flag per PDF -
 * nothing of the text (06-betrieb.md section 4). The text itself is only in
 * the artifact's `data_enc`, under the artifact's own data key (02, Stand
 * M4-8: so the optional worker can write it the same way one day).
 *
 * Queued next to `pdf_erzeugen` by every new document
 * (App\Service\Submission\SubmissionService, InterneErfassung), and by
 * migrations/026 for every open document that existed before.
 */
final readonly class Texterkennung implements JobHandler
{
    public const string JOB_TYP = 'extract_text';

    private const string REF_TYPE = 'document';

    private const string SCHRITT_QUELLE = 'quelle';

    private const string TABELLE = 'document_artifact';

    private const string SPALTE = 'data_enc';

    public function __construct(
        private DocumentRepository $documents,
        private DocumentArtifactRepository $artifacts,
        private BlobService $blobs,
    ) {
    }

    public function typ(): string
    {
        return self::JOB_TYP;
    }

    /** `document.edit`, like `pdf_erzeugen` and `detect_duplicate`: who decides on receipts drives their jobs. */
    public function recht(): Permission
    {
        return Permission::DocumentEdit;
    }

    public function schritt(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        return match ($job->step) {
            '' => $this->pruefen($job, $vault),
            self::SCHRITT_QUELLE => $this->quelle($job, $vault, $now),
            default => throw new ProcessingException(sprintf('Unbekannter Schritt "%s".', $job->step)),
        };
    }

    /**
     * The text the AI reads instead of the page images - or null, when the
     * page images are needed (no text layer, an unusable one, or pages that
     * are images to begin with). Pages are separated by
     * Textlayer::SEITENWECHSEL, one PDF after the other in the order of the
     * originals. The decision itself is the job's (`ocr_status` `fertig`);
     * this only reads what it stored.
     */
    public function textFuerKi(Document $document, Vault $vault): ?string
    {
        if ($document->ocrStatus !== OcrStatus::Fertig) {
            return null;
        }

        $artefakte = $this->artifacts->fuerDokument($document->id, ArtifactKind::Text);
        $lauf = max(array_map(static fn (DocumentArtifact $a): int => $a->jobId ?? 0, $artefakte) ?: [0]);
        $texte = [];
        foreach ($artefakte as $artefakt) {
            if (($artefakt->jobId ?? 0) !== $lauf) {
                continue;
            }
            $inhalt = $this->inhalt($artefakt, $vault);
            if ($inhalt === null || !$inhalt['brauchbar']) {
                return null;
            }
            $texte[] = implode(Textlayer::SEITENWECHSEL, $inhalt['seiten']);
        }

        return $texte === [] ? null : implode(Textlayer::SEITENWECHSEL, $texte);
    }

    private function pruefen(Job $job, Vault $vault): JobSchrittErgebnis
    {
        $document = $this->dokument($job);
        if ($document->ocrStatus === OcrStatus::Fertig || $document->ocrStatus === OcrStatus::Uebersprungen) {
            return JobSchrittErgebnis::fertig();
        }

        $quellen = [];
        foreach ($document->originalBlobIds as $blobId) {
            if ($this->mimeType($blobId, $vault) === MagicBytes::PDF) {
                $quellen[] = $blobId;
            }
        }

        if ($quellen === []) {
            $this->documents->setzeOcrStatus($document->id, OcrStatus::Uebersprungen);

            return JobSchrittErgebnis::uebersprungen();
        }

        return JobSchrittErgebnis::weiter(self::SCHRITT_QUELLE, ['quellen' => $quellen, 'brauchbar' => []]);
    }

    private function quelle(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        $document = $this->dokument($job);
        $quellen = self::intListe($job->state['quellen'] ?? null);
        $brauchbar = self::boolListe($job->state['brauchbar'] ?? null);
        $seq = count($brauchbar);
        if ($quellen === [] || $seq >= count($quellen)) {
            throw new ProcessingException('Zwischenstand passt nicht zum Dokument.');
        }

        $brauchbar[] = $this->lesen($document, $quellen[$seq], $seq, $job->id, $vault, $now);

        if (count($brauchbar) < count($quellen)) {
            return JobSchrittErgebnis::weiter(self::SCHRITT_QUELLE, ['quellen' => $quellen, 'brauchbar' => $brauchbar]);
        }

        $this->artifacts->loescheAndereLaeufe($document->id, ArtifactKind::Text, $job->id);
        $nurPdfs = count($quellen) === count($document->originalBlobIds);
        $this->documents->setzeOcrStatus(
            $document->id,
            $nurPdfs && !in_array(false, $brauchbar, true) ? OcrStatus::Fertig : OcrStatus::Uebersprungen,
        );

        return JobSchrittErgebnis::fertig();
    }

    /**
     * Reads one PDF and stores its text layer - unless a cut-off attempt of
     * this very step stored it already, then that is what counts. Returns
     * whether the text layer is usable.
     */
    private function lesen(Document $document, int $blobId, int $seq, int $jobId, Vault $vault, \DateTimeImmutable $now): bool
    {
        $vorhanden = $this->artifacts->finde($document->id, ArtifactKind::Text, $jobId, $seq);
        if ($vorhanden !== null && $vorhanden->dataEnc !== null) {
            $inhalt = $this->inhalt($vorhanden, $vault) ?? throw new ProcessingException('Textlayer nicht lesbar.');

            return $inhalt['brauchbar'];
        }

        $blob = $this->blobs->find($blobId) ?? throw new ProcessingException('Originaldatei nicht gefunden.');
        $pdf = '';
        foreach ($this->blobs->openRead($blob, $vault) as $stueck) {
            $pdf .= $stueck;
        }
        $textlayer = PdfTextlayer::lesen($pdf);
        unset($pdf);

        $schluessel = $vorhanden === null ? DataKey::generate() : $vault->openDataKey($vorhanden->dekSealed);
        $id = $vorhanden?->id ?? $this->artifacts->insert(
            $document->id,
            ArtifactKind::Text,
            $seq,
            null,
            $vault->sealDataKey($schluessel),
            null,
            JobExecutor::Session,
            $jobId,
            $now,
        );

        $json = json_encode(
            ['befund' => $textlayer->befund->value, 'brauchbar' => $textlayer->brauchbar(), 'seiten' => $textlayer->seiten],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $this->artifacts->setzeDaten($id, FieldCipher::encrypt($schluessel, $json, new FieldContext(self::TABELLE, $id, self::SPALTE)));

        return $textlayer->brauchbar();
    }

    /**
     * @return array{brauchbar: bool, seiten: list<string>}|null
     */
    private function inhalt(DocumentArtifact $artefakt, Vault $vault): ?array
    {
        if ($artefakt->dataEnc === null) {
            return null;
        }
        $json = FieldCipher::decrypt(
            $vault->openDataKey($artefakt->dekSealed),
            $artefakt->dataEnc,
            new FieldContext(self::TABELLE, $artefakt->id, self::SPALTE),
        );
        $daten = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($daten) || !is_bool($daten['brauchbar'] ?? null) || !is_array($daten['seiten'] ?? null)) {
            return null;
        }

        return [
            'brauchbar' => $daten['brauchbar'],
            'seiten' => array_values(array_map(strval(...), array_filter($daten['seiten'], is_string(...)))),
        ];
    }

    private function mimeType(int $blobId, Vault $vault): string
    {
        $blob = $this->blobs->find($blobId) ?? throw new ProcessingException('Blob nicht gefunden.');

        return $this->blobs->meta($blob, $vault)?->mimeType ?? throw new ProcessingException('Blob ohne Metadaten.');
    }

    private function dokument(Job $job): Document
    {
        if ($job->refType !== self::REF_TYPE || $job->refId === null) {
            throw new ProcessingException('Job ohne zugehöriges Dokument.');
        }

        return $this->documents->find($job->refId) ?? throw new ProcessingException('Dokument nicht gefunden.');
    }

    /**
     * @return list<int>
     */
    private static function intListe(mixed $wert): array
    {
        return is_array($wert) ? array_values(array_map(intval(...), $wert)) : [];
    }

    /**
     * @return list<bool>
     */
    private static function boolListe(mixed $wert): array
    {
        if (!is_array($wert)) {
            throw new ProcessingException('Zwischenstand fehlt.');
        }

        return array_values(array_map(static fn (mixed $b): bool => $b === true, $wert));
    }
}
