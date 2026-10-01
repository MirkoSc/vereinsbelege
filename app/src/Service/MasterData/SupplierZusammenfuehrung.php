<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\AuditAction;
use App\Domain\Supplier;
use App\Domain\SupplierRecord;
use App\Repository\InvoiceRepository;
use App\Repository\SupplierRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\Vault;

/**
 * Merging a duplicate supplier into another (M6-5, issue #39,
 * docs/spec/03-erfassung-und-ki.md section 7, docs/spec/02-datenmodell.md
 * "Lieferanten").
 *
 * The source disappears from every list, the target keeps its id and gains
 * the source's names, IBANs and the rest (App\Service\MasterData\
 * SupplierMerge). Every receipt of the source moves to the target - except
 * the locked ones (docs/spec/01-sicherheit.md section 7): those are
 * immutable and keep pointing at the source, whose row therefore stays,
 * with `merged_into` set. Whoever shows or evaluates a receipt resolves
 * the supplier through it (SupplierRepository::aufgeloest()).
 *
 * One transaction: both suppliers under a row lock, decrypted and planned
 * again inside it (the preview may be stale), the target rewritten with its
 * keys, the receipts moved, the source marked, the audit entry written. If
 * any of it fails, nothing happened.
 *
 * Needs the unlocked vault - the data is decrypted and re-encrypted, and the
 * blind indexes of the target are keyed from the vault.
 */
final readonly class SupplierZusammenfuehrung
{
    public function __construct(
        private \PDO $pdo,
        private SupplierRepository $lieferanten,
        private InvoiceRepository $belege,
        private SupplierService $service,
        private AuditLog $audit,
    ) {
    }

    /**
     * What the merge would do, without doing it.
     *
     * @throws SupplierRuleViolation when it cannot be done
     */
    public function vorschau(Vault $vault, int $quelleId, int $zielId): SupplierMergeVorschau
    {
        self::nichtSichSelbst($quelleId, $zielId);
        $quelle = $this->service->entschluesseln($vault, $this->record($quelleId, false));
        $ziel = $this->service->entschluesseln($vault, $this->record($zielId, false));
        $plan = $this->plane($vault, $quelle, $ziel);

        return new SupplierMergeVorschau($quelle, $ziel, $plan, $this->belege->anzahlNachLieferant($quelle->id));
    }

    /**
     * @throws SupplierRuleViolation
     */
    public function ausfuehren(Vault $vault, int $quelleId, int $zielId, ?int $userId, string $ip, \DateTimeImmutable $now): SupplierMerged
    {
        self::nichtSichSelbst($quelleId, $zielId);

        $this->pdo->beginTransaction();
        try {
            // Always the lower id first: two merges of the same pair in
            // opposite directions wait for each other instead of deadlocking.
            $gesperrt = [];
            foreach ([min($quelleId, $zielId), max($quelleId, $zielId)] as $id) {
                $gesperrt[$id] = $this->record($id, true);
            }
            $zielRecord = $gesperrt[$zielId];
            $plan = $this->plane(
                $vault,
                $this->service->entschluesseln($vault, $gesperrt[$quelleId]),
                $this->service->entschluesseln($vault, $zielRecord),
            );

            $key = $vault->openDataKey($zielRecord->dekSealed);
            $this->lieferanten->update($zielId, $plan->role, $plan->defaultCategoryId, SupplierService::verschluesseln($key, $zielId, $plan->data), $now);
            $this->lieferanten->replaceKeys($zielId, $this->service->keys($vault, $plan->data));

            $umgehaengt = $this->belege->haengeLieferantUm($quelleId, $zielId, $userId, $now);
            $festgeschrieben = $this->belege->anzahlNachLieferant($quelleId)['festgeschrieben'];
            $this->lieferanten->markiereZusammengefuehrt($quelleId, $zielId, $now);

            $this->audit->record(AuditAction::LieferantZusammengefuehrt, $userId, $ip, $quelleId, [
                'ziel' => $zielId,
                'belege' => $umgehaengt,
                'festgeschrieben' => $festgeschrieben,
                'felder' => $plan->geaenderteFelder,
            ], $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return new SupplierMerged($quelleId, $zielId, $umgehaengt, $festgeschrieben);
    }

    /**
     * @throws SupplierRuleViolation
     */
    private function plane(Vault $vault, Supplier $quelle, Supplier $ziel): SupplierMergePlan
    {
        $plan = SupplierMerge::plane($quelle, $ziel);
        // A third supplier may carry one of the source's IBANs or ids only
        // if it is a merge of its own away - the same rule as saving.
        $this->service->pruefeEindeutig($vault, $this->service->keys($vault, $plan->data), [$quelle->id, $ziel->id]);

        return $plan;
    }

    /**
     * @throws SupplierRuleViolation
     */
    private function record(int $id, bool $sperren): SupplierRecord
    {
        $record = ($sperren ? $this->lieferanten->sperre($id) : $this->lieferanten->find($id))
            ?? throw new SupplierRuleViolation('Diesen Lieferanten gibt es nicht.');
        SupplierService::nichtZusammengefuehrt($record);

        return $record;
    }

    /**
     * @throws SupplierRuleViolation
     */
    private static function nichtSichSelbst(int $quelleId, int $zielId): void
    {
        if ($quelleId === $zielId) {
            throw new SupplierRuleViolation('Bitte einen anderen Lieferanten wählen – mit sich selbst lässt sich keiner zusammenführen.');
        }
    }
}
