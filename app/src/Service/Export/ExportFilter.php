<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * The filters of the ZIP export /app/export (issue #76/M12-2,
 * docs/spec/05-auswertung-und-export.md section 2): period (default: the
 * current business year = calendar year, E-16), status, category, cost
 * center, supplier, and whether the originals come along. All of it is
 * plaintext structure and filters in SQL
 * (App\Repository\InvoiceRepository::exportListe()) - ids and dates only,
 * so the filter may travel in the page's URL and into the audit log.
 *
 * For category, cost center and supplier, null means "all" and 0 means
 * "none assigned" (`ohne` in the form), as in the booking list.
 */
final readonly class ExportFilter
{
    /** Form value for "none assigned". */
    public const string OHNE = 'ohne';

    public function __construct(
        public \DateTimeImmutable $von,
        public \DateTimeImmutable $bis,
        public ExportStatus $status = ExportStatus::Geprueft,
        public ?int $kategorieId = null,
        public ?int $kostenstelleId = null,
        public ?int $lieferantId = null,
        public bool $originale = false,
    ) {
    }

    /**
     * Reads the page's query string or the download form. Unknown or
     * malformed values fall back to the default rather than being refused -
     * the page shows what was understood before anything is downloaded.
     *
     * @param array<string, mixed> $felder
     */
    public static function ausFeldern(array $felder, \DateTimeImmutable $heute): self
    {
        $text = static fn(string $feld): string => is_string($felder[$feld] ?? null) ? trim($felder[$feld]) : '';
        $auswahl = static function (string $feld) use ($text): ?int {
            $wert = $text($feld);
            if ($wert === self::OHNE) {
                return 0;
            }

            return ctype_digit($wert) && (int) $wert > 0 ? (int) $wert : null;
        };
        $datum = static function (string $feld) use ($text): ?\DateTimeImmutable {
            $wert = $text($feld);
            $tag = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

            return $tag !== false && $tag->format('Y-m-d') === $wert ? $tag : null;
        };
        $jahr = (int) $heute->format('Y');

        return new self(
            von: $datum('von') ?? $heute->setDate($jahr, 1, 1)->setTime(0, 0),
            bis: $datum('bis') ?? $heute->setDate($jahr, 12, 31)->setTime(0, 0),
            status: ExportStatus::tryFrom($text('status')) ?? ExportStatus::Geprueft,
            kategorieId: $auswahl('kategorie'),
            kostenstelleId: $auswahl('kostenstelle'),
            lieferantId: $auswahl('lieferant'),
            originale: $text('originale') === '1',
        );
    }

    /** What is wrong with the filter for a download, or null. */
    public function fehler(): ?string
    {
        return $this->von > $this->bis ? 'Das Von-Datum liegt nach dem Bis-Datum.' : null;
    }

    /**
     * The filter as form fields - for the download form and the links back
     * to the page. Round-trips through ausFeldern().
     *
     * @return array<string, string>
     */
    public function alsFelder(): array
    {
        $auswahl = static fn(?int $id): string => match ($id) {
            null => '',
            0 => self::OHNE,
            default => (string) $id,
        };

        return [
            'von' => $this->von->format('Y-m-d'),
            'bis' => $this->bis->format('Y-m-d'),
            'status' => $this->status->value,
            'kategorie' => $auswahl($this->kategorieId),
            'kostenstelle' => $auswahl($this->kostenstelleId),
            'lieferant' => $auswahl($this->lieferantId),
            'originale' => $this->originale ? '1' : '',
        ];
    }

    /**
     * What the audit log records about the filter (05 section 2: "Filter,
     * Anzahl, Nutzer"): dates, status and ids only, never a name.
     *
     * @return array<string, string|int|bool|null>
     */
    public function auditDetails(): array
    {
        return [
            'von' => $this->von->format('Y-m-d'),
            'bis' => $this->bis->format('Y-m-d'),
            'status' => $this->status->value,
            'kategorie' => $this->kategorieId,
            'kostenstelle' => $this->kostenstelleId,
            'lieferant' => $this->lieferantId,
            'originale' => $this->originale,
        ];
    }
}
