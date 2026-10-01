<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\ArtifactKind;
use App\Domain\AuditAction;
use App\Domain\Document;
use App\Domain\DocumentArtifact;
use App\Domain\DocumentStatus;
use App\Domain\InboxItem;
use App\Domain\InvoiceData;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceRecord;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
use App\Domain\Supplier;
use App\Domain\Zugriffsbereich;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SupplierRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Inbox\Posteingang;
use App\Service\MasterData\SupplierRuleViolation;
use App\Service\MasterData\SupplierService;
use App\Service\Processing\Betrag;
use App\Service\Upload\MagicBytes;

/**
 * The review page (issue #37/M6-3, docs/spec/03-erfassung-und-ki.md
 * section 6 "Prüfansicht", docs/spec/02-datenmodell.md "Fachdaten"): a
 * person captures a document as a receipt - completely by hand for now, the
 * AI only prefills the same form later (M7-5).
 *
 * - The queue is every document that can reach `in_pruefung`
 *   (App\Domain\DocumentStatus::pruefbare()), oldest first, narrowed to the
 *   viewer's scope in SQL.
 * - Saving writes `invoice` (vault data in `data_enc`, AAD
 *   "invoice|<id>|data_enc", structure in plaintext) and moves the document
 *   to `in_pruefung`; "Geprüft, nächster" also moves it on to `geprueft`.
 *   Both inside one transaction that first locks the document in the status
 *   it was read in - two people saving at once do not both win.
 * - The cost center of the receipt is the document's too: the scope
 *   "eigene Kostenstelle" filters on `document.cost_center_id`.
 * - A supplier or payer can be created right from the form (name, optionally
 *   an IBAN) through App\Service\MasterData\SupplierService - only for whom
 *   may maintain suppliers, and before the receipt's transaction (the
 *   supplier service runs its own).
 * - The sum check (net + taxes ≈ gross) warns, it does not refuse.
 *
 * Framework-free like the other services: no Http, no Session. Nothing
 * decrypted leaves through an exception message or the audit log - the log
 * keeps the names of the changed fields only (docs/spec/01-sicherheit.md
 * section 6).
 */
