<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The `match_rejection` table (migrations/042_match_rejection.sql, M10-4,
 * issue #68, E-41): pairs of receipt and booking a user discarded as a
 * suggestion. Ids only - no vault data.
 */
final readonly class MatchRejectionRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /** Whether the pair was newly discarded (false: it already was). */
    public function verwerfen(int $invoiceId, int $transactionId, ?int $userId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO match_rejection (invoice_id, transaction_id, created_by, created_at) VALUES (?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $invoiceId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $transactionId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(4, $now->format(self::FORMAT));
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** Whether there was such a row. */
    public function zuruecknehmen(int $invoiceId, int $transactionId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM match_rejection WHERE invoice_id = ? AND transaction_id = ?');
        $stmt->execute([$invoiceId, $transactionId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The discarded pairs of these receipts.
     *
     * @param list<int> $invoiceIds
     *
     * @return array<string, true> keyed "invoiceId:transactionId" (self::schluessel())
     */
    public function paare(array $invoiceIds): array
    {
        $paare = [];
        foreach (array_chunk(array_values(array_unique($invoiceIds)), 500) as $teil) {
            $stmt = $this->pdo->prepare(sprintf(
                'SELECT invoice_id, transaction_id FROM match_rejection WHERE invoice_id IN (%s)',
                implode(',', array_fill(0, count($teil), '?')),
            ));
            $stmt->execute($teil);
            foreach ($stmt->fetchAll() as $zeile) {
                $paare[self::schluessel((int) $zeile['invoice_id'], (int) $zeile['transaction_id'])] = true;
            }
        }

        return $paare;
    }

    public static function schluessel(int $invoiceId, int $transactionId): string
    {
        return $invoiceId . ':' . $transactionId;
    }
}
