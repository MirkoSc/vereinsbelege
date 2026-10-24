<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Domain\ArtifactKind;
use App\Domain\AuditAction;
use App\Domain\Document;
use App\Domain\DocumentArtifact;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\Permission;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Service\Archiv\ArchivVorgabe;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Job\JobHandler;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\MasterData\Aufloesung;
use App\Service\MasterData\AufloesungsArt;
use App\Service\MasterData\SupplierAufloesung;
use App\Service\MasterData\SupplierRuleViolation;
use App\Service\Processing\ProcessingException;

/**
 * The `resolve_supplier` job (issue #48/M7-6, docs/spec/
 * 03-erfassung-und-ki.md sections 5 and 7): once the AI has read a receipt
 * (`ai_extract` → `ausgewertet`), the supplier it names is resolved against
 * the master data (App\Service\MasterData\SupplierAufloesung) - and created,
 * with `needs_review`, when nothing matches. The review page then shows the
 * supplier chosen and how it was found; a person confirms it by saving.
 *
 * Always a session job (07-worker.md): the blind indexes need the vault.
 * One step:
 *
 * - Skipped for a document no longer `ausgewertet` (a person took it up,
 *   it was fixed - 01 section 7) and for one the archive import gave a
 *   supplier already (its path was sorted by a person, App\Service\Archiv\
 *   ArchivVorgabe wins on the review page anyway).
 * - What is resolved: the newest AI suggestion, with the seller of a read
 *   e-invoice laid over it - the same order as the review page.
 * - The result (App\Service\MasterData\Aufloesung: step, kind of value,
 *   supplier, suggestions - ids only) is a `document_artifact` of the kind
 *   `lieferant`, encrypted under its own data key, written in one
 *   transaction with a supplier step 6 creates. Older runs go.
 * - Idempotent: a stored result for this run ends the job without a second
 *   look; a supplier created in a step whose request died afterwards is
 *   found again by its name in step 4.
 * - Two requests creating the same supplier at once: the locks of the
 *   lookups make one of them fail (deadlock, or an id already taken) - it
 *   rolls back and waits a few seconds; the next try finds the new
 *   supplier. After VERSUCHE_MAX tries it resolves without creating.
 *
 * Audit `lieferant.angelegt` for a created supplier, without a user (the
 * job created it, not the person whose browser ran the step): details
 * `herkunft` = `ki`, the document id, the names of the fields filled.
 */