final readonly class Pruefung
{
    /** Rows of the queue page. */
    public const int WARTESCHLANGE = 200;

    public const int NUMMER_MAX = 100;

    public const int ZWECK_MAX = 200;

    public const int NOTIZ_MAX = 2000;

    /** Tax lines of one receipt: 19 %, 7 % and one more is plenty. */
    public const int STEUERN_MAX = 3;

    private const string TABLE = 'invoice';

    private const string COLUMN = 'data_enc';

    private const string NUMMER_ZWECK = 'invoice.number';

    public function __construct(
        private \PDO $pdo,
        private DocumentRepository $documents,
        private InvoiceRepository $invoices,
        private CategoryRepository $kategorien,
        private CostCenterRepository $kostenstellen,
        private SupplierRepository $lieferantenRepository,
        private SupplierService $lieferanten,
        private DocumentArtifactRepository $artefakte,
        private Posteingang $posteingang,
        private AuditLog $audit,
    ) {
    }

    /**
     * @return list<InboxItem>
     */
    public function warteschlange(Zugriffsbereich $bereich): array
    {
        return $this->documents->pruefListe($bereich, self::WARTESCHLANGE);
    }

    /**
     * The checked receipts that wait to be locked (issue #38/M6-4), oldest
     * first, narrowed to the viewer's scope in SQL.
     *
     * @return list<InboxItem>
     */
    public function geprueftListe(Zugriffsbereich $bereich): array
    {
        return $this->documents->geprueftListe($bereich, self::WARTESCHLANGE);
    }

    /**
     * When the receipt was locked - plaintext structure, no vault needed.
     */
    public function festgeschriebenAm(Document $document): ?\DateTimeImmutable
    {
        return $this->invoices->findByDocument($document->id)?->lockedAt;
    }

    /**
     * The document after $nach in the queue, wrapping around to the start -
     * never $nach itself. Null when nothing else is waiting.
     */
    public function naechster(Zugriffsbereich $bereich, int $nach): ?int
    {
        return self::naechsterIn(array_map(static fn(InboxItem $item): int => $item->document->id, $this->warteschlange($bereich)), $nach);
    }

    /**
     * naechster() on a queue already at hand.
     *
     * @param list<int> $ids document ids in queue order
     */
    public static function naechsterIn(array $ids, int $nach): ?int
    {
        $position = array_search($nach, $ids, true);
        $reihe = $position === false ? $ids : [...array_slice($ids, $position + 1), ...array_slice($ids, 0, $position)];

        foreach ($reihe as $id) {
            if ($id !== $nach) {
                return $id;
            }
        }

        return null;
    }

    public function eintrag(int $id, Zugriffsbereich $bereich): ?InboxItem
    {
        return $this->documents->inboxItem($id, $bereich);
    }

    /**
     * The receipt captured so far, or null when the document has none yet.
     */
    public function beleg(Document $document, Vault $vault): ?Invoice
    {
        $record = $this->invoices->findByDocument($document->id);

        return $record === null ? null : new Invoice($record, self::entschluesseln($vault, $record));
    }

    /**
     * The pages to look at while capturing: the images the document came as
     * (the scanner's processed version where there is one), then the page
     * images pdf.js rendered from its PDFs (issue #30/M4-8, the newest run);
     * the PDFs themselves as links - the CSP rules out embedding them.
     *
     * @return array{bilder: list<array{blobId: int, titel: string, originalId: ?int}>, pdfs: list<array{blobId: int, titel: string}>}
     */
    public function seiten(Document $document, Vault $vault): array
    {
        $bilder = [];
        $pdfs = [];
        foreach ($this->posteingang->seiten($document, $vault) as $seite) {
            if ($seite['mime'] === MagicBytes::PDF) {
                $pdfs[] = ['blobId' => $seite['blobId'], 'titel' => $seite['pdf'] ? 'Aufbereitetes PDF' : 'PDF (Seite ' . $seite['seite'] . ')'];
            } else {
                $bilder[] = ['blobId' => $seite['blobId'], 'titel' => 'Seite ' . $seite['seite'] . ($seite['originalId'] !== null ? ' (aufbereitet)' : ''), 'originalId' => $seite['originalId']];
            }
        }
        foreach ($this->seitenbilder($document) as $i => $blobId) {
            $bilder[] = ['blobId' => $blobId, 'titel' => 'PDF-Seite ' . ($i + 1), 'originalId' => null];
        }

        return ['bilder' => $bilder, 'pdfs' => $pdfs];
    }

    /**
     * One page to stream - the document's own blobs and its page images.
     *
     * @return array{chunks: \Generator<string>, mime: string, dateiname: string}|null
     */
    public function datei(InboxItem $item, int $blobId, Vault $vault): ?array
    {
        return $this->posteingang->datei($item, $blobId, $vault, $this->seitenbilder($item->document));
    }

    /**
     * Every supplier and payer to choose from, sorted by name. The page
     * filters by direction; the service checks it again on save.
     *
     * @return list<Supplier>
     */
    public function lieferanten(Vault $vault): array
    {
        return $this->lieferanten->liste($vault);
    }

    /**
     * The form as the page shows it first: the stored receipt, or for a
     * document without one the defaults - an expense, an invoice, in euros,
     * with the cost center the document came with.
     *
     * @return array<string, string>
     */
    public function felder(Document $document, ?Invoice $beleg): array
    {
        $felder = array_fill_keys(self::feldnamen(), '');
        $felder['belegart'] = InvoiceType::Rechnung->value;
        $felder['richtung'] = InvoiceDirection::Ausgabe->value;
        $felder['waehrung'] = 'EUR';
        $felder['kostenstelle'] = $document->costCenterId === null ? '' : (string) $document->costCenterId;
        if ($beleg === null) {
            return $felder;
        }

        $r = $beleg->record;
        $d = $beleg->data;
        $datum = static fn(?\DateTimeImmutable $wert): string => $wert?->format('Y-m-d') ?? '';
        $id = static fn(?int $wert): string => $wert === null ? '' : (string) $wert;

        $felder = [
            ...$felder,
            'belegart' => $r->docType->value,
            'richtung' => $r->direction->value,
            'datum' => $datum($r->invoiceDate),
            'faellig' => $datum($r->dueDate),
            'leistung_von' => $datum($r->serviceFrom),
            'leistung_bis' => $datum($r->serviceTo),
            // A locked receipt may still point at a supplier merged away
            // since (issue #39/M6-5): it shows - and once unlocked saves -
            // the one it was merged into.
            'lieferant' => $id($r->supplierId === null ? null : $this->lieferantenRepository->aufgeloest($r->supplierId)),
            'kategorie' => $id($r->categoryId),
            'kostenstelle' => $id($r->costCenterId),
            'nummer' => $d->invoiceNumber,
            'brutto' => Betrag::format($d->gross),
            'netto' => $d->net === null ? '' : Betrag::format($d->net),
            'waehrung' => $d->currency,
            'zweck' => $d->purposeShort,
            'notiz' => $d->notes,
        ];
        foreach (array_values($d->taxes) as $i => $steuer) {
            $felder['steuer_satz_' . ($i + 1)] = str_replace('.', ',', $steuer['rate']);
            $felder['steuer_betrag_' . ($i + 1)] = Betrag::format($steuer['amount']);
        }

        return $felder;
    }

    /**
     * Every field of the form, in the order a person tabs through them.
     *
     * @return list<string>
     */
    public static function feldnamen(): array
    {
        $namen = ['richtung', 'belegart', 'datum', 'nummer', 'brutto', 'waehrung', 'netto'];
        for ($i = 1; $i <= self::STEUERN_MAX; $i++) {
            $namen[] = 'steuer_satz_' . $i;
            $namen[] = 'steuer_betrag_' . $i;
        }

        return [...$namen, 'lieferant', 'lieferant_neu_name', 'lieferant_neu_iban', 'kategorie', 'kostenstelle',
            'faellig', 'leistung_von', 'leistung_bis', 'zweck', 'notiz'];
    }

    /**
     * Saves the form and moves the document to `in_pruefung` - with
     * $geprueft on to `geprueft` ("Geprüft, nächster").
     *
     * @param array<string, string> $felder the form, see feldnamen()
     * @param bool $darfLieferantAnlegen whether the account may maintain
     *        suppliers (`supplier.manage`) - only then may the form create one
     *
     * @throws InvoiceRuleViolation
     */
    public function speichern(
        Document $document,
        Vault $vault,
        array $felder,
        bool $geprueft,
        bool $darfLieferantAnlegen,
        ?int $userId,
        string $ip,
        \DateTimeImmutable $now,
    ): PruefErgebnis {
        if ($document->status === DocumentStatus::Festgeschrieben) {
            throw new InvoiceRuleViolation('Dieser Beleg ist festgeschrieben und lässt sich nicht ändern. Zum Korrigieren zuerst die Festschreibung aufheben.');
        }
        if (!$document->status->pruefbar()) {
            throw new InvoiceRuleViolation('Dieser Beleg ist nicht (mehr) in Prüfung und lässt sich hier nicht ändern.');
        }

        $vorher = $this->invoices->findByDocument($document->id);
        $vorherDaten = $vorher === null ? null : self::entschluesseln($vault, $vorher);
        [$struktur, $daten, $warnungen] = $this->pruefe($felder, $document, $vorher);
        $neuerLieferant = $this->neuerLieferant($felder, $struktur, $darfLieferantAnlegen);

        $lieferantAngelegt = false;
        if ($neuerLieferant !== null) {
            $struktur = $this->legeLieferantAn($vault, $neuerLieferant, $struktur, $userId, $ip, $now);
            $lieferantAngelegt = true;
        }

        $numberBi = $daten->invoiceNumber === ''
            ? null
            : $vault->blindIndex()->forValue(self::NUMMER_ZWECK, self::nummerFuerIndex($daten->invoiceNumber));

        $geaendert = [
            ...$struktur->geaendertGegen($vorher === null ? null : InvoiceStructure::aus($vorher)),
            ...self::geaenderteDaten($daten, $vorherDaten),
        ];

        $this->pdo->beginTransaction();
        try {
            if (!$this->documents->sperreImStatus($document->id, $document->status)) {
                throw new InvoiceRuleViolation('Der Beleg wurde inzwischen anders bearbeitet. Bitte die Seite neu laden.');
            }

            if ($vorher === null) {
                $key = DataKey::generate();
                $invoiceId = $this->invoices->insert($document->id, $struktur, $vault->sealDataKey($key), $numberBi, $userId, $now);
                $this->invoices->setData($invoiceId, self::verschluesseln($key, $invoiceId, $daten));
            } else {
                // The row keeps its data key, like a supplier does.
                $invoiceId = $vorher->id;
                $key = $vault->openDataKey($vorher->dekSealed);
                if ($geaendert !== []) {
                    $this->invoices->update($invoiceId, $struktur, self::verschluesseln($key, $invoiceId, $daten), $numberBi, $userId, $now);
                }
            }

            if ($struktur->costCenterId !== $document->costCenterId) {
                $this->documents->setzeKostenstelle($document->id, $struktur->costCenterId);
            }

            $status = $document->status;
            if ($status !== DocumentStatus::InPruefung) {
                $this->wechsle($document->id, $status, DocumentStatus::InPruefung, $userId, $now);
            }
            if ($vorher === null || $geaendert !== [] || $status !== DocumentStatus::InPruefung) {
                $details = ['felder' => $geaendert];
                if ($status !== DocumentStatus::InPruefung) {
                    $details['von'] = $status->value;
                }
                $this->audit->record(AuditAction::BelegBearbeitet, $userId, $ip, $document->id, $details, $now);
            }

            if ($geprueft) {
                $this->wechsle($document->id, DocumentStatus::InPruefung, DocumentStatus::Geprueft, $userId, $now);
                $this->invoices->setzeGeprueft($invoiceId, $userId, $now);
                $this->audit->record(AuditAction::BelegGeprueft, $userId, $ip, $document->id, [], $now);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return new PruefErgebnis($invoiceId, $geprueft, $warnungen, $lieferantAngelegt);
    }

    /**
     * What goes into the blind index of an invoice number: spaces do not
     * make two numbers different ("RE 2026-01" = "RE2026-01"); case is
     * already folded by App\Service\Crypto\BlindIndex.
     */
    public static function nummerFuerIndex(string $nummer): string
    {
        return preg_replace('/\s+/u', '', $nummer) ?? $nummer;
    }

    /**
     * @throws InvoiceRuleViolation
     */
    private function wechsle(int $id, DocumentStatus $von, DocumentStatus $nach, ?int $userId, \DateTimeImmutable $now): void
    {
        if (!$von->kannWechselnZu($nach) || !$this->documents->wechsleStatus($id, $von, $nach, null, null, $userId, $now)) {
            throw new InvoiceRuleViolation('Der Beleg wurde inzwischen anders bearbeitet. Bitte die Seite neu laden.');
        }
    }

    /**
     * @param array<string, string> $felder
     *
     * @return array{InvoiceStructure, InvoiceData, list<string>}
     *
     * @throws InvoiceRuleViolation
     */
    private function pruefe(array $felder, Document $document, ?InvoiceRecord $vorher): array
    {
        $feld = static fn(string $name): string => trim($felder[$name] ?? '');

        $richtung = InvoiceDirection::tryFrom($feld('richtung'))
            ?? throw new InvoiceRuleViolation('Bitte wählen, ob es eine Ausgabe oder eine Einnahme ist.', 'richtung');
        $art = InvoiceType::tryFrom($feld('belegart'))
            ?? throw new InvoiceRuleViolation('Bitte die Belegart wählen.', 'belegart');

        $datum = self::datum($feld('datum'), 'datum', 'Das Belegdatum')
            ?? throw new InvoiceRuleViolation('Bitte das Belegdatum angeben.', 'datum');
        $faellig = self::datum($feld('faellig'), 'faellig', 'Das Fälligkeitsdatum');
        $von = self::datum($feld('leistung_von'), 'leistung_von', 'Der Leistungsbeginn');
        $bis = self::datum($feld('leistung_bis'), 'leistung_bis', 'Das Leistungsende');
        if ($von !== null && $bis !== null && $von > $bis) {
            throw new InvoiceRuleViolation('Der Leistungszeitraum endet vor seinem Beginn.', 'leistung_bis');
        }

        $nummer = $feld('nummer');
        self::laenge($nummer, self::NUMMER_MAX, 'Die Belegnummer', 'nummer');

        if ($feld('brutto') === '') {
            throw new InvoiceRuleViolation('Bitte den Bruttobetrag angeben.', 'brutto');
        }
        $brutto = self::betrag($feld('brutto'), 'brutto', 'Den Bruttobetrag');
        $netto = $feld('netto') === '' ? null : self::betrag($feld('netto'), 'netto', 'Den Nettobetrag');

        $steuern = [];
        for ($i = 1; $i <= self::STEUERN_MAX; $i++) {
            $satz = $feld('steuer_satz_' . $i);
            $betrag = $feld('steuer_betrag_' . $i);
            if ($satz === '' && $betrag === '') {
                continue;
            }
            if ($satz === '' || $betrag === '') {
                throw new InvoiceRuleViolation(sprintf('Bitte in der %d. Steuerzeile Satz und Betrag angeben.', $i), $satz === '' ? 'steuer_satz_' . $i : 'steuer_betrag_' . $i);
            }
            $rate = Betrag::prozent($satz)
                ?? throw new InvoiceRuleViolation(sprintf('Der Steuersatz in der %d. Zeile ist ungültig (z. B. 19 oder 7).', $i), 'steuer_satz_' . $i);
            $steuern[] = ['rate' => $rate, 'amount' => self::betrag($betrag, 'steuer_betrag_' . $i, sprintf('Den Steuerbetrag in der %d. Zeile', $i))];
        }

        $waehrung = strtoupper($feld('waehrung'));
        if ($waehrung === '') {
            $waehrung = 'EUR';
        }
        if (preg_match('/^[A-Z]{3}$/', $waehrung) !== 1) {
            throw new InvoiceRuleViolation('Die Währung ist ein Code aus drei Buchstaben, z. B. EUR.', 'waehrung');
        }

        $zweck = $feld('zweck');
        self::laenge($zweck, self::ZWECK_MAX, 'Der Zweck', 'zweck');
        $notiz = $feld('notiz');
        self::laenge($notiz, self::NOTIZ_MAX, 'Die Notiz', 'notiz');

        $struktur = new InvoiceStructure(
            docType: $art,
            direction: $richtung,
            supplierId: $this->lieferant($feld('lieferant'), $richtung),
            invoiceDate: $datum,
            dueDate: $faellig,
            serviceFrom: $von,
            serviceTo: $bis,
            categoryId: $this->kategorie($feld('kategorie'), $richtung, $vorher?->categoryId),
            costCenterId: $this->kostenstelle($feld('kostenstelle'), [$vorher?->costCenterId, $document->costCenterId]),
        );

        $warnungen = [];
        if (!Betrag::summePasst($netto, array_column($steuern, 'amount'), $brutto)) {
            $warnungen[] = 'Netto und Steuern ergeben nicht den Bruttobetrag – bitte die Beträge prüfen.';
        }

        return [$struktur, new InvoiceData($nummer, $brutto, $netto, $steuern, $waehrung, $zweck, $notiz), $warnungen];
    }

    /**
     * @throws InvoiceRuleViolation
     */
    private function lieferant(string $wert, InvoiceDirection $richtung): ?int
    {
        if ($wert === '') {
            return null;
        }

        $lieferant = ctype_digit($wert) ? $this->lieferantenRepository->find((int) $wert) : null;
        if ($lieferant === null || $lieferant->mergedInto !== null) {
            throw new InvoiceRuleViolation(sprintf('Diesen %s gibt es nicht (mehr).', $richtung->partner()), 'lieferant');
        }
        if (!$richtung->passtZuLieferant($lieferant->role)) {
            throw new InvoiceRuleViolation(
                sprintf('Der gewählte Partner ist als %s hinterlegt und passt nicht zu einer %s.', $lieferant->role->label(), $richtung->label()),
                'lieferant',
            );
        }

        return $lieferant->id;
    }

    /**
     * @throws InvoiceRuleViolation
     */
    private function kategorie(string $wert, InvoiceDirection $richtung, ?int $bisher): ?int
    {
        if ($wert === '') {
            return null;
        }

        $kategorie = ctype_digit($wert) ? $this->kategorien->find((int) $wert) : null;
        if ($kategorie === null) {
            throw new InvoiceRuleViolation('Diese Kategorie gibt es nicht.', 'kategorie');
        }
        if (!$kategorie->active && $kategorie->id !== $bisher) {
            throw new InvoiceRuleViolation('Diese Kategorie ist deaktiviert.', 'kategorie');
        }
        if (!$richtung->passtZuKategorie($kategorie->direction)) {
            throw new InvoiceRuleViolation(
                sprintf('Die Kategorie „%s“ ist keine %s-Kategorie.', $kategorie->name, $richtung->label()),
                'kategorie',
            );
        }

        return $kategorie->id;
    }

    /**
     * An inactive cost center is accepted only if the receipt or its
     * document already carries it (saving the form unchanged).
     *
     * @param list<int|null> $bisher
     *
     * @throws InvoiceRuleViolation
     */
    private function kostenstelle(string $wert, array $bisher): ?int
    {
        if ($wert === '') {
            return null;
        }

        $id = ctype_digit($wert) ? (int) $wert : 0;
        if (!isset($this->kostenstellen->active()[$id]) && !in_array($id, $bisher, true)) {
            throw new InvoiceRuleViolation('Unbekannte oder deaktivierte Kostenstelle.', 'kostenstelle');
        }

        return $id;
    }

    /**
     * The partner to create from the form, or null when none is asked for.
     *
     * @param array<string, string> $felder
     *
     * @return array{name: string, iban: string}|null
     *
     * @throws InvoiceRuleViolation
     */
    private function neuerLieferant(array $felder, InvoiceStructure $struktur, bool $darf): ?array
    {
        $name = trim($felder['lieferant_neu_name'] ?? '');
        $iban = trim($felder['lieferant_neu_iban'] ?? '');
        if ($name === '') {
            if ($iban !== '') {
                throw new InvoiceRuleViolation(sprintf('Für einen neuen %s bitte auch den Namen angeben.', $struktur->direction->partner()), 'lieferant_neu_name');
            }

            return null;
        }
        if ($struktur->supplierId !== null) {
            throw new InvoiceRuleViolation(
                sprintf('Bitte entweder einen %s auswählen oder einen neuen anlegen, nicht beides.', $struktur->direction->partner()),
                'lieferant_neu_name',
            );
        }
        if (!$darf) {
            throw new InvoiceRuleViolation('Neue Lieferanten und Zahler darf nur anlegen, wer Lieferanten pflegen darf.', 'lieferant_neu_name');
        }

        return ['name' => $name, 'iban' => $iban];
    }

    /**
     * @param array{name: string, iban: string} $neu
     *
     * @throws InvoiceRuleViolation
     */
    private function legeLieferantAn(Vault $vault, array $neu, InvoiceStructure $struktur, ?int $userId, string $ip, \DateTimeImmutable $now): InvoiceStructure
    {
        try {
            $ergebnis = $this->lieferanten->anlegen($vault, [
                'name' => $neu['name'],
                'rolle' => $struktur->direction->neueLieferantenRolle()->value,
                'ibans' => $neu['iban'],
            ], $now);
        } catch (SupplierRuleViolation $e) {
            throw new InvoiceRuleViolation($e->getMessage(), 'lieferant_neu_name', $e->konfliktId);
        }

        $this->audit->record(AuditAction::LieferantAngelegt, $userId, $ip, $ergebnis->id, ['felder' => $ergebnis->geaenderteFelder], $now);

        return new InvoiceStructure(
            docType: $struktur->docType,
            direction: $struktur->direction,
            supplierId: $ergebnis->id,
            invoiceDate: $struktur->invoiceDate,
            dueDate: $struktur->dueDate,
            serviceFrom: $struktur->serviceFrom,
            serviceTo: $struktur->serviceTo,
            categoryId: $struktur->categoryId,
            costCenterId: $struktur->costCenterId,
        );
    }

    /**
     * The keys of `data_enc` whose value changed - all of them that carry a
     * value for a new receipt.
     *
     * @return list<string>
     */
    private static function geaenderteDaten(InvoiceData $daten, ?InvoiceData $vorher): array
    {
        $neu = $daten->toPayload();
        if ($vorher === null) {
            return array_keys(array_filter($neu, static fn(mixed $wert): bool => $wert !== '' && $wert !== null && $wert !== []));
        }
        $alt = $vorher->toPayload();

        return array_keys(array_filter($neu, static fn(mixed $wert, string $feld): bool => $wert !== $alt[$feld], ARRAY_FILTER_USE_BOTH));
    }

    /**
     * The blob ids of the newest rendering run's page images, in page
     * order.
     *
     * @return list<int>
     */
    private function seitenbilder(Document $document): array
    {
        $artefakte = $this->artefakte->fuerDokument($document->id, ArtifactKind::PageImage);
        $lauf = max([0, ...array_map(static fn(DocumentArtifact $a): int => $a->jobId ?? 0, $artefakte)]);

        $ids = [];
        foreach ($artefakte as $artefakt) {
            if (($artefakt->jobId ?? 0) === $lauf && $artefakt->blobId !== null) {
                $ids[] = $artefakt->blobId;
            }
        }

        return $ids;
    }

    private static function entschluesseln(Vault $vault, InvoiceRecord $record): InvoiceData
    {
        $json = FieldCipher::decrypt(
            $vault->openDataKey($record->dekSealed),
            $record->dataEnc,
            new FieldContext(self::TABLE, $record->id, self::COLUMN),
        );
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return InvoiceData::fromPayload(is_array($payload) ? $payload : []);
    }

    private static function verschluesseln(DataKey $key, int $id, InvoiceData $daten): string
    {
        return FieldCipher::encrypt(
            $key,
            json_encode($daten->toPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            new FieldContext(self::TABLE, $id, self::COLUMN),
        );
    }

    /**
     * @throws InvoiceRuleViolation
     */
    private static function datum(string $wert, string $feld, string $was): ?\DateTimeImmutable
    {
        if ($wert === '') {
            return null;
        }
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);
        if ($datum === false || $datum->format('Y-m-d') !== $wert) {
            throw new InvoiceRuleViolation(sprintf('%s ist kein gültiges Datum.', $was), $feld);
        }

        return $datum;
    }

    /**
     * @throws InvoiceRuleViolation
     */
    private static function betrag(string $wert, string $feld, string $was): int
    {
        return Betrag::parse($wert)
            ?? throw new InvoiceRuleViolation(sprintf('%s bitte als Betrag wie 12,34 angeben (höchstens zwei Nachkommastellen).', $was), $feld);
    }

    /**
     * @throws InvoiceRuleViolation
     */
    private static function laenge(string $wert, int $max, string $was, string $feld): void
    {
        if (mb_strlen($wert) > $max) {
            throw new InvoiceRuleViolation(sprintf('%s darf höchstens %d Zeichen lang sein.', $was, $max), $feld);
        }
    }
}
