<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\AuditAction;
use App\Domain\Document;
use App\Domain\DocumentStatus;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Service\Audit\AuditLog;

/**
 * Locking a checked receipt, and the one way back (issue #38/M6-4,
 * docs/spec/01-sicherheit.md section 7, E-09 - no four-eyes principle):
 *
 * - festschreiben(): `geprueft` -> `festgeschrieben`, with `invoice.locked_by/
 *   locked_at`. The person who may edit receipts (`document.edit`) may also
 *   lock them, even the one who checked it.
 * - aufheben(): `festgeschrieben` -> `in_pruefung`, with a mandatory reason.
 *   The lock and the "checked" mark are cleared; the receipt is captured in
 *   the review page again, checked again, locked again. That is the only
 *   correction there is - nothing changes a locked receipt silently.
 *
 * Both run in one transaction that first locks the document row in the
 * status it was read in (two people at once do not both win), change status
 * and receipt together and write the audit entry - if any of it fails,
 * none of it happened.
 *
 * Framework-free like App\Service\Invoice\Pruefung: no Http, no Session.
 * Needs no vault - nothing is decrypted - and the audit log holds neither
 * amounts nor names, only the reason a person gave for lifting the lock.
 */
final readonly class Festschreibung
{
    /** Same length as every other free-text reason (App\Service\Inbox\Posteingang::NOTIZ_MAX). */
    public const int GRUND_MAX = 2000;

    public function __construct(
        private \PDO $pdo,
        private DocumentRepository $documents,
        private InvoiceRepository $invoices,
        private AuditLog $audit,
    ) {
    }

    /**
     * @throws InvoiceRuleViolation when the receipt is not checked (any
     *         more), has no captured data, or was changed meanwhile
     */
    public function festschreiben(Document $document, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        if ($document->status === DocumentStatus::Festgeschrieben) {
            throw new InvoiceRuleViolation('Dieser Beleg ist bereits festgeschrieben.');
        }
        if ($document->status !== DocumentStatus::Geprueft) {
            throw new InvoiceRuleViolation('Nur ein geprüfter Beleg lässt sich festschreiben.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->sperren($document);
            $invoice = $this->invoices->findByDocument($document->id)
                ?? throw new InvoiceRuleViolation('Zu diesem Beleg sind keine Angaben erfasst – er lässt sich nicht festschreiben.');

            $this->wechsle($document, DocumentStatus::Festgeschrieben, $userId, $now);
            $this->invoices->setzeFestgeschrieben($invoice->id, $userId, $now);
            $this->audit->record(AuditAction::BelegFestgeschrieben, $userId, $ip, $document->id, [], $now);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->zurueck();

            throw $e;
        }
    }

    /**
     * @throws InvoiceRuleViolation when the reason is missing or too long,
     *         the receipt is not locked (any more), or was changed meanwhile
     */
    public function aufheben(Document $document, string $grund, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        if ($document->status !== DocumentStatus::Festgeschrieben) {
            throw new InvoiceRuleViolation('Dieser Beleg ist nicht festgeschrieben.');
        }
        $grund = trim($grund);
        if ($grund === '') {
            throw new InvoiceRuleViolation('Bitte einen Grund für die Aufhebung angeben.', 'grund');
        }
        if (mb_strlen($grund) > self::GRUND_MAX) {
            throw new InvoiceRuleViolation(sprintf('Der Grund darf höchstens %d Zeichen lang sein.', self::GRUND_MAX), 'grund');
        }

        $this->pdo->beginTransaction();
        try {
            $this->sperren($document);
            $invoice = $this->invoices->findByDocument($document->id)
                ?? throw new InvoiceRuleViolation('Zu diesem Beleg sind keine Angaben erfasst.');

            $this->wechsle($document, DocumentStatus::InPruefung, $userId, $now);
            $this->invoices->hebeFestschreibungAuf($invoice->id);
            $this->audit->record(AuditAction::BelegFestschreibungAufgehoben, $userId, $ip, $document->id, ['grund' => $grund], $now);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->zurueck();

            throw $e;
        }
    }

    /**
     * @throws InvoiceRuleViolation
     */
    private function sperren(Document $document): void
    {
        if (!$this->documents->sperreImStatus($document->id, $document->status)) {
            throw self::veraltet();
        }
    }

    /**
     * @throws InvoiceRuleViolation
     */
    private function wechsle(Document $document, DocumentStatus $nach, ?int $userId, \DateTimeImmutable $now): void
    {
        if (!$document->status->kannWechselnZu($nach) || !$this->documents->wechsleStatus($document->id, $document->status, $nach, null, null, $userId, $now)) {
            throw self::veraltet();
        }
    }

    private function zurueck(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private static function veraltet(): InvoiceRuleViolation
    {
        return new InvoiceRuleViolation('Der Beleg wurde inzwischen anders bearbeitet. Bitte die Seite neu laden.');
    }
}
