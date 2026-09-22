<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which rows of a business table a user may see for one right: the
 * cost-center scope ("Vereinsverantwortlicher") and the period scope of
 * external accounts (docs/spec/01-sicherheit.md section 4).
 *
 * The spec wants both "im Repository erzwungen, nicht in der View". This is
 * the piece a repository takes in: sqlBedingung() turns the scope into a
 * WHERE fragment with placeholders only, so every query that lists receipts,
 * inbox items or report rows filters in SQL and a template never sees a row
 * it must not show. erlaubt() is the same rule for one already loaded row
 * (a detail page reached by id).
 *
 * The business tables themselves arrive from M4 on; until then the tests
 * (tests/Integration/AccessScopeTest.php) run this against a table of their
 * own with the same column shapes (`cost_center_id`, a DATE column).
 */
final readonly class Zugriffsbereich
{
    /**
     * @param list<int>|null $kostenstellen null = not restricted; an empty
     *        list = restricted to nothing (a scoped role with no cost center
     *        assigned yet sees no rows, never all of them)
     */
    public function __construct(
        public ?array $kostenstellen = null,
        public ?\DateTimeImmutable $von = null,
        public ?\DateTimeImmutable $bis = null,
    ) {
    }

    public static function unbeschraenkt(): self
    {
        return new self();
    }

    public function istUnbeschraenkt(): bool
    {
        return $this->kostenstellen === null && $this->von === null && $this->bis === null;
    }

    /**
     * @param int|null $kostenstelle the row's cost center; a row without one
     *        is outside every cost-center scope
     * @param \DateTimeImmutable $datum the date the period scope applies to
     *        (receipt date, booking date)
     */
    public function erlaubt(?int $kostenstelle, \DateTimeImmutable $datum): bool
    {
        if ($this->kostenstellen !== null && ($kostenstelle === null || !in_array($kostenstelle, $this->kostenstellen, true))) {
            return false;
        }

        $tag = $datum->format('Y-m-d');
        if ($this->von !== null && $tag < $this->von->format('Y-m-d')) {
            return false;
        }

        return $this->bis === null || $tag <= $this->bis->format('Y-m-d');
    }

    /**
     * A WHERE fragment for a repository query, e.g.
     * `[$sql, $params] = $bereich->sqlBedingung('i.invoice_date', 'i.cost_center_id')`
     * then `... WHERE i.status = ? AND ' . $sql`, binding `$params` after the
     * query's own. Column names come from code, never from a request - they
     * are checked against a plain identifier pattern all the same.
     *
     * @param string|null $kostenstellenSpalte null for tables without a cost
     *        center; a cost-center scope then matches nothing
     * @return array{0: string, 1: list<int|string>}
     */
    public function sqlBedingung(string $datumsSpalte, ?string $kostenstellenSpalte): array
    {
        self::pruefeSpalte($datumsSpalte);
        if ($kostenstellenSpalte !== null) {
            self::pruefeSpalte($kostenstellenSpalte);
        }

        $teile = [];
        $parameter = [];

        if ($this->kostenstellen !== null) {
            if ($this->kostenstellen === [] || $kostenstellenSpalte === null) {
                $teile[] = '1 = 0';
            } else {
                $teile[] = $kostenstellenSpalte . ' IN (' . implode(', ', array_fill(0, count($this->kostenstellen), '?')) . ')';
                array_push($parameter, ...$this->kostenstellen);
            }
        }
        if ($this->von !== null) {
            $teile[] = $datumsSpalte . ' >= ?';
            $parameter[] = $this->von->format('Y-m-d');
        }
        if ($this->bis !== null) {
            // Exclusive upper bound on the following day, so the last day
            // counts in full for a DATETIME column as well as for a DATE.
            $teile[] = $datumsSpalte . ' < ?';
            $parameter[] = $this->bis->modify('+1 day')->format('Y-m-d');
        }

        return [$teile === [] ? '1 = 1' : '(' . implode(' AND ', $teile) . ')', $parameter];
    }

    private static function pruefeSpalte(string $spalte): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $spalte) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Spaltenname für den Zugriffsbereich.');
        }
    }
}
