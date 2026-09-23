<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Domain\AuditAction;

/**
 * The filters of /app/audit (issue #21/M3-8), read from the query string.
 * A value that does not parse is dropped rather than answered with an
 * error: it is a GET form, and the list without that filter is the
 * obvious fallback.
 *
 * All filters are plaintext columns, so they run in SQL
 * (App\Repository\AuditLogRepository::page()) - the details are
 * encrypted and deliberately not searchable.
 */
final readonly class AuditFilter
{
    public function __construct(
        public ?AuditAction $aktion = null,
        public ?int $userId = null,
        public ?\DateTimeImmutable $von = null,
        /** inclusive: the whole day */
        public ?\DateTimeImmutable $bis = null,
        public ?string $entity = null,
        public ?int $entityId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $entity = self::text($query, 'entity');

        return new self(
            aktion: AuditAction::tryFrom(self::text($query, 'aktion')),
            userId: self::id($query, 'benutzer'),
            von: self::datum($query, 'von'),
            bis: self::datum($query, 'bis'),
            entity: in_array($entity, AuditAction::entities(), true) ? $entity : null,
            entityId: self::id($query, 'entity_id'),
        );
    }

    /**
     * The same filters as query parameters - for the "Ältere Einträge" link,
     * so paging keeps them.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'aktion' => $this->aktion?->value,
            'benutzer' => $this->userId === null ? null : (string) $this->userId,
            'von' => $this->von?->format('Y-m-d'),
            'bis' => $this->bis?->format('Y-m-d'),
            'entity' => $this->entity,
            'entity_id' => $this->entityId === null ? null : (string) $this->entityId,
        ], static fn(?string $wert): bool => $wert !== null);
    }

    public function aktiv(): bool
    {
        return $this->toQuery() !== [];
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function text(array $query, string $feld): string
    {
        $wert = $query[$feld] ?? '';

        return is_string($wert) ? trim($wert) : '';
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function id(array $query, string $feld): ?int
    {
        $wert = self::text($query, $feld);

        return ctype_digit($wert) && (int) $wert > 0 ? (int) $wert : null;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function datum(array $query, string $feld): ?\DateTimeImmutable
    {
        $wert = self::text($query, $feld);
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $datum !== false && $datum->format('Y-m-d') === $wert ? $datum : null;
    }
}
