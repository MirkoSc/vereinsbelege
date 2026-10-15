<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The counts of an archive import (`archive_import.stats`, M12-3, issue
 * #77) - numbers and document ids only, never a name, so they may stand in
 * plaintext and in the audit log.
 *
 * - `belege` documents written; `wiedervorlage` and `kasse` of them from such
 *   a folder; `ohneVorbelegung` whose name fitted no pattern;
 * - `lieferantenNeu` suppliers created by the import;
 * - `uebersprungen` files not imported and listed in the report (no PDF or
 *   image, a copy under `_Originale`, a broken entry); `systemdateien`
 *   skipped without a word (`.DS_Store`, `Thumbs.db`); `ordner` folder
 *   entries of the ZIP;
 * - `ersteDocumentId`/`letzteDocumentId` the range the import wrote.
 */
final readonly class ArchiveImportStats
{
    public function __construct(
        public int $belege = 0,
        public int $wiedervorlage = 0,
        public int $kasse = 0,
        public int $ohneVorbelegung = 0,
        public int $lieferantenNeu = 0,
        public int $uebersprungen = 0,
        public int $systemdateien = 0,
        public int $ordner = 0,
        public ?int $ersteDocumentId = null,
        public ?int $letzteDocumentId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $werte
     */
    public static function fromArray(array $werte): self
    {
        $zahl = static fn(string $k): int => is_int($werte[$k] ?? null) ? $werte[$k] : 0;
        $id = static fn(string $k): ?int => is_int($werte[$k] ?? null) ? $werte[$k] : null;

        return new self(
            belege: $zahl('belege'),
            wiedervorlage: $zahl('wiedervorlage'),
            kasse: $zahl('kasse'),
            ohneVorbelegung: $zahl('ohne_vorbelegung'),
            lieferantenNeu: $zahl('lieferanten_neu'),
            uebersprungen: $zahl('uebersprungen'),
            systemdateien: $zahl('systemdateien'),
            ordner: $zahl('ordner'),
            ersteDocumentId: $id('erste_document_id'),
            letzteDocumentId: $id('letzte_document_id'),
        );
    }

    /**
     * @return array{belege: int, wiedervorlage: int, kasse: int, ohne_vorbelegung: int, lieferanten_neu: int, uebersprungen: int, systemdateien: int, ordner: int, erste_document_id: ?int, letzte_document_id: ?int}
     */
    public function toArray(): array
    {
        return [
            'belege' => $this->belege,
            'wiedervorlage' => $this->wiedervorlage,
            'kasse' => $this->kasse,
            'ohne_vorbelegung' => $this->ohneVorbelegung,
            'lieferanten_neu' => $this->lieferantenNeu,
            'uebersprungen' => $this->uebersprungen,
            'systemdateien' => $this->systemdateien,
            'ordner' => $this->ordner,
            'erste_document_id' => $this->ersteDocumentId,
            'letzte_document_id' => $this->letzteDocumentId,
        ];
    }

    /**
     * The counts after one more document.
     */
    public function mitBeleg(int $documentId, bool $wiedervorlage, bool $kasse, bool $ohneVorbelegung, bool $lieferantNeu): self
    {
        return new self(
            belege: $this->belege + 1,
            wiedervorlage: $this->wiedervorlage + ($wiedervorlage ? 1 : 0),
            kasse: $this->kasse + ($kasse ? 1 : 0),
            ohneVorbelegung: $this->ohneVorbelegung + ($ohneVorbelegung ? 1 : 0),
            lieferantenNeu: $this->lieferantenNeu + ($lieferantNeu ? 1 : 0),
            uebersprungen: $this->uebersprungen,
            systemdateien: $this->systemdateien,
            ordner: $this->ordner,
            ersteDocumentId: $this->ersteDocumentId ?? $documentId,
            letzteDocumentId: $documentId,
        );
    }

    /**
     * The counts after one more entry that became no document.
     */
    public function mitAuslassung(bool $ordner, bool $systemdatei): self
    {
        return new self(
            belege: $this->belege,
            wiedervorlage: $this->wiedervorlage,
            kasse: $this->kasse,
            ohneVorbelegung: $this->ohneVorbelegung,
            lieferantenNeu: $this->lieferantenNeu,
            uebersprungen: $this->uebersprungen + (!$ordner && !$systemdatei ? 1 : 0),
            systemdateien: $this->systemdateien + ($systemdatei ? 1 : 0),
            ordner: $this->ordner + ($ordner ? 1 : 0),
            ersteDocumentId: $this->ersteDocumentId,
            letzteDocumentId: $this->letzteDocumentId,
        );
    }
}