final readonly class LieferantenAufloesung implements JobHandler
{
    public const string JOB_TYP = 'resolve_supplier';

    /** Tries with step 6 creating a supplier before the job resolves without. */
    public const int VERSUCHE_MAX = 2;

    /** Seconds before a try that lost against a parallel one runs again. */
    public const int WARTEN_SEKUNDEN = 5;

    private const string REF_TYPE = 'document';

    private const string TABELLE = 'document_artifact';

    private const string SPALTE = 'data_enc';

    public function __construct(
        private \PDO $pdo,
        private DocumentRepository $documents,
        private DocumentArtifactRepository $artifacts,
        private KiAuslesung $kiAuslesung,
        private Texterkennung $texterkennung,
        private SupplierAufloesung $aufloesung,
        private AuditLog $audit,
    ) {
    }

    public function typ(): string
    {
        return self::JOB_TYP;
    }

    /** `document.edit`, like `ai_extract` whose result it carries on. */
    public function recht(): Permission
    {
        return Permission::DocumentEdit;
    }

    /**
     * Queues the job for $documentId unless one is still to do. Static,
     * like KiAuslesung::einreihen() - `ai_extract` queues it when it has
     * moved a document to `ausgewertet`.
     */
    public static function einreihen(JobRepository $jobs, int $documentId, \DateTimeImmutable $now): bool
    {
        if ($jobs->offenFuer([self::JOB_TYP], self::REF_TYPE, $documentId)) {
            return false;
        }
        $jobs->enqueue(self::JOB_TYP, JobExecutor::Session, self::REF_TYPE, $documentId, now: $now);

        return true;
    }

    public function schritt(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        if ($job->step !== '') {
            throw new ProcessingException(sprintf('Unbekannter Schritt "%s".', $job->step));
        }
        $document = $this->dokument($job);
        if ($this->artifacts->finde($document->id, ArtifactKind::Lieferant, $job->id, 0)?->dataEnc !== null) {
            return JobSchrittErgebnis::fertig();
        }
        if ($document->status !== DocumentStatus::Ausgewertet || $this->archivLieferant($document, $vault)) {
            return JobSchrittErgebnis::uebersprungen();
        }
        $extraktion = $this->extraktion($document, $vault);
        if ($extraktion === null) {
            return JobSchrittErgebnis::uebersprungen();
        }

        $versuch = $job->state['versuch'] ?? 0;
        if (!is_int($versuch) || $versuch < 0 || $versuch > self::VERSUCHE_MAX) {
            throw new ProcessingException('Zwischenstand passt nicht zum Job.');
        }

        $this->pdo->beginTransaction();
        try {
            $ergebnis = $this->aufloesung->aufloesen($vault, $extraktion, $versuch < self::VERSUCHE_MAX, $now);
            $this->speichern($document, $job->id, $vault, $now, $ergebnis);
            $this->artifacts->loescheAndereLaeufe($document->id, ArtifactKind::Lieferant, $job->id);
            $this->pdo->commit();
        } catch (\PDOException | SupplierRuleViolation $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($versuch >= self::VERSUCHE_MAX) {
                throw $e;
            }

            return JobSchrittErgebnis::warten('', ['versuch' => $versuch + 1], self::WARTEN_SEKUNDEN);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        if ($ergebnis->art === AufloesungsArt::Angelegt && $ergebnis->supplierId !== null) {
            $this->audit->record(
                AuditAction::LieferantAngelegt,
                null,
                '',
                $ergebnis->supplierId,
                ['herkunft' => 'ki', 'beleg' => $document->id, 'felder' => $ergebnis->felder],
                $now,
            );
        }

        return JobSchrittErgebnis::fertig();
    }

    /**
     * How the newest run resolved the supplier of $document, or null when
     * none has (yet) - for the review page.
     */
    public function ergebnis(Document $document, Vault $vault): ?Aufloesung
    {
        $neuestes = null;
        foreach ($this->artifacts->fuerDokument($document->id, ArtifactKind::Lieferant) as $artefakt) {
            if ($artefakt->dataEnc !== null && ($neuestes === null || ($artefakt->jobId ?? 0) > ($neuestes->jobId ?? 0))) {
                $neuestes = $artefakt;
            }
        }
        $daten = $neuestes === null ? null : $this->daten($neuestes, $vault);

        return $daten === null || ($daten['schema'] ?? null) !== Aufloesung::SCHEMA ? null : Aufloesung::fromArray($daten);
    }

    /**
     * The supplier to resolve, in the shape of `extract-v1`: the newest AI
     * suggestion, and the seller of a read e-invoice over it - each value
     * the e-invoice states wins, like on the review page. Null without a
     * valid AI suggestion.
     *
     * @return ?array<string, mixed>
     */
    private function extraktion(Document $document, Vault $vault): ?array
    {
        $ki = $this->kiAuslesung->auszug($document, $vault)?->extraktion;
        if ($ki === null) {
            return null;
        }
        $eRechnung = $this->texterkennung->eRechnung($document, $vault);
        $ueber = $eRechnung !== null && $eRechnung->gelesen() ? ($eRechnung->extraktion ?? []) : [];

        $partner = is_array($ki['supplier'] ?? null) ? $ki['supplier'] : [];
        foreach (is_array($ueber['supplier'] ?? null) ? $ueber['supplier'] : [] as $feld => $wert) {
            if (is_string($wert) && trim($wert) !== '') {
                $partner[$feld] = $wert;
            }
        }
        $ki['supplier'] = $partner;
        if (is_string($ueber['direction'] ?? null)) {
            $ki['direction'] = $ueber['direction'];
        }
        if (is_array($ueber['payment'] ?? null) && is_string($ueber['payment']['mandate_reference'] ?? null) && trim($ueber['payment']['mandate_reference']) !== '') {
            $ki['payment'] = [...(is_array($ki['payment'] ?? null) ? $ki['payment'] : []), 'mandate_reference' => $ueber['payment']['mandate_reference']];
        }

        return $ki;
    }

    /** Whether the archive import gave $document its supplier (issue #77/M12-3). */
    private function archivLieferant(Document $document, Vault $vault): bool
    {
        if ($document->source !== DocumentSource::Archiv) {
            return false;
        }
        $artefakt = $this->artifacts->fuerDokument($document->id, ArtifactKind::Vorgabe)[0] ?? null;
        $daten = $artefakt === null ? null : $this->daten($artefakt, $vault);

        return $daten !== null && ArchivVorgabe::fromArray($daten)?->lieferantId !== null;
    }

    /**
     * Stores the result as this run's artifact (seq 0) under its own data
     * key - the AAD names the row id, so the row comes first.
     */
    private function speichern(Document $document, int $jobId, Vault $vault, \DateTimeImmutable $now, Aufloesung $ergebnis): void
    {
        $vorhanden = $this->artifacts->finde($document->id, ArtifactKind::Lieferant, $jobId, 0);
        $schluessel = $vorhanden === null ? DataKey::generate() : $vault->openDataKey($vorhanden->dekSealed);
        $id = $vorhanden?->id ?? $this->artifacts->insert(
            $document->id,
            ArtifactKind::Lieferant,
            0,
            null,
            $vault->sealDataKey($schluessel),
            null,
            JobExecutor::Session,
            $jobId,
            $now,
        );
        $json = json_encode($ergebnis->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->artifacts->setzeDaten($id, FieldCipher::encrypt($schluessel, $json, new FieldContext(self::TABELLE, $id, self::SPALTE)));
    }

    /**
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
        $daten = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

        return is_array($daten) ? $daten : null;
    }

    private function dokument(Job $job): Document
    {
        if ($job->refType !== self::REF_TYPE || $job->refId === null) {
            throw new ProcessingException('Job ohne zugehöriges Dokument.');
        }

        return $this->documents->find($job->refId) ?? throw new ProcessingException('Dokument nicht gefunden.');
    }
}
