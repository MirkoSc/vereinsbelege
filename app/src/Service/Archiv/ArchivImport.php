<?php

declare(strict_types=1);

namespace App\Service\Archiv;

use App\Domain\ArchiveImportRecord;
use App\Domain\ArchiveImportStats;
use App\Domain\ArchiveImportStatus;
use App\Domain\ArtifactKind;
use App\Domain\AuditAction;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\CategoryDirection;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InvoiceDirection;
use App\Domain\JobExecutor;
use App\Domain\OcrStatus;
use App\Repository\ArchiveImportRepository;
use App\Repository\CategoryRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\SettingRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Document\Duplikatindex;
use App\Service\Document\KiAuslesung;
use App\Service\Document\PdfErzeugung;
use App\Service\Document\Texterkennung;
use App\Service\MasterData\SupplierKeys;
use App\Service\MasterData\SupplierRuleViolation;
use App\Service\MasterData\SupplierService;
use App\Service\Storage\BlobService;
use App\Service\Upload\MagicBytes;
use App\Service\Upload\UploadError;
use App\Service\Upload\UploadException;
use App\Service\Upload\UploadService;

/**
 * The archive import (M12-3, issue #77, docs/spec/05-auswertung-und-
 * export.md section 3): a ZIP of the club's sorted year folders becomes one
 * receipt per file. upload → preview → start → step chain → done, driven by
 * the import page like the statement import (06 section 4) - the user just
 * started it and waits; it does not queue behind other jobs.
 *
 * - The ZIP stays as its upload chunks in `shared/var/tmp` while the import
 *   runs (the one plaintext exception there, CLAUDE.md section 2) and is
 *   read in place (App\Service\Archiv\ZipLeser); every file is unpacked in
 *   pieces straight into an encrypted blob. When the import is done or
 *   discarded, the chunks go; the cron's 24 h rule counts from the last
 *   step (UploadService::beruehren()).
 * - Per file: the path is read (App\Service\Archiv\ArchivPfad), the supplier
 *   found by name or created (SupplierOrigin::Archiv), the document written
 *   with `source = archiv` and status `bereit_zur_auswertung` - straight
 *   into the review list, the archive is sorted already - or, from a
 *   Wiedervorlage folder, `wiedervorlage` due today. What the path said is
 *   kept as the document's `vorgabe` artifact (App\Service\Archiv\
 *   ArchivVorgabe) for the review page; the usual three session jobs (PDF,
 *   duplicate index, text) follow as for any captured receipt.
 * - One transaction per file, opened by moving the cursor from exactly the
 *   value read: a repeated or parallel step writes nothing twice. The blob
 *   is stored before the transaction and deleted again when it loses.
 * - Files that are no receipt (no PDF or image, `_Originale` copies, broken
 *   entries) are skipped and listed in the encrypted report; system files
 *   are only counted.
 *
 * Every method but verwerfen() needs the unlocked vault of the session.
 */
