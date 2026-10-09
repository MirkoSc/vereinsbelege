<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Domain\Blob;
use App\Domain\Supplier;
use App\Domain\Zugriffsbereich;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SettingRepository;
use App\Service\Crypto\Vault;
use App\Service\Invoice\Pruefung;
use App\Service\MasterData\SupplierService;
use App\Service\Storage\BlobException;
use App\Service\Storage\BlobService;
use App\Service\Upload\MagicBytes;
use App\Support\FileLogger;

/**
 * The ZIP export of the receipts (issue #76/M12-2,
 * docs/spec/05-auswertung-und-export.md section 2) in two steps, both in a
 * session with the unlocked vault (CLAUDE.md section 4):
 *
 * - vorbereiten() reads the matching receipts (SQL filters on plaintext
 *   structure, App\Repository\InvoiceRepository::exportListe()), decrypts
 *   what the path pattern and index.csv need, and hands out every path in
 *   a fixed order (receipt date, id) - the same filter gives the same names
 *   on every run. Nothing is read from a file yet.
 * - strom() writes the ZIP as a stream: index.csv first, then every file
 *   decrypted chunk by chunk straight into App\Service\Export\ZipStrom.
 *   No temp file, no plaintext anywhere but in the response.
 *
 * Per receipt the working PDF (`document.pdf_blob_id`) goes into the main
 * tree; a receipt without a usable one (mixed uploads, several PDFs, a PDF
 * that was never finished) contributes its originals there instead. With
 * the option "Originale zusätzlich" every original also goes below
 * `_Originale/`, same structure. Receipts not checked yet go below
 * `_Wiedervorlage/` (App\Service\Export\ExportStatus). A file missing from
 * the store is left out before the first byte - in index.csv it reads
 * "(Datei fehlt)" - so one lost file never breaks the whole ZIP.
 *
 * "bezahlt am", "Konto", "Referenz" and `{kasse}` stay empty until receipts
 * are matched to payments (M10-1, issue #65).
 */
