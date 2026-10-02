<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\DocumentStatus;
use App\Domain\DuplikatGrund;
use App\Domain\DuplikatKandidat;

/**
 * Duplicate detection (issue #40/M6-6, docs/spec/02-datenmodell.md
 * "Statusmodell", `duplikat_verdacht`): which documents look like the same
 * receipt, and the pairs someone decided to keep (`document_duplicate_kept`,
 * migrations/023).
 *
 * The suspicion is derived here on every read, never stored: two documents
 * are a pair when they have the same `content_bi` (the same original files)
 * or their receipts the same `number_bi` with the same supplier - a merged
 * supplier counts as its target (`merged_into`, docs/spec/03-erfassung-und-
 * ki.md section 7). Both are blind indexes; SQL compares them without
 * decrypting anything. A pair does not count when
 *   - the other document is `abgelehnt` (it is not a receipt any more),
 *   - the document itself is `abgelehnt` or `festgeschrieben` (nothing is
 *     left to decide there - a locked one is still the other side of a pair),
 *   - the pair is in `document_duplicate_kept`.
 */
final readonly class DocumentDuplicateRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Which of these documents carry the suspicion - one query for a page of
     * the inbox or the review queue.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, true>
     */
    public function verdaechtige(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $ergebnis = [];
        foreach ($this->paare($documentIds) as $zeile) {
            $ergebnis[(int) $zeile['document_id']] = true;
        }

        return $ergebnis;
    }

    /**
     * The other side of every pair this document is part of, oldest first,
     * with every reason that applies.
     *
     * @return list<DuplikatKandidat>
     */
    public function kandidaten(int $documentId): array
    {
        /** @var array<int, array{zeile: array<string, mixed>, gruende: array<string, true>}> $nachDokument */
        $nachDokument = [];
        foreach ($this->paare([$documentId]) as $zeile) {
            $id = (int) $zeile['other_id'];
            $nachDokument[$id] ??= ['zeile' => $zeile, 'gruende' => []];
            $nachDokument[$id]['gruende'][(string) $zeile['grund']] = true;
        }

        $kandidaten = [];
        foreach ($nachDokument as $id => ['zeile' => $zeile, 'gruende' => $gruende]) {
            $createdAt = new \DateTimeImmutable((string) $zeile['created_at']);
            $kandidaten[] = new DuplikatKandidat(
                documentId: $id,
                gruende: array_values(array_filter(
                    DuplikatGrund::cases(),
                    static fn(DuplikatGrund $g): bool => isset($gruende[$g->value]),
                )),
                status: DocumentStatus::from((string) $zeile['status']),
                referenz: $zeile['reference_code'] === null ? null : (string) $zeile['reference_code'],
                eingegangenAm: $zeile['received_at'] === null ? $createdAt : new \DateTimeImmutable((string) $zeile['received_at']),
                costCenterId: $zeile['cost_center_id'] === null ? null : (int) $zeile['cost_center_id'],
                createdAt: $createdAt,
            );
        }

        return $kandidaten;
    }

    /**
     * "Bewusst behalten": these two are different receipts. Deciding the
     * same pair twice is no error.
     */
    public function behalte(int $documentId, int $andereId, ?int $userId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO document_duplicate_kept (document_low_id, document_high_id, kept_by, kept_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE document_low_id = document_low_id',
        );
        $stmt->bindValue(1, min($documentId, $andereId), \PDO::PARAM_INT);
        $stmt->bindValue(2, max($documentId, $andereId), \PDO::PARAM_INT);
        $stmt->bindValue(3, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(4, $now->format(self::FORMAT));
        $stmt->execute();
    }

    /**
     * Every counting pair (document, other, reason) of the given documents,
     * with what DuplikatKandidat needs of the other one. A pair matching on
     * both signals comes as two rows.
     *
     * @param non-empty-list<int> $documentIds
     *
     * @return list<array<string, mixed>>
     */
    private function paare(array $documentIds): array
    {
        $in = implode(', ', array_fill(0, count($documentIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT p.document_id, p.other_id, p.grund, o.status, o.cost_center_id, o.created_at, sub.reference_code, sub.received_at
             FROM (
                 SELECT d.id AS document_id, o.id AS other_id, ? AS grund
                 FROM document d JOIN document o ON o.content_bi = d.content_bi AND o.id <> d.id
                 WHERE d.id IN (' . $in . ') AND d.content_bi IS NOT NULL
                 UNION ALL
                 SELECT i.document_id, oi.document_id, ?
                 FROM invoice i
                 JOIN supplier s ON s.id = i.supplier_id
                 JOIN supplier os ON COALESCE(os.merged_into, os.id) = COALESCE(s.merged_into, s.id)
                 JOIN invoice oi ON oi.supplier_id = os.id AND oi.number_bi = i.number_bi AND oi.document_id <> i.document_id
                 WHERE i.document_id IN (' . $in . ') AND i.number_bi IS NOT NULL
             ) p
             JOIN document d ON d.id = p.document_id
             JOIN document o ON o.id = p.other_id
             LEFT JOIN submission sub ON sub.id = o.submission_id
             WHERE d.status NOT IN (?, ?) AND o.status <> ?
               AND NOT EXISTS (
                   SELECT 1 FROM document_duplicate_kept k
                   WHERE k.document_low_id = LEAST(d.id, o.id) AND k.document_high_id = GREATEST(d.id, o.id)
               )
             ORDER BY o.created_at, o.id, p.grund',
        );
        $stmt->execute([
            DuplikatGrund::Inhalt->value,
            ...$documentIds,
            DuplikatGrund::Nummer->value,
            ...$documentIds,
            DocumentStatus::Abgelehnt->value,
            DocumentStatus::Festgeschrieben->value,
            DocumentStatus::Abgelehnt->value,
        ]);

        return $stmt->fetchAll();
    }
}
