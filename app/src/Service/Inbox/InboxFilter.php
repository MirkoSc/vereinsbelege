<?php

declare(strict_types=1);

namespace App\Service\Inbox;

/**
 * The filters of /app/posteingang (issue #27/M4-5), read from the query
 * string. Like App\Service\Audit\AuditFilter, a value that does not parse
 * is dropped rather than answered with an error.
 *
 * Status group, period and cost center are plaintext columns and filter in
 * SQL (App\Repository\DocumentRepository::inbox()). The search runs over
 * name and description of the submission - vault ciphertext, so PHP
 * matches it after decrypting (App\Service\Inbox\Posteingang::liste()).
 */
final readonly class InboxFilter
{
    /** `kostenstelle=ohne`: documents without a cost center. */
    public const string OHNE_KOSTENSTELLE = 'ohne';

    public function __construct(
        public InboxAnsicht $ansicht = InboxAnsicht::Offen,
        public ?\DateTimeImmutable $von = null,
        /** inclusive: the whole day */
        public ?\DateTimeImmutable $bis = null,
        /** null = all, 0 = without a cost center */
        public ?int $kostenstelle = null,
        public string $suche = '',
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $kostenstelle = self::text($query, 'kostenstelle');

        return new self(
            ansicht: InboxAnsicht::tryFrom(self::text($query, 'ansicht')) ?? InboxAnsicht::Offen,
            von: self::datum($query, 'von'),
            bis: self::datum($query, 'bis'),
            kostenstelle: match (true) {
                $kostenstelle === self::OHNE_KOSTENSTELLE => 0,
                ctype_digit($kostenstelle) && (int) $kostenstelle > 0 => (int) $kostenstelle,
                default => null,
            },
            suche: mb_substr(self::text($query, 'suche'), 0, 100),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'ansicht' => $this->ansicht === InboxAnsicht::Offen ? null : $this->ansicht->value,
            'von' => $this->von?->format('Y-m-d'),
            'bis' => $this->bis?->format('Y-m-d'),
            'kostenstelle' => match ($this->kostenstelle) {
                null => null,
                0 => self::OHNE_KOSTENSTELLE,
                default => (string) $this->kostenstelle,
            },
            'suche' => $this->suche === '' ? null : $this->suche,
        ], static fn(?string $wert): bool => $wert !== null);
    }

    /**
     * Whether anything beyond the default view narrows the list.
     */
    public function eingeschraenkt(): bool
    {
        return $this->von !== null || $this->bis !== null || $this->kostenstelle !== null || $this->suche !== '';
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
    private static function datum(array $query, string $feld): ?\DateTimeImmutable
    {
        $wert = self::text($query, $feld);
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $datum !== false && $datum->format('Y-m-d') === $wert ? $datum : null;
    }
}