final readonly class ZipExport
{
    /**
     * Plaintext bytes one export may hold. ZipStrom writes no ZIP64 and
     * stops at 4 GiB; the rest is room for headers and deflate's growth.
     */
    public const int MAX_GROESSE = 3 * 1024 * 1024 * 1024;

    /** Files one export may hold - below ZipStrom::MAX_EINTRAEGE. */
    public const int MAX_DATEIEN = 65_000;

    public const string ORDNER_ORIGINALE = '_Originale';

    public const string ORDNER_WIEDERVORLAGE = '_Wiedervorlage';

    /** index.csv, columns as in 05 section 2. */
    public const array KOPF = [
        'Datum', 'Richtung', 'Lieferant', 'Rechnungsnr.', 'Brutto', 'Kategorie', 'Kostenstelle',
        'Status', 'bezahlt am', 'Konto', 'Referenz', 'Datei',
    ];

    /** "Datei" entry of a main-tree file that could not be exported. */
    public const string DATEI_FEHLT = '(Datei fehlt)';

    /** Deflate level of the receipts: PDFs and JPEGs are compressed already. */
    private const int STUFE_DATEI = 1;

    private const int STUFE_TEXT = 6;

    /**
     * @param int $sekundenJeDatei restarts the execution time limit before
     *        every file (set_time_limit()), so a long export never hits it
     *        while no single file takes long; 0 leaves the limit alone (the
     *        tests - set_time_limit() would bound the whole test run)
     */
    public function __construct(
        private InvoiceRepository $belege,
        private BlobService $blobs,
        private SupplierService $lieferanten,
        private CategoryRepository $kategorien,
        private CostCenterRepository $kostenstellen,
        private SettingRepository $settings,
        private ?FileLogger $logger = null,
        private int $sekundenJeDatei = 0,
    ) {
    }

    public function vorbereiten(ExportFilter $filter, Zugriffsbereich $bereich, Vault $vault): ExportPlan
    {
        $muster = ExportEinstellungen::fromSettings($this->settings)->muster;
        $wurzel = ExportEinstellungen::wurzelordner($filter->von, $filter->bis);
        $vergabe = new PfadVergabe();
        // First, so no receipt can take the name.
        $indexPfad = $vergabe->vergeben([$wurzel, 'index'], 'csv');

        $kategorien = [];
        foreach ($this->kategorien->all() as $kategorie) {
            $kategorien[$kategorie->id] = $kategorie->name;
        }
        $kostenstellen = [];
        foreach ($this->kostenstellen->all() as $kostenstelle) {
            $kostenstellen[$kostenstelle->id] = $kostenstelle->name;
        }
        $lieferanten = [];
        foreach ($this->lieferanten->liste($vault) as $lieferant) {
            $lieferanten[$lieferant->id] = $lieferant->data->name;
        }

        $csv = Csv::BOM . Csv::zeile(self::KOPF);
        $eintraege = [];
        $groesse = 0;
        $fehlend = 0;
        $anzahl = 0;

        foreach ($this->belege->exportListe($filter, $bereich) as $beleg) {
            $anzahl++;
            $invoice = $beleg->invoice;
            $daten = Pruefung::entschluesseln($vault, $invoice);

            $lieferant = null;
            if ($invoice->supplierId !== null) {
                // liste() leaves out suppliers merged into another; a locked
                // receipt may still point at one (issue #39/M6-5).
                $lieferanten[$invoice->supplierId] ??= $this->lieferanten->finde($vault, $invoice->supplierId)?->data->name ?? '';
                $lieferant = $lieferanten[$invoice->supplierId];
            }
            $kategorie = $invoice->categoryId === null ? null : ($kategorien[$invoice->categoryId] ?? null);

            $segmente = $muster->aufloesen(new BelegPfadDaten(
                datum: $invoice->invoiceDate,
                richtung: $invoice->direction,
                lieferant: $lieferant,
                kategorie: $kategorie,
                nr: $daten->invoiceNumber,
                betragCent: $daten->gross,
                waehrung: $daten->currency,
                // Paid in cash is known once receipts are matched to
                // payments (M10-1, issue #65).
                kasse: false,
            ));
            $vorne = ExportStatus::ungeprueft($beleg->status) ? [self::ORDNER_WIEDERVORLAGE] : [];

            // The working PDF, or - without a usable one - the originals.
            $pdf = $beleg->pdfBlobId === null ? null : $this->datei($beleg->pdfBlobId, null);
            $hauptdateien = $pdf !== null ? [$beleg->pdfBlobId => $pdf] : $this->dateien($beleg->originalBlobIds, $vault);
            $imIndex = [];
            foreach ($hauptdateien as $blobId => $datei) {
                if ($datei === null) {
                    $fehlend++;
                    $imIndex[] = self::DATEI_FEHLT;
                    continue;
                }
                $pfad = $vergabe->vergeben([$wurzel, ...$vorne, ...$segmente], $datei['endung']);
                $eintraege[] = ['pfad' => $pfad, 'blobId' => $blobId, 'stufe' => self::STUFE_DATEI];
                $groesse += $datei['groesse'];
                $imIndex[] = substr($pfad, strlen($wurzel) + 1);
            }

            if ($filter->originale) {
                foreach ($this->dateien($beleg->originalBlobIds, $vault) as $blobId => $datei) {
                    if ($datei === null) {
                        $fehlend++;
                        continue;
                    }
                    $pfad = $vergabe->vergeben([$wurzel, self::ORDNER_ORIGINALE, ...$vorne, ...$segmente], $datei['endung']);
                    $eintraege[] = ['pfad' => $pfad, 'blobId' => $blobId, 'stufe' => self::STUFE_DATEI];
                    $groesse += $datei['groesse'];
                }
            }

            $csv .= Csv::zeile([
                $invoice->invoiceDate->format('d.m.Y'),
                $invoice->direction->label(),
                Csv::text($lieferant ?? ''),
                Csv::text($daten->invoiceNumber),
                PfadMuster::betrag($daten->gross, $daten->currency),
                Csv::text($kategorie ?? ''),
                Csv::text($invoice->costCenterId === null ? '' : ($kostenstellen[$invoice->costCenterId] ?? '')),
                $beleg->status->bezeichnung(),
                '',
                '',
                '',
                // "|" cannot occur in a name (App\Service\Export\Dateiname).
                // The formula guard holds here too: a path that begins with
                // "-" or "+" (a pattern starting with {betrag}) gets the
                // apostrophe as well - safety before an exact match.
                Csv::text($imIndex === [] ? self::DATEI_FEHLT : implode(' | ', $imIndex)),
            ]);
        }

        return new ExportPlan($wurzel, $indexPfad, $csv, $eintraege, $anzahl, $groesse, $fehlend);
    }

    /**
     * The suppliers the filter offers, decrypted and sorted by name: only
     * those of receipts within $bereich (InvoiceRepository::
     * lieferantenImBereich()).
     *
     * @return list<Supplier>
     */
    public function lieferantenAuswahl(Zugriffsbereich $bereich, Vault $vault): array
    {
        $ids = array_flip($this->belege->lieferantenImBereich($bereich));

        return array_values(array_filter(
            $this->lieferanten->liste($vault),
            static fn(Supplier $lieferant): bool => isset($ids[$lieferant->id]),
        ));
    }

    /**
     * The ZIP, piece by piece. A file that turns out unreadable halfway (a
     * damaged blob, a failed decryption) ends the stream without the
     * central directory: the download is then recognisably broken instead
     * of quietly incomplete. The log gets the class and message only - no
     * path, no name (CLAUDE.md section 4).
     *
     * @param \DateTimeImmutable $jetzt modification time of the entries
     *
     * @return \Generator<int, string>
     */
    public function strom(ExportPlan $plan, Vault $vault, \DateTimeImmutable $jetzt): \Generator
    {
        $zip = new ZipStrom($jetzt);

        try {
            yield from $zip->datei($plan->indexPfad, [$plan->indexCsv], self::STUFE_TEXT);

            foreach ($plan->eintraege as $eintrag) {
                $this->zeitlimit();
                $blob = $this->blobs->find($eintrag['blobId']);
                if ($blob === null) {
                    throw new \RuntimeException(sprintf('Blob %d disappeared during the export.', $eintrag['blobId']));
                }
                yield from $zip->datei($eintrag['pfad'], $this->blobs->openRead($blob, $vault), $eintrag['stufe']);
            }

            yield $zip->abschluss();
        } catch (\Throwable $e) {
            $this->logger?->append(sprintf('[%s] ZIP export aborted: %s: %s', date('c'), $e::class, $e->getMessage()));
        }
    }

    /**
     * A file the export can take, with its extension and plaintext size -
     * null when the blob is missing or was never finished.
     *
     * @param Vault|null $vault to read the type from the blob's metadata;
     *        null for the working PDF, which is always a PDF
     *
     * @return array{endung: string, groesse: int}|null
     */
    private function datei(int $blobId, ?Vault $vault): ?array
    {
        $blob = $this->blobs->find($blobId);
        // The row alone is not enough: a file lost from shared/var/blobs/
        // would otherwise only show halfway through the stream and break
        // the whole ZIP.
        if ($blob === null || !$blob->isComplete() || !$this->vorhanden($blob)) {
            return null;
        }
        $mime = $vault === null ? MagicBytes::PDF : $this->blobs->meta($blob, $vault)?->mimeType;

        return [
            'endung' => match ($mime) {
                MagicBytes::PDF => 'pdf',
                MagicBytes::JPEG => 'jpg',
                MagicBytes::PNG => 'png',
                default => 'bin',
            },
            'groesse' => $blob->size,
        ];
    }

    /**
     * @param list<int> $blobIds
     *
     * @return array<int, array{endung: string, groesse: int}|null> by blob id, in order
     */
    private function dateien(array $blobIds, Vault $vault): array
    {
        $dateien = [];
        foreach ($blobIds as $blobId) {
            $dateien[$blobId] = $this->datei($blobId, $vault);
        }

        return $dateien;
    }

    private function vorhanden(Blob $blob): bool
    {
        try {
            return $this->blobs->exists($blob);
        } catch (BlobException) {
            return false;
        }
    }

    private function zeitlimit(): void
    {
        if ($this->sekundenJeDatei > 0 && function_exists('set_time_limit')) {
            set_time_limit($this->sekundenJeDatei);
        }
    }
}
