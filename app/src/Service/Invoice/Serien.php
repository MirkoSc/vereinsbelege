<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\AuditAction;
use App\Domain\Document;
use App\Domain\DocumentStatus;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceRecord;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\Permission;
use App\Domain\RecurringSeriesRecord;
use App\Domain\SeriesData;
use App\Domain\SeriesPeriod;
use App\Domain\SeriesStatus;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\RecurringSeriesRepository;
use App\Repository\SettingRepository;
use App\Repository\SupplierRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Document\KiAuslesung;
use App\Service\Job\JobHandler;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\MasterData\SupplierService;
use App\Service\Processing\Betrag;
use App\Service\Processing\ProcessingException;

/**
 * Recurring invoices (issue #57/M8-1, docs/spec/03-erfassung-und-ki.md
 * section 9, E-26): the `detect_recurring` job and what a person does with
 * its suggestions - confirm, change, dismiss.
 *
 * The job runs for every receipt marked "Geprüft" (App\Service\Invoice\
 * Pruefung queues it; migration 037 once for the receipts before). One
 * step, in one transaction under a row lock of the supplier (two receipts
 * of one supplier checked at once do not make two suggestions):
 *
 * 1. A receipt not yet in a series that fits a **confirmed** series of its
 *    supplier and direction - same currency, amount within the tolerance of
 *    the expected amount, no receipt of the series within half a rhythm of
 *    its date - is linked to it (the closest amount wins); is it the newest,
 *    the series takes its amount and the next date moves on. Audit
 *    `serie.beleg_verknuepft` without a user.
 * 2. The supplier's checked receipts in no series (or in its pending
 *    suggestion) go through App\Service\Invoice\Serienerkennung. A series
 *    found becomes the supplier's suggestion - the pending one is updated
 *    (label and contract reference stay), else a new one is created. No
 *    audit for a suggestion; nothing is decided yet.
 *
 * Amounts are vault data: the job is a session job, detection runs on the
 * decrypted amounts in memory, the series stores its own encrypted copy.
 * Nothing here writes an `invoice` row - a locked receipt stays as it is
 * (docs/spec/01-sicherheit.md section 7); the links are a table of their
 * own. Messages and audit details never name an amount or a supplier.
 */
