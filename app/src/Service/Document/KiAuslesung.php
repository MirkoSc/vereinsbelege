<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Domain\ArtifactKind;
use App\Domain\Category;
use App\Domain\Document;
use App\Domain\DocumentArtifact;
use App\Domain\DocumentStatus;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\OcrStatus;
use App\Domain\Permission;
use App\Domain\Zugriffsbereich;
use App\Repository\AiCallLogRepository;
use App\Repository\AiProviderRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Inbox\EinreichungsDaten;
use App\Service\Job\ExternerAufruf;
use App\Service\Job\ExternerJobHandler;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\Ki\KiAbfrage;
use App\Service\Ki\KiAnbieter;
use App\Service\Ki\KiErgebnis;
use App\Service\Ki\KiVersuch;
use App\Service\MasterData\SupplierAufloesung;
use App\Service\Processing\KiAuslese\AusleseEingabe;
use App\Service\Processing\KiAuslese\AusleseVorschlag;
use App\Service\Processing\KiAuslese\Auslesung;
use App\Service\Processing\PngZuJpeg;
use App\Service\Processing\ProcessingException;
use App\Service\Storage\BlobService;
use App\Service\Upload\MagicBytes;

/**
 * The `ai_extract` job (issue #47/M7-5, docs/spec/03-erfassung-und-ki.md
 * sections 5 and 6): a document that was accepted (`bereit_zur_auswertung`)
 * is read by the AI with prompt `extract-v1`; the result is a suggestion the
 * review page fills in and a person confirms (CLAUDE.md section 6).
 *
 * The extraction logic itself is framework-free and shared with the worker
 * to come (CLAUDE.md section 6a): App\Service\Processing\KiAuslese\
 * Auslesung builds the request and checks the answer, App\Service\Ki\
 * KiAbfrage asks and validates. This class is the glue around it -
 * repositories, vault, blobs.
 *
 *   '' - checks whether there is anything to do: the document still waits
 *      for the AI, the default profile is usable (else `uebersprungen`, the
 *      receipt is captured by hand), the text layer and page images are
 *      done (else it waits, JobSchrittErgebnis::warten()), and there is
 *      something the AI can read (else `ki_fehler` with the reason).
 *   'fragen', 'fallback', 'reparieren' - one AI call each, never two in one
 *      request (KiAbfrage, 06-betrieb.md section 4): the first call with the
 *      default profile, the one try with the fallback profile when the
 *      default one was out of reach, and the one repair call of the profile
 *      that answered. Each runs as an App\Service\Job\ExternerJobHandler
 *      step: the request is built with the database (aufruf()), the call
 *      runs without it, the attempt is stored over a fresh connection
 *      (nachAufruf()). At most three calls.
 *
 * Every attempt is a `document_artifact` of kind `extraction`, `seq` = its
 * place in the run, under its own data key: the attempt with the raw answer,
 * model and prompt version (KiVersuch::toArray()), the message for the
 * receipt, and - for a valid answer - the suggestion after the server's
 * checks. That is also where the next step finds the previous attempt; the
 * job's own `state` holds only the counter `n`. The last step drops older
 * runs and moves the document to `ausgewertet` or `ki_fehler` - only from
 * `bereit_zur_auswertung`: a receipt a person has already taken up keeps its
 * status, a fixed one (01 section 7) is never touched. A document that
 * reached `ausgewertet` gets the supplier resolution queued next
 * (`resolve_supplier`, issue #48/M7-6, App\Service\Document\
 * LieferantenAufloesung); the prompt carries the blind-index preselection of
 * known suppliers (App\Service\MasterData\SupplierAufloesung::vorauswahl()).
 *
 * Queued by accepting a document in the inbox (App\Service\Inbox\
 * Posteingang), by the archive import, by "KI erneut versuchen" in the
 * review page (all through einreihen()) and by migrations/031.
 */
