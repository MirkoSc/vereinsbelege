<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Domain\AuditAction;
use App\Domain\Document;
use App\Domain\DocumentStatus;
use App\Domain\DuplikatGrund;
use App\Domain\DuplikatKandidat;
use App\Domain\Zugriffsbereich;
use App\Repository\DocumentDuplicateRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * Duplicate detection (issue #40/M6-6, docs/spec/02-datenmodell.md
 * "Statusmodell", `duplikat_verdacht`): shows the inbox and the review page
 * which documents look like the same receipt, and resolves a suspicion -
 * "Als Duplikat verwerfen" or "Bewusst behalten".
 *
 * The suspicion is a flag, not a status, and it is derived on every read
 * (App\Repository\DocumentDuplicateRepository) from two blind indexes the
 * session computed: the content index of the original files
 * (App\Service\Document\Duplikatindex) and supplier + invoice number
 * (App\Service\Invoice\Pruefung). Both sides of a pair carry it, until one
 * of them is discarded or the pair is kept.
 *
 * - Verwerfen moves the document to `abgelehnt` - from every status that
 *   is not decided yet, `geprueft` included (DocumentStatus::uebergaenge()
 *   has those transitions for this action only). The reason is written by
 *   the application ("Als Duplikat verworfen: …"), FieldCipher under the
 *   document's DEK like every rejection reason. A checked receipt is no
 *   longer checked.
 * - Behalten records every current pair of the document as "different
 *   receipts"; a further copy arriving later is a new pair and flags again.
 * - Both run in one transaction that first locks the document and the
 *   other sides of its pairs (smallest id first), checks the document is
 *   still in the status it was read in, and looks at the suspicion again
 *   under that lock: two people deciding at once do not both win - nobody
 *   discards both sides of a pair - and nobody discards a document whose
 *   suspicion has gone in the meantime.
 * - A locked receipt (docs/spec/01-sicherheit.md section 7) is never written
 *   - it carries no suspicion of its own and is refused loudly here.
 *
 * Framework-free like the other services. Nothing decrypted leaves through
 * an exception message or the audit log: its details name document ids and
 * reasons only.
 */