final readonly class ArchivImport
{
    /** Largest ZIP the upload takes. Without ZIP64 an archive ends below 4 GiB anyway. */
    public const int MAX_ZIP_BYTES = 2 * 1024 * 1024 * 1024;

    /** Budget of one step: files, unpacked bytes, seconds - whichever is reached first. */
    public const int SCHRITT_DATEIEN = 10;
    public const int SCHRITT_BYTES = 24 * 1024 * 1024;
    public const int SCHRITT_SEKUNDEN = 15;

    /** Rows of the preview shown in detail. */
    public const int VORSCHAU_ZEILEN = 50;

    /** Entries the report keeps - more would only be noise. */
    public const int BERICHT_MAX = 2000;

    public const string UPLOAD_WEG = 'Die hochgeladene ZIP-Datei ist nicht mehr da (nach 24 Stunden ohne Fortschritt wird sie gelöscht). Bereits übernommene Belege bleiben erhalten – bitte das ZIP für den Rest erneut hochladen.';

    public const string WIEDERVORLAGE_NOTIZ = 'Aus dem Archiv-Import (Ordner Wiedervorlage).';

    private const string TABLE = 'archive_import';
    private const string COLUMN_REPORT = 'report_enc';

    public function __construct(
        private \PDO $pdo,
        private ArchiveImportRepository $importe,
        private UploadService $uploads,
        private BlobService $blobs,
        private SettingRepository $settings,
        private DocumentRepository $documents,
        private DocumentArtifactRepository $artifacts,
        private JobRepository $jobs,
        private SupplierService $lieferanten,
        private CategoryRepository $kategorien,
        private AuditLog $audit,
        private int $schrittDateien = self::SCHRITT_DATEIEN,
    ) {
    }

    /**
     * Turns a complete upload into an import `vorschau`. The upload is
     * thrown away when it is no ZIP we can read.
     *
     * @throws UngueltigesZip
     * @throws UploadException the upload is unknown or incomplete
     */
    public function hochgeladen(Vault $vault, string $uploadId, ?int $userId, \DateTimeImmutable $now): int
    {
        // The browser repeated the closing request (its answer got lost):
        // the same import, not a second one on the same chunks.
        $vorhanden = $this->importe->idFuerUpload($uploadId);
        if ($vorhanden !== null) {
            return $vorhanden;
        }

        $status = $this->uploads->status($uploadId);
        if (!$status->vollstaendig()) {
            throw new UploadException(UploadError::Incomplete);
        }

        try {
            $eintraege = new ZipLeser(new UploadQuelle($this->uploads, $uploadId))->verzeichnis();
        } catch (UngueltigesZip $e) {
            $this->uploads->discard($uploadId);

            throw $e;
        }
        if ($eintraege === []) {
            $this->uploads->discard($uploadId);

            throw new UngueltigesZip('Das ZIP ist leer.');
        }

        return $this->importe->insert($uploadId, DateinamenMuster::STANDARD, count($eintraege), $vault->sealDataKey(DataKey::generate()), $userId, $now);
    }

    /**
     * The preview with the pattern asked for (null: the stored one). A
     * usable pattern is remembered for the import.
     *
     * @throws ArchivImportFehler unknown import
     */
    public function vorschau(Vault $vault, int $id, ?string $muster, \DateTimeImmutable $now): ArchivVorschau
    {
        $import = $this->finde($id);
        $muster ??= $import->namePattern;
        $leer = ['dateien' => 0, 'ordner' => 0, 'systemdateien' => 0, 'uebersprungen' => 0, 'ohne_vorbelegung' => 0, 'kasse' => 0, 'wiedervorlage' => 0, 'lieferanten_neu' => 0, 'lieferanten_mehrdeutig' => 0];

        try {
            $geparst = DateinamenMuster::parse($muster);
        } catch (UngueltigesDateinamenMuster $e) {
            return new ArchivVorschau($import, $muster, $e->getMessage(), $leer, []);
        }
        if ($import->status === ArchiveImportStatus::Vorschau && $geparst->muster !== $import->namePattern) {
            $this->importe->setzeMuster($id, $geparst->muster, $now);
        }

        try {
            $eintraege = $this->leser($import)->verzeichnis();
        } catch (UngueltigesZip $e) {
            $this->abbrechen($import, $e->getMessage(), $now);

            return new ArchivVorschau($this->finde($id), $muster, null, $leer, [], $e->getMessage());
        }

        [$ausgabe, $einnahme] = $this->kategorienamen();
        $alleKategorien = $ausgabe + $einnahme;
        $zaehler = $leer;
        $zeilen = [];
        $lieferantStatus = [];
        foreach ($eintraege as $eintrag) {
            if ($eintrag->ordner) {
                $zaehler['ordner']++;

                continue;
            }
            $v = $eintrag->problem === null
                ? ArchivPfad::analysieren($eintrag->pfad, $geparst, $ausgabe, $einnahme, $now)
                : new ArchivVorbelegung($eintrag->pfad, ueberspringen: $eintrag->problem);
            if ($v->systemdatei) {
                $zaehler['systemdateien']++;

                continue;
            }

            $status = null;
            if ($v->ueberspringen !== null) {
                $zaehler['uebersprungen']++;
            } else {
                $zaehler['dateien']++;
                $zaehler['ohne_vorbelegung'] += $v->musterPasst ? 0 : 1;
                $zaehler['kasse'] += $v->kasse ? 1 : 0;
                $zaehler['wiedervorlage'] += $v->wiedervorlage ? 1 : 0;
                if ($v->lieferant !== null) {
                    $schluessel = $v->richtung->value . '|' . SupplierKeys::name($v->lieferant);
                    if (!isset($lieferantStatus[$schluessel])) {
                        $treffer = count($this->lieferanten->idsZuNamen($vault, $v->lieferant, $v->richtung->lieferantenRollen()));
                        $lieferantStatus[$schluessel] = match (true) {
                            $treffer === 0 => 'neu',
                            $treffer === 1 => 'vorhanden',
                            default => 'mehrdeutig',
                        };
                        $zaehler['lieferanten_neu'] += $treffer === 0 ? 1 : 0;
                        $zaehler['lieferanten_mehrdeutig'] += $treffer > 1 ? 1 : 0;
                    }
                    $status = $lieferantStatus[$schluessel];
                }
            }

            if (count($zeilen) < self::VORSCHAU_ZEILEN) {
                $zeilen[] = new ArchivVorschauZeile($v, $status, $v->kategorieId === null ? null : ($alleKategorien[$v->kategorieId] ?? null));
            }
        }

        return new ArchivVorschau($this->finde($id), $geparst->muster, null, $zaehler, $zeilen);
    }

    /**
     * Confirms the preview; the step chain may start. A second click does
     * nothing.
     *
     * @throws UngueltigesDateinamenMuster
     * @throws ArchivImportFehler
     */
    public function starten(int $id, string $muster, \DateTimeImmutable $now): void
    {
        $import = $this->finde($id);
        if ($import->status !== ArchiveImportStatus::Vorschau) {
            if ($import->status === ArchiveImportStatus::Abgebrochen) {
                throw new ArchivImportFehler('Dieser Import wurde abgebrochen.');
            }

            return;
        }
        if (!$this->uploads->vorhanden($import->uploadId)) {
            $this->abbrechen($import, self::UPLOAD_WEG, $now);

            throw new ArchivImportFehler(self::UPLOAD_WEG);
        }

        $this->importe->starte($id, DateinamenMuster::parse($muster)->muster, $now);
    }

    /**
     * One step of the chain: imports the next files and says where the
     * import stands. Safe to call again and from two tabs at once.
     *
     * @throws ArchivImportFehler unknown or not started
     */
    public function schritt(Vault $vault, int $id, ?int $userId, string $ip, \DateTimeImmutable $now): ArchivImportStand
    {
        $import = $this->finde($id);
        if (!$import->status->offen()) {
            return self::stand($import);
        }
        if ($import->status !== ArchiveImportStatus::Laeuft) {
            throw new ArchivImportFehler('Dieser Import ist noch nicht gestartet.');
        }

        try {
            $leser = $this->leser($import);
            $eintraege = $leser->verzeichnis();
        } catch (UngueltigesZip $e) {
            $this->abbrechen($import, $e->getMessage(), $now, $userId, $ip);

            return self::stand($this->finde($id), $e->getMessage());
        }
        $this->uploads->beruehren($import->uploadId, $now);

        try {
            $muster = DateinamenMuster::parse($import->namePattern);
        } catch (UngueltigesDateinamenMuster) {
            $muster = DateinamenMuster::standard();
        }
        [$ausgabe, $einnahme] = $this->kategorienamen();
        $storage = BlobService::configuredStorage($this->settings);

        $beginn = hrtime(true);
        $index = $import->nextIndex;
        $stats = $import->stats;
        $dateien = 0;
        $bytes = 0;
        while ($index < count($eintraege)
            && $dateien < $this->schrittDateien
            && $bytes < self::SCHRITT_BYTES
            && (hrtime(true) - $beginn) / 1e9 < self::SCHRITT_SEKUNDEN
        ) {
            $eintrag = $eintraege[$index];
            try {
                $ergebnis = $this->eintrag($vault, $import, $leser, $eintrag, $muster, $ausgabe, $einnahme, $stats, $storage, $userId, $ip, $now);
            } catch (UngueltigesZip $e) {
                // The chunks went away in the middle of an entry (the cron,
                // a manual clean-up): that entry was rolled back, the rest
                // stays as it is.
                $this->abbrechen($this->finde($id), $e->getMessage(), $now, $userId, $ip);

                return self::stand($this->finde($id), $e->getMessage());
            }
            if ($ergebnis === null) {
                // Another request moved the chain on meanwhile: report what is.
                return self::stand($this->finde($id));
            }

            $stats = $ergebnis;
            $index++;
            if (!$eintrag->ordner) {
                $dateien++;
                $bytes += $eintrag->groesse;
            }
        }

        if ($index < count($eintraege)) {
            return new ArchivImportStand(ArchiveImportStatus::Laeuft, $index, count($eintraege), $stats->belege);
        }

        if ($this->importe->finish($id, $now)) {
            $this->audit->record(AuditAction::ArchivImportiert, $userId, $ip, $id, [
                'status' => ArchiveImportStatus::Fertig->value,
                'dateien' => count($eintraege),
                ...$stats->toArray(),
            ], $now);
            $this->uploads->discard($import->uploadId);
        }

        return self::stand($this->finde($id));
    }

    /**
     * Discards the import: the upload goes, the documents already written
     * stay. Needs no vault.
     */
    public function verwerfen(int $id, ?int $userId, string $ip, \DateTimeImmutable $now): bool
    {
        $import = $this->importe->find($id);
        if ($import === null || !$import->status->offen()) {
            return false;
        }

        return $this->abbrechen($import, null, $now, $userId, $ip);
    }

    /**
     * The files that were not imported, with the reason.
     *
     * @return list<array{pfad: string, grund: string}>
     */
    public function bericht(Vault $vault, ArchiveImportRecord $import): array
    {
        if ($import->reportEnc === null) {
            return [];
        }
        $json = FieldCipher::decrypt($vault->openDataKey($import->dekSealed), $import->reportEnc, new FieldContext(self::TABLE, $import->id, self::COLUMN_REPORT));
        $liste = json_decode($json, true, 4, JSON_THROW_ON_ERROR);

        $bericht = [];
        foreach (is_array($liste) ? $liste : [] as $zeile) {
            if (is_array($zeile) && is_string($zeile['pfad'] ?? null) && is_string($zeile['grund'] ?? null)) {
                $bericht[] = ['pfad' => $zeile['pfad'], 'grund' => $zeile['grund']];
            }
        }

        return $bericht;
    }

    /**
     * @throws ArchivImportFehler
     */
    public function finde(int $id): ArchiveImportRecord
    {
        return $this->importe->find($id) ?? throw new ArchivImportFehler('Diesen Import gibt es nicht.');
    }

    /**
     * The latest imports for the history on the import page.
     *
     * @return list<ArchiveImportRecord>
     */
    public function letzte(int $anzahl): array
    {
        return $this->importe->recent($anzahl);
    }

    /**
     * Imports one entry - or records why not - and moves the cursor past it
     * in one transaction. Null when another request had moved the cursor
     * already; then nothing was written.
     *
     * @param array<int, string> $ausgabe
     * @param array<int, string> $einnahme
     */
    private function eintrag(
        Vault $vault,
        ArchiveImportRecord $import,
        ZipLeser $leser,
        ZipEintrag $eintrag,
        DateinamenMuster $muster,
        array $ausgabe,
        array $einnahme,
        ArchiveImportStats $stats,
        BlobStorage $storage,
        ?int $userId,
        string $ip,
        \DateTimeImmutable $now,
    ): ?ArchiveImportStats {
        if ($eintrag->ordner) {
            return $this->auslassen($vault, $import, $eintrag, $stats->mitAuslassung(true, false), null, $now);
        }
        if ($eintrag->problem !== null) {
            return $this->auslassen($vault, $import, $eintrag, $stats->mitAuslassung(false, false), $eintrag->problem, $now);
        }

        $v = ArchivPfad::analysieren($eintrag->pfad, $muster, $ausgabe, $einnahme, $now);
        if ($v->ueberspringen !== null) {
            return $this->auslassen($vault, $import, $eintrag, $stats->mitAuslassung(false, $v->systemdatei), $v->systemdatei ? null : $v->ueberspringen, $now);
        }

        try {
            [$typ, $inhalt] = self::oeffne($leser, $eintrag);
            if ($typ === null) {
                return $this->auslassen($vault, $import, $eintrag, $stats->mitAuslassung(false, false), 'Kein PDF und kein Bild (JPEG/PNG) – nicht übernommen.', $now);
            }
            $blob = $this->blobs->store($inhalt, new BlobMeta($typ, mb_substr($eintrag->dateiname(), 0, 200)), $vault, $storage);
        } catch (ZipEintragDefekt $e) {
            return $this->auslassen($vault, $import, $eintrag, $stats->mitAuslassung(false, false), $e->getMessage(), $now);
        }

        $lieferantNeu = null;
        $this->pdo->beginTransaction();
        try {
            // First, so a parallel step waits on the row lock or loses here.
            if (!$this->importe->advance($import->id, $eintrag->index, $now)) {
                $this->pdo->rollBack();
                $this->blobs->delete($blob);

                return null;
            }

            [$lieferantId, $lieferantNeu, $hinweise] = $this->lieferant($vault, $v, $now);
            $vorgabe = ArchivVorgabe::aus($v, $lieferantId, $hinweise);

            $dek = DataKey::generate();
            $status = $v->wiedervorlage ? DocumentStatus::Wiedervorlage : DocumentStatus::BereitZurAuswertung;
            $documentId = $this->documents->insert(
                DocumentSource::Archiv,
                null,
                [$blob->id],
                $vault->sealDataKey($dek),
                $now,
                status: $status,
                ocrStatus: OcrStatus::Ausstehend,
                createdBy: $userId,
            );
            if ($v->wiedervorlage) {
                $notiz = FieldCipher::encrypt($dek, self::WIEDERVORLAGE_NOTIZ, new FieldContext('document', $documentId, 'status_note_enc'));
                $this->documents->wechsleStatus($documentId, $status, $status, $notiz, $now, $userId, $now);
            }

            $artefaktKey = DataKey::generate();
            $artefaktId = $this->artifacts->insert($documentId, ArtifactKind::Vorgabe, 0, null, $vault->sealDataKey($artefaktKey), null, JobExecutor::Session, null, $now);
            $this->artifacts->setzeDaten($artefaktId, FieldCipher::encrypt(
                $artefaktKey,
                json_encode($vorgabe->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                new FieldContext('document_artifact', $artefaktId, 'data_enc'),
            ));

            foreach ([PdfErzeugung::JOB_TYP, Duplikatindex::JOB_TYP, Texterkennung::JOB_TYP] as $typ) {
                $this->jobs->enqueue($typ, JobExecutor::Session, 'document', $documentId, now: $now);
            }
            // Ready for evaluation right away (no inbox): the AI reads it
            // (issue #47/M7-5); the path's values lie over its suggestion.
            if ($status === DocumentStatus::BereitZurAuswertung) {
                KiAuslesung::einreihen($this->jobs, $documentId, $now);
            }

            $stats = $stats->mitBeleg($documentId, $v->wiedervorlage, $v->kasse, !$v->musterPasst, $lieferantNeu !== null);
            $this->importe->setzeStand($import->id, $stats, null);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->blobs->delete($blob);

            throw $e;
        }

        if ($lieferantNeu !== null) {
            $this->audit->record(AuditAction::LieferantAngelegt, $userId, $ip, $lieferantNeu, ['herkunft' => 'archiv', 'import' => $import->id], $now);
        }

        return $stats;
    }

    /**
     * The supplier of one file: found by name, created, or - when several
     * have that name or the name is unusable - none, with the reason.
     *
     * @return array{0: ?int, 1: ?int, 2: list<string>} id, id if created, hints
     */
    private function lieferant(Vault $vault, ArchivVorbelegung $v, \DateTimeImmutable $now): array
    {
        if ($v->lieferant === null) {
            return [null, null, []];
        }

        $ids = $this->lieferanten->idsZuNamen($vault, $v->lieferant, $v->richtung->lieferantenRollen());
        if (count($ids) === 1) {
            return [$ids[0], null, []];
        }
        if (count($ids) > 1) {
            return [null, null, [sprintf('Mehrere %s heißen „%s“ – bitte selbst wählen.', $v->richtung === InvoiceDirection::Ausgabe ? 'Lieferanten' : 'Zahler', $v->lieferant)]];
        }

        try {
            $id = $this->lieferanten->anlegenAusArchiv($vault, $v->lieferant, $v->richtung->neueLieferantenRolle(), $now);
        } catch (SupplierRuleViolation $e) {
            return [null, null, [sprintf('%s „%s“ ließ sich nicht anlegen: %s', $v->richtung->partner(), $v->lieferant, $e->getMessage())]];
        }

        return [$id, $id, []];
    }

    /**
     * Moves the cursor past an entry that becomes no document, noting the
     * reason in the report when there is one.
     */
    private function auslassen(Vault $vault, ArchiveImportRecord $import, ZipEintrag $eintrag, ArchiveImportStats $stats, ?string $grund, \DateTimeImmutable $now): ?ArchiveImportStats
    {
        $this->pdo->beginTransaction();
        try {
            if (!$this->importe->advance($import->id, $eintrag->index, $now)) {
                $this->pdo->rollBack();

                return null;
            }

            $reportEnc = null;
            if ($grund !== null) {
                // Read inside the transaction: the row lock is ours, so no
                // other step appends meanwhile.
                $aktuell = $this->finde($import->id);
                $bericht = $this->bericht($vault, $aktuell);
                if (count($bericht) < self::BERICHT_MAX) {
                    $bericht[] = ['pfad' => $eintrag->pfad, 'grund' => $grund];
                    $reportEnc = FieldCipher::encrypt(
                        $vault->openDataKey($import->dekSealed),
                        json_encode($bericht, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        new FieldContext(self::TABLE, $import->id, self::COLUMN_REPORT),
                    );
                }
            }
            $this->importe->setzeStand($import->id, $stats, $reportEnc);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $stats;
    }

    /**
     * Ends an open import: status, audit (only when it wrote something),
     * upload gone.
     */
    private function abbrechen(ArchiveImportRecord $import, ?string $grund, \DateTimeImmutable $now, ?int $userId = null, string $ip = ''): bool
    {
        if (!$this->importe->abbrechen($import->id, $now)) {
            return false;
        }
        $this->uploads->discard($import->uploadId);
        if ($import->stats->belege > 0) {
            $this->audit->record(AuditAction::ArchivImportiert, $userId, $ip, $import->id, [
                'status' => ArchiveImportStatus::Abgebrochen->value,
                'grund' => $grund === null ? 'verworfen' : 'upload_fehlt',
                'dateien' => $import->total,
                ...$import->stats->toArray(),
            ], $now);
        }

        return true;
    }

    private function leser(ArchiveImportRecord $import): ZipLeser
    {
        if (!$this->uploads->vorhanden($import->uploadId)) {
            throw new UngueltigesZip(self::UPLOAD_WEG);
        }

        return new ZipLeser(new UploadQuelle($this->uploads, $import->uploadId));
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>} active categories for expenses and for income
     */
    private function kategorienamen(): array
    {
        return [$this->kategorien->active(CategoryDirection::Ausgabe), $this->kategorien->active(CategoryDirection::Einnahme)];
    }

    /**
     * Looks at the first bytes of an entry to learn what it is, and hands
     * back the whole content as one stream again - unpacked once.
     *
     * @return array{0: ?string, 1: \Generator<int, string>}
     * @throws ZipEintragDefekt
     */
    private static function oeffne(ZipLeser $leser, ZipEintrag $eintrag): array
    {
        $strom = $leser->inhalt($eintrag);
        $gelesen = [];
        $kopf = '';
        while ($strom->valid() && strlen($kopf) < MagicBytes::HEAD_BYTES) {
            $teil = $strom->current();
            $gelesen[] = $teil;
            $kopf .= $teil;
            $strom->next();
        }

        $alles = (static function () use ($gelesen, $strom): \Generator {
            yield from $gelesen;
            while ($strom->valid()) {
                yield $strom->current();
                $strom->next();
            }
        })();

        return [MagicBytes::detect(substr($kopf, 0, MagicBytes::HEAD_BYTES)), $alles];
    }

    private static function stand(ArchiveImportRecord $import, ?string $meldung = null): ArchivImportStand
    {
        return new ArchivImportStand($import->status, min($import->nextIndex, $import->total), $import->total, $import->stats->belege, $meldung);
    }
}