final readonly class Serien implements JobHandler
{
    public const string JOB_TYP = 'detect_recurring';

    public const int BEZEICHNUNG_MAX = 100;

    public const int VERTRAG_MAX = 100;

    /** Days a series' next receipt may be late at most (M8-2). */
    public const int TOLERANZ_TAGE_MAX = 120;

    private const string REF_TYPE = 'document';

    private const string TABELLE = 'recurring_series';

    private const string SPALTE = 'data_enc';

    public function __construct(
        private \PDO $pdo,
        private RecurringSeriesRepository $serien,
        private InvoiceRepository $invoices,
        private DocumentRepository $documents,
        private SupplierRepository $lieferantenRepository,
        private SupplierService $lieferanten,
        private KiAuslesung $kiAuslesung,
        private SettingRepository $settings,
        private AuditLog $audit,
    ) {
    }

    public function typ(): string
    {
        return self::JOB_TYP;
    }

    /** `document.edit`, like the review page whose "Geprüft" queues it. */
    public function recht(): Permission
    {
        return Permission::DocumentEdit;
    }

    /** Queues the job for $documentId unless one is still to do. */
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
        if ($job->refType !== self::REF_TYPE || $job->refId === null) {
            throw new ProcessingException('Job ohne zugehöriges Dokument.');
        }
        $document = $this->documents->find($job->refId) ?? throw new ProcessingException('Dokument nicht gefunden.');
        $beleg = $this->invoices->findByDocument($document->id);
        if (
            $beleg === null || $beleg->supplierId === null || $beleg->checkedAt === null
            || !in_array($document->status, [DocumentStatus::Geprueft, DocumentStatus::Festgeschrieben], true)
        ) {
            return JobSchrittErgebnis::uebersprungen();
        }
        [$kiHinweis, $kiPeriode] = $this->kiHinweis($document, $vault);

        $this->pdo->beginTransaction();
        try {
            $lieferant = $this->lieferantenRepository->aufgeloest($beleg->supplierId);
            $this->lieferantenRepository->sperre($lieferant);
            $this->erkenne($vault, $lieferant, $beleg->direction, $beleg->id, $kiHinweis, $kiPeriode, $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return JobSchrittErgebnis::fertig();
    }

    /** How many suggestions wait for a person - for the list page. */
    public function anzahlVorschlaege(): int
    {
        return $this->serien->anzahl(SeriesStatus::Vorgeschlagen);
    }

    /**
     * Suggestions and confirmed series, next expected date first.
     *
     * @return list<SerienAnsicht>
     */
    public function liste(Vault $vault): array
    {
        $anzahl = $this->serien->anzahlMitglieder();
        $namen = [];
        $liste = [];
        foreach ($this->serien->liste([SeriesStatus::Vorgeschlagen, SeriesStatus::Bestaetigt]) as $record) {
            $namen[$record->supplierId] ??= $this->lieferantName($vault, $record->supplierId);
            $liste[] = new SerienAnsicht($record, $this->daten($vault, $record), $namen[$record->supplierId], $anzahl[$record->id] ?? 0);
        }

        return $liste;
    }

    /** One series with its receipts and the ones that could be added. */
    public function ansicht(Vault $vault, int $id): ?SerienAnsicht
    {
        $record = $this->serien->find($id);
        if ($record === null) {
            return null;
        }
        $ids = $this->serien->mitglieder($id);
        $mitglieder = array_map(fn(InvoiceRecord $r): SerienPosten => $this->posten($vault, $r), $this->invoices->nachIds($ids));

        $weitere = [];
        if ($record->status !== SeriesStatus::Verworfen) {
            $kandidaten = $this->invoices->geprueftFuerLieferant($record->supplierId, $record->direction);
            $zuordnung = $this->serien->serienVon(array_map(static fn(InvoiceRecord $r): int => $r->id, $kandidaten));
            foreach ($kandidaten as $kandidat) {
                if (!isset($zuordnung[$kandidat->id])) {
                    $weitere[] = $this->posten($vault, $kandidat);
                }
            }
        }

        return new SerienAnsicht(
            $record,
            $this->daten($vault, $record),
            $this->lieferantName($vault, $record->supplierId),
            count($mitglieder),
            $mitglieder,
            $weitere,
        );
    }

    /**
     * Confirms a suggestion or saves a confirmed series, with what the
     * person entered and the receipts they kept or added. Audit
     * `serie.bestaetigt` (a suggestion) or `serie.geaendert` (only when
     * something changed) - the names of the changed fields, never a value.
     *
     * @param array<string, string> $felder periode, betrag, naechste, toleranz, bezeichnung, vertrag
     * @param list<int> $belege invoice ids
     *
     * @throws InvoiceRuleViolation
     */
    public function speichern(Vault $vault, int $id, array $felder, array $belege, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        $periode = SeriesPeriod::tryFrom($felder['periode'] ?? '')
            ?? throw new InvoiceRuleViolation('Bitte einen Rhythmus wählen.', 'periode');
        $betrag = Betrag::parse(trim($felder['betrag'] ?? ''));
        if ($betrag === null || $betrag <= 0) {
            throw new InvoiceRuleViolation('Erwarteter Betrag bitte als positiven Betrag wie 12,34 angeben.', 'betrag');
        }
        $naechste = self::datum(trim($felder['naechste'] ?? ''));
        if ($naechste === false) {
            throw new InvoiceRuleViolation('Nächste Fälligkeit ist kein gültiges Datum.', 'naechste');
        }
        if ($naechste === null && $periode !== SeriesPeriod::Unregelmaessig) {
            throw new InvoiceRuleViolation('Bitte die nächste Fälligkeit angeben.', 'naechste');
        }
        $toleranzText = trim($felder['toleranz'] ?? '');
        if (preg_match('/^\d{1,3}$/', $toleranzText) !== 1 || (int) $toleranzText > self::TOLERANZ_TAGE_MAX) {
            throw new InvoiceRuleViolation(sprintf('Toleranz bitte als ganze Tage von 0 bis %d angeben.', self::TOLERANZ_TAGE_MAX), 'toleranz');
        }
        $bezeichnung = trim($felder['bezeichnung'] ?? '');
        $vertrag = trim($felder['vertrag'] ?? '');
        if (mb_strlen($bezeichnung) > self::BEZEICHNUNG_MAX) {
            throw new InvoiceRuleViolation(sprintf('Bezeichnung darf höchstens %d Zeichen lang sein.', self::BEZEICHNUNG_MAX), 'bezeichnung');
        }
        if (mb_strlen($vertrag) > self::VERTRAG_MAX) {
            throw new InvoiceRuleViolation(sprintf('Vertragsnummer darf höchstens %d Zeichen lang sein.', self::VERTRAG_MAX), 'vertrag');
        }
        $belege = array_values(array_unique($belege));
        if ($belege === []) {
            throw new InvoiceRuleViolation('Eine Serie braucht mindestens einen Beleg.', 'belege');
        }

        $this->pdo->beginTransaction();
        try {
            $record = $this->serien->sperre($id) ?? throw new InvoiceRuleViolation('Diese Serie gibt es nicht (mehr).');
            if ($record->status === SeriesStatus::Verworfen) {
                throw new InvoiceRuleViolation('Diese Serie wurde verworfen und lässt sich nicht mehr ändern.');
            }
            $this->pruefeBelege($record, $belege);

            $vorher = $this->daten($vault, $record);
            $daten = new SeriesData($bezeichnung, $betrag, $vorher->currency, $vertrag);
            $vorherBelege = $this->serien->mitglieder($id);
            $geaendert = array_keys(array_filter([
                'periode' => $periode !== $record->period,
                'betrag' => $betrag !== $vorher->expectedGross,
                'naechste' => $naechste?->format('Y-m-d') !== $record->nextExpected?->format('Y-m-d'),
                'toleranz' => (int) $toleranzText !== $record->toleranceDays,
                'bezeichnung' => $bezeichnung !== $vorher->label,
                'vertrag' => $vertrag !== $vorher->contractRef,
                'belege' => self::sortiert($belege) !== self::sortiert($vorherBelege),
            ]));

            $bestaetigt = $record->status === SeriesStatus::Vorgeschlagen;
            if ($geaendert !== []) {
                $this->serien->update($id, $periode, $naechste, (int) $toleranzText, $this->verschluesseln($vault, $record, $daten), $userId, $now);
                if (in_array('belege', $geaendert, true)) {
                    $this->serien->setzeMitglieder($id, $belege);
                }
            }
            if ($bestaetigt) {
                $this->serien->entscheide($id, SeriesStatus::Bestaetigt, $userId, $now);
                $this->audit->record(AuditAction::SerieBestaetigt, $userId, $ip, $id, ['felder' => $geaendert], $now);
            } elseif ($geaendert !== []) {
                $this->audit->record(AuditAction::SerieGeaendert, $userId, $ip, $id, ['felder' => $geaendert], $now);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Dismisses a suggestion or a confirmed series. Its receipts stay
     * linked, so detection does not propose them again. Audit
     * `serie.verworfen` (details: the status before).
     *
     * @throws InvoiceRuleViolation
     */
    public function verwerfen(int $id, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $record = $this->serien->sperre($id) ?? throw new InvoiceRuleViolation('Diese Serie gibt es nicht (mehr).');
            if ($record->status === SeriesStatus::Verworfen) {
                throw new InvoiceRuleViolation('Diese Serie ist bereits verworfen.');
            }
            $this->serien->entscheide($id, SeriesStatus::Verworfen, $userId, $now);
            $this->audit->record(AuditAction::SerieVerworfen, $userId, $ip, $id, ['von' => $record->status->value], $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Both steps of the job for one supplier and direction (see the class
     * comment). Runs inside the caller's transaction.
     */
    private function erkenne(
        Vault $vault,
        int $lieferant,
        InvoiceDirection $richtung,
        int $ausloeser,
        bool $kiHinweis,
        ?SeriesPeriod $kiPeriode,
        \DateTimeImmutable $now,
    ): void {
        $belege = [];
        $dokumente = [];
        foreach ($this->invoices->geprueftFuerLieferant($lieferant, $richtung) as $record) {
            $daten = Pruefung::entschluesseln($vault, $record);
            $belege[$record->id] = new SerienBeleg($record->id, $record->invoiceDate, $daten->gross, $daten->currency);
            $dokumente[$record->id] = $record->documentId;
        }
        if (!isset($belege[$ausloeser])) {
            return;
        }
        $serien = $this->serien->fuerLieferant($lieferant, $richtung);
        $zuordnung = $this->serien->serienVon(array_keys($belege));
        $erkennung = new Serienerkennung($this->toleranzProzent());

        if (!isset($zuordnung[$ausloeser])) {
            $ziel = $this->passendeSerie($vault, $erkennung, $serien, $belege, $zuordnung, $belege[$ausloeser]);
            if ($ziel !== null) {
                $this->verknuepfe($vault, $ziel, $belege, $zuordnung, $belege[$ausloeser], $dokumente[$ausloeser], $now);
                $zuordnung[$ausloeser] = $ziel->id;
            }
        }

        $vorschlag = null;
        foreach ($serien as $serie) {
            if ($serie->status === SeriesStatus::Vorgeschlagen) {
                $vorschlag = $serie;
            }
        }
        $frei = array_values(array_filter(
            $belege,
            static fn(SerienBeleg $b): bool => !isset($zuordnung[$b->invoiceId]) || $zuordnung[$b->invoiceId] === $vorschlag?->id,
        ));
        if ($frei === []) {
            return;
        }
        // The AI's hint belongs to the receipt that queued the job - it
        // counts only when that is the newest free receipt.
        $neuester = array_reduce($frei, static fn(?SerienBeleg $n, SerienBeleg $b): SerienBeleg => $n === null || [$b->datum, $b->invoiceId] > [$n->datum, $n->invoiceId] ? $b : $n);
        $hinweis = $kiHinweis && $neuester?->invoiceId === $ausloeser;
        $kandidat = $erkennung->erkenne($frei, $hinweis, $hinweis ? $kiPeriode : null);
        if ($kandidat === null) {
            return;
        }

        if ($vorschlag === null) {
            $key = DataKey::generate();
            $id = $this->serien->insert(
                $lieferant,
                $richtung,
                $kandidat->periode,
                SeriesStatus::Vorgeschlagen,
                $kandidat->naechsteFaelligkeit,
                $kandidat->periode->standardToleranzTage(),
                $vault->sealDataKey($key),
                $now,
            );
            $daten = new SeriesData('', $kandidat->letzterBetrag, $kandidat->waehrung, '');
            $this->serien->setData($id, self::verschluesselnMit($key, $id, $daten));
        } else {
            $id = $vorschlag->id;
            $vorher = $this->daten($vault, $vorschlag);
            $daten = new SeriesData($vorher->label, $kandidat->letzterBetrag, $kandidat->waehrung, $vorher->contractRef);
            $this->serien->update(
                $id,
                $kandidat->periode,
                $kandidat->naechsteFaelligkeit,
                $kandidat->periode->standardToleranzTage(),
                $this->verschluesseln($vault, $vorschlag, $daten),
                null,
                $now,
            );
        }
        $this->serien->setzeMitglieder($id, $kandidat->invoiceIds);
    }

    /**
     * The confirmed series $beleg fits best, or null.
     *
     * @param list<RecurringSeriesRecord> $serien
     * @param array<int, SerienBeleg> $belege
     * @param array<int, int> $zuordnung invoice id => series id
     */
    private function passendeSerie(Vault $vault, Serienerkennung $erkennung, array $serien, array $belege, array $zuordnung, SerienBeleg $beleg): ?RecurringSeriesRecord
    {
        $beste = null;
        $abstand = PHP_INT_MAX;
        foreach ($serien as $serie) {
            $tage = $serie->period->tage();
            if ($serie->status !== SeriesStatus::Bestaetigt || $tage === null) {
                continue;
            }
            $daten = $this->daten($vault, $serie);
            if ($daten->currency !== $beleg->waehrung || !$erkennung->betragPasst($beleg->brutto, $daten->expectedGross)) {
                continue;
            }
            foreach ($zuordnung as $invoiceId => $serieId) {
                if ($serieId === $serie->id && isset($belege[$invoiceId]) && $belege[$invoiceId]->datum->diff($beleg->datum)->days < $tage / 2) {
                    continue 2;
                }
            }
            if (abs($beleg->brutto - $daten->expectedGross) < $abstand) {
                $beste = $serie;
                $abstand = abs($beleg->brutto - $daten->expectedGross);
            }
        }

        return $beste;
    }

    /**
     * Links $beleg to the confirmed $serie; the newest receipt sets the
     * expected amount and the next date.
     *
     * @param array<int, SerienBeleg> $belege
     * @param array<int, int> $zuordnung invoice id => series id
     */
    private function verknuepfe(Vault $vault, RecurringSeriesRecord $serie, array $belege, array $zuordnung, SerienBeleg $beleg, int $documentId, \DateTimeImmutable $now): void
    {
        $this->serien->verknuepfe($serie->id, $beleg->invoiceId);
        $neuester = true;
        foreach ($zuordnung as $invoiceId => $serieId) {
            if ($serieId === $serie->id && isset($belege[$invoiceId]) && $belege[$invoiceId]->datum > $beleg->datum) {
                $neuester = false;
            }
        }
        if ($neuester) {
            $vorher = $this->daten($vault, $serie);
            $daten = new SeriesData($vorher->label, $beleg->brutto, $vorher->currency, $vorher->contractRef);
            $this->serien->update(
                $serie->id,
                $serie->period,
                Serienerkennung::naechsteFaelligkeit($beleg->datum, $serie->period),
                $serie->toleranceDays,
                $this->verschluesseln($vault, $serie, $daten),
                null,
                $now,
            );
        }
        $this->audit->record(AuditAction::SerieBelegVerknuepft, null, '', $serie->id, ['beleg' => $documentId], $now);
    }

    /**
     * Every receipt must be a checked receipt of the series' supplier and
     * direction, in no other series.
     *
     * @param list<int> $belege
     *
     * @throws InvoiceRuleViolation
     */
    private function pruefeBelege(RecurringSeriesRecord $record, array $belege): void
    {
        $erlaubt = array_map(static fn(InvoiceRecord $r): int => $r->id, $this->invoices->geprueftFuerLieferant($record->supplierId, $record->direction));
        $eigene = $this->serien->mitglieder($record->id);
        $zuordnung = $this->serien->serienVon($belege);
        foreach ($belege as $invoiceId) {
            $schonDrin = in_array($invoiceId, $eigene, true);
            if (!$schonDrin && (!in_array($invoiceId, $erlaubt, true) || isset($zuordnung[$invoiceId]))) {
                throw new InvoiceRuleViolation('Ein gewählter Beleg passt nicht zu dieser Serie oder gehört schon zu einer anderen. Bitte die Seite neu laden.', 'belege');
            }
        }
    }

    /**
     * What the AI read about recurrence on $document (`recurring.likely`,
     * `recurring.period`) - its newest run.
     *
     * @return array{0: bool, 1: ?SeriesPeriod}
     */
    private function kiHinweis(Document $document, Vault $vault): array
    {
        $wiederkehrend = $this->kiAuslesung->auszug($document, $vault)?->extraktion['recurring'] ?? null;
        if (!is_array($wiederkehrend) || ($wiederkehrend['likely'] ?? null) !== true) {
            return [false, null];
        }
        $periode = is_string($wiederkehrend['period'] ?? null) ? SeriesPeriod::tryFrom($wiederkehrend['period']) : null;

        return [true, $periode === SeriesPeriod::Unregelmaessig ? null : $periode];
    }

    /** The amount tolerance of detection in percent (setting, default 15). */
    private function toleranzProzent(): int
    {
        $wert = $this->settings->get(Serienerkennung::SETTING_TOLERANZ, (string) Serienerkennung::TOLERANZ_PROZENT_STANDARD);

        return preg_match('/^\d{1,3}$/', $wert) === 1 && (int) $wert <= 100 ? (int) $wert : Serienerkennung::TOLERANZ_PROZENT_STANDARD;
    }

    private function posten(Vault $vault, InvoiceRecord $record): SerienPosten
    {
        $daten = Pruefung::entschluesseln($vault, $record);

        return new SerienPosten($record->id, $record->documentId, $record->invoiceDate, $daten->gross, $daten->currency, $record->checkedAt !== null);
    }

    private function lieferantName(Vault $vault, int $id): string
    {
        $record = $this->lieferantenRepository->find($id);

        return $record === null ? '?' : $this->lieferanten->entschluesseln($vault, $record)->data->name;
    }

    private function daten(Vault $vault, RecurringSeriesRecord $record): SeriesData
    {
        $json = FieldCipher::decrypt(
            $vault->openDataKey($record->dekSealed),
            $record->dataEnc,
            new FieldContext(self::TABELLE, $record->id, self::SPALTE),
        );
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return SeriesData::fromPayload(is_array($payload) ? $payload : []);
    }

    /** The row keeps its data key. */
    private function verschluesseln(Vault $vault, RecurringSeriesRecord $record, SeriesData $daten): string
    {
        return self::verschluesselnMit($vault->openDataKey($record->dekSealed), $record->id, $daten);
    }

    private static function verschluesselnMit(DataKey $key, int $id, SeriesData $daten): string
    {
        return FieldCipher::encrypt(
            $key,
            json_encode($daten->toPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            new FieldContext(self::TABELLE, $id, self::SPALTE),
        );
    }

    /** A date `JJJJ-MM-TT`, null for empty, false when invalid. */
    private static function datum(string $wert): \DateTimeImmutable|false|null
    {
        if ($wert === '') {
            return null;
        }
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $datum === false || $datum->format('Y-m-d') !== $wert ? false : $datum;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function sortiert(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
