<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The other document of a suspected duplicate pair (issue #40/M6-6): only
 * plaintext structure - the reference number, when it came in, where it
 * stands - plus what the viewer's scope is checked against
 * (App\Domain\Zugriffsbereich::erlaubt() on cost center and `created_at`,
 * like the inbox).
 */
final readonly class DuplikatKandidat
{
    /**
     * @param non-empty-list<DuplikatGrund> $gruende
     */
    public function __construct(
        public int $documentId,
        public array $gruende,
        public DocumentStatus $status,
        public ?string $referenz,
        public \DateTimeImmutable $eingegangenAm,
        public ?int $costCenterId,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
