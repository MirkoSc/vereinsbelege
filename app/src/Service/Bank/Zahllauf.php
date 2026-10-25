<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\PaymentStatus;
use App\Domain\ZahllaufRecord;
use App\Domain\ZahllaufStatus;

/**
 * A payment run, decrypted (issue #85, E-42): the stored structure plus
 * what its file says. Exists only in a session with the vault unlocked.
 */
final readonly class Zahllauf
{
    /**
     * @param list<ZahllaufUeberweisung> $ueberweisungen
     * @param array<int, PaymentStatus> $belegStatus invoice id => status, empty in lists
     */
    public function __construct(
        public ZahllaufRecord $record,
        public string $kontoName,
        public string $auftraggeber,
        public string $iban,
        public string $bic,
        public string $msgId,
        public string $pmtInfId,
        public \DateTimeImmutable $erstellt,
        public array $ueberweisungen,
        public array $belegStatus = [],
    ) {
    }

    public function summe(): int
    {
        return array_sum(array_map(static fn(ZahllaufUeberweisung $u): int => $u->cent, $this->ueberweisungen));
    }

    /**
     * Ordered and every receipt settled (`bezahlt`/`erstattet`): nothing
     * of the run is open any more.
     */
    public function ausgeglichen(): bool
    {
        return $this->record->status === ZahllaufStatus::Angewiesen && $this->record->offeneBelege === 0;
    }

    /** Whether the matching has settled every receipt of this transfer. */
    public function ueberweisungAusgeglichen(ZahllaufUeberweisung $u): bool
    {
        foreach ($u->belege as $beleg) {
            $status = $this->belegStatus[$beleg] ?? null;
            if ($status === null || !$status->ausgeglichen()) {
                return false;
            }
        }

        return true;
    }

    /** The badge class of /admin/designsystem for statusText(). */
    public function markeKlasse(): string
    {
        return match (true) {
            $this->record->status === ZahllaufStatus::Verworfen => 'marke-fehler',
            $this->ausgeglichen() => 'marke-ok',
            default => 'marke-warnung',
        };
    }

    /**
     * Discarding frees the receipts - only while nothing of the run has
     * been matched with a booking since it was created.
     */
    public function verwerfbar(): bool
    {
        return $this->record->status === ZahllaufStatus::Angewiesen && $this->record->offeneBelege > 0 && $this->record->zugeordneteBelege === 0;
    }

    public function statusText(): string
    {
        return match (true) {
            $this->record->status === ZahllaufStatus::Verworfen => 'verworfen',
            $this->ausgeglichen() => 'ausgeglichen',
            default => 'angewiesen',
        };
    }
}