final readonly class Duplikatpruefung
{
    /** References listed in the rejection reason; more are counted. */
    private const int NOTIZ_REFERENZEN = 10;

    private const string TABLE_DOCUMENT = 'document';

    private const string COLUMN_NOTE = 'status_note_enc';

    public function __construct(
        private \PDO $pdo,
        private DocumentRepository $documents,
        private DocumentDuplicateRepository $duplikate,
        private InvoiceRepository $invoices,
        private AuditLog $audit,
    ) {
    }

    /**
     * Which of these documents carry the suspicion - for the badges of a
     * list page.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, true>
     */
    public function verdaechtige(array $documentIds): array
    {
        return $this->duplikate->verdaechtige($documentIds);
    }

    public function verdacht(Document $document, Zugriffsbereich $bereich): DuplikatVerdacht
    {
        return self::aufteilen($this->duplikate->kandidaten($document->id), $bereich);
    }

    /**
     * @throws DuplikatRuleViolation
     */
    public function verwerfen(Document $document, Vault $vault, Zugriffsbereich $bereich, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        self::pruefeOffen($document);
        if (!$document->status->kannWechselnZu(DocumentStatus::Abgelehnt)) {
            throw new DuplikatRuleViolation('Dieser Beleg lässt sich nicht als Duplikat verwerfen.');
        }

        $gegenstuecke = $this->gegenstuecke($document);
        $this->inTransaktion(function () use ($document, $gegenstuecke, $vault, $bereich, $userId, $ip, $now): void {
            $kandidaten = $this->kandidatenImLock($document, $gegenstuecke);
            $notiz = FieldCipher::encrypt(
                $vault->openDataKey($document->dekSealed),
                self::notiz(self::aufteilen($kandidaten, $bereich)),
                new FieldContext(self::TABLE_DOCUMENT, $document->id, self::COLUMN_NOTE),
            );

            if (!$this->documents->wechsleStatus($document->id, $document->status, DocumentStatus::Abgelehnt, $notiz, null, $userId, $now)) {
                throw new DuplikatRuleViolation('Der Beleg wurde inzwischen anders bearbeitet. Bitte die Seite neu laden.');
            }
            if ($document->status === DocumentStatus::Geprueft) {
                $rechnung = $this->invoices->findByDocument($document->id);
                if ($rechnung !== null) {
                    $this->invoices->setzeUngeprueft($rechnung->id);
                }
            }

            $this->audit->record(AuditAction::BelegDuplikatVerworfen, $userId, $ip, $document->id, [
                'von' => $document->status->value,
                ...self::details($kandidaten),
            ], $now);
        });
    }

    /**
     * @throws DuplikatRuleViolation
     */
    public function behalten(Document $document, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        self::pruefeOffen($document);

        $gegenstuecke = $this->gegenstuecke($document);
        $this->inTransaktion(function () use ($document, $gegenstuecke, $userId, $ip, $now): void {
            $kandidaten = $this->kandidatenImLock($document, $gegenstuecke);
            foreach ($kandidaten as $kandidat) {
                $this->duplikate->behalte($document->id, $kandidat->documentId, $userId, $now);
            }

            $this->audit->record(AuditAction::BelegDuplikatBehalten, $userId, $ip, $document->id, self::details($kandidaten), $now);
        });
    }

    /**
     * @throws DuplikatRuleViolation
     */
    private static function pruefeOffen(Document $document): void
    {
        if ($document->status === DocumentStatus::Festgeschrieben) {
            throw new DuplikatRuleViolation('Dieser Beleg ist festgeschrieben und lässt sich nicht ändern.');
        }
        if ($document->status === DocumentStatus::Abgelehnt) {
            throw new DuplikatRuleViolation('Dieser Beleg ist bereits abgelehnt.');
        }
    }

    /**
     * The other sides as they are now - read before the transaction starts:
     * its first plain read fixes what it sees (InnoDB's consistent read), and
     * that has to come after the locks.
     *
     * @return list<int>
     */
    private function gegenstuecke(Document $document): array
    {
        return array_map(static fn(DuplikatKandidat $k): int => $k->documentId, $this->duplikate->kandidaten($document->id));
    }

    /**
     * Inside the transaction: locks the document and every other side of
     * its pairs, smallest id first (two people discarding the two sides of
     * one pair at once must not both succeed - the second one has to see
     * the first one's `abgelehnt`), checks the document is still in the
     * status it was read in, then reads the suspicion again.
     *
     * @param list<int> $gegenstuecke gegenstuecke(), read before the transaction
     *
     * @return non-empty-list<DuplikatKandidat>
     *
     * @throws DuplikatRuleViolation
     */
    private function kandidatenImLock(Document $document, array $gegenstuecke): array
    {
        $this->documents->sperreAlle([$document->id, ...$gegenstuecke]);
        if (!$this->documents->sperreImStatus($document->id, $document->status)) {
            throw new DuplikatRuleViolation('Der Beleg wurde inzwischen anders bearbeitet. Bitte die Seite neu laden.');
        }

        $kandidaten = $this->duplikate->kandidaten($document->id);
        if ($kandidaten === []) {
            throw new DuplikatRuleViolation('Für diesen Beleg besteht kein Duplikat-Verdacht (mehr).');
        }

        return $kandidaten;
    }

    /**
     * @param \Closure(): void $arbeit
     */
    private function inTransaktion(\Closure $arbeit): void
    {
        $this->pdo->beginTransaction();
        try {
            $arbeit();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @param list<DuplikatKandidat> $kandidaten
     */
    private static function aufteilen(array $kandidaten, Zugriffsbereich $bereich): DuplikatVerdacht
    {
        $sichtbar = array_values(array_filter(
            $kandidaten,
            static fn(DuplikatKandidat $k): bool => $bereich->erlaubt($k->costCenterId, $k->createdAt),
        ));

        return new DuplikatVerdacht($sichtbar, count($kandidaten) - count($sichtbar));
    }

    /**
     * The rejection reason: why, and which document it duplicates - only
     * references the deciding person may see.
     */
    private static function notiz(DuplikatVerdacht $verdacht): string
    {
        $teile = [];
        foreach (array_slice($verdacht->sichtbar, 0, self::NOTIZ_REFERENZEN) as $kandidat) {
            $gruende = implode(' und ', array_map(static fn(DuplikatGrund $g): string => $g->bezeichnung(), $kandidat->gruende));
            $teile[] = $gruende . ' wie ' . ($kandidat->referenz ?? 'Beleg #' . $kandidat->documentId);
        }

        $weitere = $verdacht->ausserhalb + max(0, count($verdacht->sichtbar) - self::NOTIZ_REFERENZEN);
        if ($weitere > 0) {
            $teile[] = $weitere === 1 ? 'ein weiterer Beleg' : sprintf('%d weitere Belege', $weitere);
        }

        return 'Als Duplikat verworfen: ' . implode('; ', $teile) . '.';
    }

    /**
     * @param list<DuplikatKandidat> $kandidaten
     *
     * @return array{andere: list<int>, gruende: list<string>}
     */
    private static function details(array $kandidaten): array
    {
        $gruende = [];
        foreach ($kandidaten as $kandidat) {
            foreach ($kandidat->gruende as $grund) {
                $gruende[$grund->value] = true;
            }
        }

        return [
            'andere' => array_map(static fn(DuplikatKandidat $k): int => $k->documentId, $kandidaten),
            'gruende' => array_keys($gruende),
        ];
    }
}