final readonly class KiAuslesung implements ExternerJobHandler
{
    public const string JOB_TYP = 'ai_extract';

    /** How long the job waits for the text layer or the page images before it looks again. */
    public const int WARTEN_SEKUNDEN = 30;

    /**
     * Page images per request at most, in bytes - beyond that the remaining
     * pages are counted, not sent (Auslesung::auswerten() says so).
     */
    public const int BILDER_MAX_BYTES = 20 * 1024 * 1024;

    private const string REF_TYPE = 'document';

    private const string SCHRITT_FRAGEN = 'fragen';

    private const string SCHRITT_FALLBACK = 'fallback';

    private const string SCHRITT_REPARIEREN = 'reparieren';

    private const string TABELLE = 'document_artifact';

    private const string SPALTE = 'data_enc';

    public function __construct(
        private DocumentRepository $documents,
        private DocumentArtifactRepository $artifacts,
        private JobRepository $jobs,
        private BlobService $blobs,
        private Texterkennung $texterkennung,
        private CategoryRepository $kategorien,
        private CostCenterRepository $kostenstellen,
        private AiProviderRepository $anbieter,
        private KiAbfrage $abfrage,
        private Auslesung $auslesung,
        private AiCallLogRepository $protokoll,
        private SupplierAufloesung $lieferanten,
    ) {
    }

    public function typ(): string
    {
        return self::JOB_TYP;
    }

    /** `document.edit`, like the other document jobs: who decides on receipts drives their jobs. */
    public function recht(): Permission
    {
        return Permission::DocumentEdit;
    }

    public function schritt(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        return match ($job->step) {
            '' => $this->pruefen($job, $vault, $now),
            self::SCHRITT_FRAGEN, self::SCHRITT_FALLBACK, self::SCHRITT_REPARIEREN => $this->ohneAufruf($job, $vault, $now),
            default => throw new ProcessingException(sprintf('Unbekannter Schritt "%s".', $job->step)),
        };
    }

    public function aufruf(Job $job, Vault $vault, \DateTimeImmutable $now): ?ExternerAufruf
    {
        if (!in_array($job->step, [self::SCHRITT_FRAGEN, self::SCHRITT_FALLBACK, self::SCHRITT_REPARIEREN], true)) {
            return null;
        }
        $document = $this->dokument($job);
        $n = self::nummer($job);
        // Stored already (a request that died after the call): schritt()
        // goes on from the stored attempt instead of asking again. Taken up
        // by a person meanwhile: no further call, schritt() ends the run.
        if ($this->gespeicherterVersuch($document, $job->id, $n, $vault) !== null || $document->status !== DocumentStatus::BereitZurAuswertung) {
            return null;
        }

        $vorher = $n === 0 ? null : $this->gespeicherterVersuch($document, $job->id, $n - 1, $vault);
        [$profil, $apiKey] = match ($job->step) {
            self::SCHRITT_FRAGEN => $this->standardProfil(),
            self::SCHRITT_FALLBACK => $this->fallbackProfil($vorher),
            default => $this->profilMitKey($vorher?->aufruf->anbieterId),
        } ?? [null, null];
        if ($profil === null || $apiKey === null || ($n > 0 && $vorher === null)) {
            // The profile went away between two steps (deleted, deactivated,
            // key removed): schritt() ends the run with what is there.
            return null;
        }

        $eingabe = $this->eingabe($document, $vault, $profil, mitBildern: true);
        $anfrage = $this->auslesung->anfrage($eingabe, $profil);
        $version = Auslesung::PROMPT_VERSION;
        $abfrage = $this->abfrage;
        $schritt = $job->step;

        return new ExternerAufruf(
            static function () use ($abfrage, $schritt, $profil, $apiKey, $anfrage, $vorher, $version, $eingabe): array {
                $versuch = match (true) {
                    $vorher === null => $abfrage->fragen($profil, $apiKey, $anfrage, $version),
                    $schritt === self::SCHRITT_FALLBACK => $abfrage->fallbackFragen($profil, $apiKey, $anfrage, $vorher, $version),
                    default => $abfrage->reparieren($profil, $apiKey, $anfrage, $vorher, $version),
                };

                return [$versuch, $eingabe];
            },
            $profil->timeoutS,
        );
    }

    public function nachAufruf(Job $job, Vault $vault, mixed $ergebnis, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        if (!is_array($ergebnis) || !($ergebnis[0] ?? null) instanceof KiVersuch || !($ergebnis[1] ?? null) instanceof AusleseEingabe) {
            throw new \LogicException('An ai_extract call returns its attempt and the input it was made from.');
        }
        [$versuch, $eingabe] = $ergebnis;
        $document = $this->dokument($job);
        $n = self::nummer($job);

        if ($this->gespeicherterVersuch($document, $job->id, $n, $vault) === null) {
            $vorschlag = $versuch->gueltig() && $versuch->daten !== null ? $this->auslesung->auswerten($versuch->daten, $eingabe) : null;
            $kette = [...$this->gespeicherteVersuche($document, $job->id, $n, $vault), $versuch];
            $this->speichern($document, $job->id, $n, $vault, $now, [
                'versuch' => $versuch->toArray(),
                'meldung' => new KiErgebnis($kette)->meldung(),
                'extraktion' => $vorschlag?->extraktion,
                'hinweise' => $vorschlag->hinweise ?? [],
            ]);
            // After the attempt is stored: a request that dies in between
            // asks again from the stored attempt and logs nothing twice.
            $this->protokoll->insert($versuch->aufruf, $now);
        }

        return $this->weiter($document, $job, $n, $vault, $now);
    }

    /**
     * The suggestion of the newest run - the last attempt it stored - or
     * null when the AI has not read this document (yet).
     */
    public function auszug(Document $document, Vault $vault): ?KiAuszug
    {
        $artefakte = $this->artifacts->fuerDokument($document->id, ArtifactKind::Extraction);
        $lauf = max([0, ...array_map(static fn(DocumentArtifact $a): int => $a->jobId ?? 0, $artefakte)]);
        $letztes = null;
        foreach ($artefakte as $artefakt) {
            if (($artefakt->jobId ?? 0) === $lauf && ($letztes === null || $artefakt->seq > $letztes->seq)) {
                $letztes = $artefakt;
            }
        }
        $daten = $letztes === null ? null : $this->daten($letztes, $vault);
        if ($daten === null || !is_string($daten['meldung'] ?? null)) {
            return null;
        }

        $extraktion = is_array($daten['extraktion'] ?? null) && !array_is_list($daten['extraktion']) ? $daten['extraktion'] : null;
        $aufruf = is_array($daten['versuch']['aufruf'] ?? null) ? $daten['versuch']['aufruf'] : [];

        return new KiAuszug(
            $daten['meldung'],
            $extraktion,
            array_values(array_filter(is_array($daten['hinweise'] ?? null) ? $daten['hinweise'] : [], is_string(...))),
            $extraktion === null ? [] : AusleseVorschlag::unsicher($extraktion),
            is_string($aufruf['anbieter_name'] ?? null) ? $aufruf['anbieter_name'] : '',
            is_string($aufruf['modell'] ?? null) ? $aufruf['modell'] : '',
            ($aufruf['fallback'] ?? false) === true,
        );
    }

    /**
     * Queues the job for $documentId unless one is still to do - returns
     * whether it did. Static, so the places that queue it (accepting in the
     * inbox, the archive import, "KI erneut versuchen") need only the job
     * repository, not everything the job itself needs.
     */
    public static function einreihen(JobRepository $jobs, int $documentId, \DateTimeImmutable $now): bool
    {
        if ($jobs->offenFuer([self::JOB_TYP], self::REF_TYPE, $documentId)) {
            return false;
        }
        $jobs->enqueue(self::JOB_TYP, JobExecutor::Session, self::REF_TYPE, $documentId, now: $now);

        return true;
    }

    /** einreihen() with this job's repository - for the review page's "KI erneut versuchen". */
    public function anstossen(int $documentId, \DateTimeImmutable $now): bool
    {
        return self::einreihen($this->jobs, $documentId, $now);
    }

    /** Whether a run for $documentId is queued or running right now. */
    public function laeuft(int $documentId): bool
    {
        return $this->jobs->offenFuer([self::JOB_TYP], self::REF_TYPE, $documentId);
    }

    /**
     * Whether the AI can be asked at all: the default profile is active and
     * has a key that opens (docs/spec/03-erfassung-und-ki.md section 6:
     * without a key the AI functions are off).
     */
    public function eingerichtet(): bool
    {
        return $this->standardProfil() !== null;
    }

    private function pruefen(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        $document = $this->dokument($job);
        if ($document->status !== DocumentStatus::BereitZurAuswertung) {
            return JobSchrittErgebnis::uebersprungen();
        }
        $standard = $this->standardProfil();
        if ($standard === null) {
            return JobSchrittErgebnis::uebersprungen();
        }
        if ($this->jobs->offenFuer([Texterkennung::JOB_TYP, PdfRasterung::JOB_TYP], self::REF_TYPE, $document->id)) {
            return JobSchrittErgebnis::warten('', [], self::WARTEN_SEKUNDEN);
        }

        $grund = $this->auslesung->nichtLesbar($this->eingabe($document, $vault, $standard[0], mitBildern: false), $standard[0]);
        if ($grund !== null) {
            if ($this->artifacts->finde($document->id, ArtifactKind::Extraction, $job->id, 0)?->dataEnc === null) {
                $this->speichern($document, $job->id, 0, $vault, $now, ['versuch' => null, 'meldung' => $grund, 'extraktion' => null, 'hinweise' => []]);
            }

            return $this->abschliessen($document, $job, false, $now);
        }

        return JobSchrittErgebnis::weiter(self::SCHRITT_FRAGEN, ['n' => 0]);
    }

    /**
     * An AI step whose aufruf() asked for nothing: either its attempt is
     * stored already (go on from there), or the profile it needed went away
     * in between, or a person took the receipt up - then the run ends with
     * what it has.
     */
    private function ohneAufruf(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        $document = $this->dokument($job);
        $n = self::nummer($job);
        if ($this->gespeicherterVersuch($document, $job->id, $n, $vault) !== null) {
            return $this->weiter($document, $job, $n, $vault, $now);
        }
        if ($n === 0) {
            return JobSchrittErgebnis::uebersprungen();
        }

        return $this->abschliessen($document, $job, false, $now);
    }

    /** What follows attempt $n: done, the repair call, the fallback, or "KI fehlgeschlagen". */
    private function weiter(Document $document, Job $job, int $n, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        $versuch = $this->gespeicherterVersuch($document, $job->id, $n, $vault) ?? throw new ProcessingException('Versuch nicht gespeichert.');
        if ($versuch->gueltig()) {
            return $this->abschliessen($document, $job, true, $now);
        }
        if ($versuch->reparaturMoeglich()) {
            return JobSchrittErgebnis::weiter(self::SCHRITT_REPARIEREN, ['n' => $n + 1]);
        }
        if ($versuch->fallbackMoeglich() && $this->fallbackProfil($versuch) !== null) {
            return JobSchrittErgebnis::weiter(self::SCHRITT_FALLBACK, ['n' => $n + 1]);
        }

        return $this->abschliessen($document, $job, false, $now);
    }

    private function abschliessen(Document $document, Job $job, bool $gueltig, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        $this->artifacts->loescheAndereLaeufe($document->id, ArtifactKind::Extraction, $job->id);
        // Only from `bereit_zur_auswertung` (UPDATE ... WHERE status = ?): a
        // receipt someone took up meanwhile keeps its status.
        $gewechselt = $this->documents->wechsleStatus(
            $document->id,
            DocumentStatus::BereitZurAuswertung,
            $gueltig ? DocumentStatus::Ausgewertet : DocumentStatus::KiFehler,
            null,
            null,
            null,
            $now,
        );
        // Next: which supplier the suggestion names (issue #48/M7-6) - also
        // when a repeated step finds the status already moved by the first.
        if ($gueltig && ($gewechselt || $this->documents->find($document->id)?->status === DocumentStatus::Ausgewertet)) {
            LieferantenAufloesung::einreihen($this->jobs, $document->id, $now);
        }

        return JobSchrittErgebnis::fertig();
    }

    /**
     * What the AI gets for $document. The page images only when the text
     * layer does not reach (`ocr_status` other than `fertig`), and loaded
     * only $mitBildern - step '' needs just their number; the same for the
     * preselection of known suppliers.
     */
    private function eingabe(Document $document, Vault $vault, KiAnbieter $profil, bool $mitBildern): AusleseEingabe
    {
        $eRechnung = $this->texterkennung->eRechnung($document, $vault);
        [$bilder, $seiten] = $document->ocrStatus === OcrStatus::Fertig
            ? [[], 0]
            : $this->seitenbilder($document, $vault, $mitBildern ? Auslesung::bilderGrenze($profil) : 0);
        $item = $document->submissionId === null ? null : $this->documents->inboxItem($document->id, Zugriffsbereich::unbeschraenkt());

        $text = $this->texterkennung->textFuerKi($document, $vault);
        $eRechnungDaten = $eRechnung !== null && $eRechnung->gelesen() ? $eRechnung->extraktion : null;
        $freitext = $item === null ? '' : (EinreichungsDaten::entschluesseln($item, $vault)?->freitext ?? '');

        return new AusleseEingabe(
            $text,
            $eRechnungDaten,
            $bilder,
            $seiten,
            $freitext,
            array_values(array_filter($this->kategorien->all(), static fn(Category $k): bool => $k->active)),
            $this->kostenstellen->active(),
            // Only for the request itself - step '' just checks.
            $mitBildern
                ? $this->lieferanten->vorauswahl($vault, [$text ?? '', $freitext], is_array($eRechnungDaten['supplier'] ?? null) ? $eRechnungDaten['supplier'] : [])
                : [],
        );
    }

    /**
     * The page images in page order - per original the scanner's processed
     * version if it has one, else the image itself (a PNG converted to JPEG),
     * and for the PDFs the page images of the newest rendering run - and how
     * many there are. At most $grenze are loaded, within BILDER_MAX_BYTES.
     *
     * @return array{list<string>, int}
     */
    private function seitenbilder(Document $document, Vault $vault, int $grenze): array
    {
        $quellen = [];
        $pdfSeitenGenommen = false;
        foreach ($document->originalBlobIds as $index => $originalId) {
            $mime = $this->mimeType($originalId, $vault);
            if ($mime === MagicBytes::PDF) {
                if (!$pdfSeitenGenommen) {
                    $pdfSeitenGenommen = true;
                    foreach ($this->renderSeiten($document) as $seite) {
                        $quellen[] = [$seite, false];
                    }
                }
                continue;
            }
            if ($mime !== MagicBytes::JPEG && $mime !== MagicBytes::PNG && $mime !== MagicBytes::HEIC) {
                continue;
            }
            $verarbeitet = $document->processedBlobId($index);
            if ($verarbeitet !== null && $this->mimeType($verarbeitet, $vault) === MagicBytes::JPEG) {
                $quellen[] = [$verarbeitet, false];
            } elseif ($mime !== MagicBytes::HEIC) {
                // A HEIC original without a JPEG is no input for the model.
                $quellen[] = [$originalId, $mime === MagicBytes::PNG];
            }
        }

        $bilder = [];
        $bytes = 0;
        foreach ($quellen as [$blobId, $png]) {
            if (count($bilder) >= $grenze) {
                break;
            }
            $blob = $this->blobs->find($blobId) ?? throw new ProcessingException('Seitenbild nicht gefunden.');
            if ($bytes + $blob->size > self::BILDER_MAX_BYTES) {
                break;
            }
            $daten = '';
            foreach ($this->blobs->openRead($blob, $vault) as $stueck) {
                $daten .= $stueck;
            }
            $jpeg = $png ? PngZuJpeg::konvertiere($daten) : $daten;
            $bytes += strlen($jpeg);
            $bilder[] = $jpeg;
        }

        return [$bilder, count($quellen)];
    }

    /**
     * The blob ids of the newest rendering run's page images (`render_pages`,
     * issue #30/M4-8), in page order.
     *
     * @return list<int>
     */
    private function renderSeiten(Document $document): array
    {
        $artefakte = $this->artifacts->fuerDokument($document->id, ArtifactKind::PageImage);
        $lauf = max([0, ...array_map(static fn(DocumentArtifact $a): int => $a->jobId ?? 0, $artefakte)]);

        return array_values(array_map(
            static fn(DocumentArtifact $a): int => (int) $a->blobId,
            array_filter($artefakte, static fn(DocumentArtifact $a): bool => ($a->jobId ?? 0) === $lauf && $a->blobId !== null),
        ));
    }

    /**
     * The default profile with its key, or null when the AI is off.
     *
     * @return ?array{KiAnbieter, string}
     */
    private function standardProfil(): ?array
    {
        $profil = $this->anbieter->standard();

        return $profil === null || !$profil->nutzbar() ? null : $this->profilMitKey($profil->id);
    }

    /**
     * The fallback profile with its key - only for an attempt that may fall
     * back, and only when it is another profile than the one that failed.
     *
     * @return ?array{KiAnbieter, string}
     */
    private function fallbackProfil(?KiVersuch $vorher): ?array
    {
        $fallback = $this->anbieter->fallback();
        if ($vorher === null || !$vorher->fallbackMoeglich() || $fallback === null || $fallback->id === $vorher->aufruf->anbieterId) {
            return null;
        }

        return $this->profilMitKey($fallback->id);
    }

    /**
     * @return ?array{KiAnbieter, string}
     */
    private function profilMitKey(?int $id): ?array
    {
        $profil = $id === null ? null : $this->anbieter->find($id);
        $apiKey = $profil === null || !$profil->nutzbar() ? null : $this->anbieter->apiKey($profil->id);

        return $profil === null || $apiKey === null ? null : [$profil, $apiKey];
    }

    /** Attempt $n of run $jobId as stored, or null when there is none (yet). */
    private function gespeicherterVersuch(Document $document, int $jobId, int $n, Vault $vault): ?KiVersuch
    {
        $artefakt = $this->artifacts->finde($document->id, ArtifactKind::Extraction, $jobId, $n);
        $daten = $artefakt === null ? null : $this->daten($artefakt, $vault);

        return is_array($daten['versuch'] ?? null) ? KiVersuch::fromArray($daten['versuch']) : null;
    }

    /**
     * Attempts 0 to $bis - 1 of run $jobId - for the message over the whole
     * chain (KiErgebnis::meldung()).
     *
     * @return list<KiVersuch>
     */
    private function gespeicherteVersuche(Document $document, int $jobId, int $bis, Vault $vault): array
    {
        $versuche = [];
        for ($i = 0; $i < $bis; $i++) {
            $versuch = $this->gespeicherterVersuch($document, $jobId, $i, $vault);
            if ($versuch !== null) {
                $versuche[] = $versuch;
            }
        }

        return $versuche;
    }

    /**
     * Stores $inhalt as attempt $n of run $jobId under the artifact's own
     * data key - in the row a cut-off attempt already inserted, if there is
     * one (the AAD names the row id, so the row comes first).
     *
     * @param array<string, mixed> $inhalt
     */
    private function speichern(Document $document, int $jobId, int $n, Vault $vault, \DateTimeImmutable $now, array $inhalt): void
    {
        $vorhanden = $this->artifacts->finde($document->id, ArtifactKind::Extraction, $jobId, $n);
        $schluessel = $vorhanden === null ? DataKey::generate() : $vault->openDataKey($vorhanden->dekSealed);
        $id = $vorhanden?->id ?? $this->artifacts->insert(
            $document->id,
            ArtifactKind::Extraction,
            $n,
            null,
            $vault->sealDataKey($schluessel),
            null,
            JobExecutor::Session,
            $jobId,
            $now,
        );
        $json = json_encode($inhalt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->artifacts->setzeDaten($id, FieldCipher::encrypt($schluessel, $json, new FieldContext(self::TABELLE, $id, self::SPALTE)));
    }

    /**
     * The decrypted JSON of an artifact, or null while it has none.
     *
     * @return array<mixed>|null
     */
    private function daten(DocumentArtifact $artefakt, Vault $vault): ?array
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

        return is_array($daten) ? $daten : null;
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

    /** The attempt counter in `job.state` - the only thing the job itself keeps. */
    private static function nummer(Job $job): int
    {
        $n = $job->state['n'] ?? null;
        if (!is_int($n) || $n < 0 || $n > 2) {
            throw new ProcessingException('Zwischenstand passt nicht zum Job.');
        }

        return $n;
    }
}
