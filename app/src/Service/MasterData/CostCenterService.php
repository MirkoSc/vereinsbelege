<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\CostCenter;
use App\Repository\CostCenterRepository;

/**
 * Creating, changing, ordering and deleting cost centers (M4-1, issue #23,
 * docs/spec/02-datenmodell.md "Fachdaten") - the rules, not the SQL:
 *
 * - Name is required, unique and at most NAME_MAX characters.
 * - A cost center still assigned to an account is not deleted - the FK
 *   (migrations/012_cost_center_restrict.sql) backs this up in the schema,
 *   this check exists to give a German message instead of a PDOException.
 * - Deactivating (not deleting) is how a cost center stops being offered
 *   for new assignments while existing ones (and the scope they grant,
 *   docs/spec/01-sicherheit.md section 4) keep working.
 * - Moving one place up/down normalises every row's `sort` to 10, 20, 30,
 *   ... so gaps from deleted rows never accumulate.
 */
final readonly class CostCenterService
{
    public const int NAME_MAX = 100;

    public function __construct(private CostCenterRepository $kostenstellen)
    {
    }

    /**
     * @throws CostCenterRuleViolation
     */
    public function anlegen(string $name): int
    {
        $name = $this->pruefeName($name, null);

        return $this->kostenstellen->create($name);
    }

    /**
     * @throws CostCenterRuleViolation
     */
    public function aendern(int $id, string $name, bool $active): void
    {
        $this->kostenstelle($id);
        $name = $this->pruefeName($name, $id);
        $this->kostenstellen->update($id, $name, $active);
    }

    /**
     * @throws CostCenterRuleViolation
     */
    public function loeschen(int $id): void
    {
        $this->kostenstelle($id);
        if ($this->kostenstellen->userCount($id) > 0) {
            throw new CostCenterRuleViolation('Die Kostenstelle ist noch Benutzern zugewiesen. Bitte zuerst die Zuweisungen entfernen oder die Kostenstelle deaktivieren.');
        }

        $this->kostenstellen->delete($id);
    }

    /**
     * Swaps the cost center with its neighbour in display order; a no-op at
     * either end of the list.
     *
     * @throws CostCenterRuleViolation
     */
    public function verschieben(int $id, bool $nachOben): void
    {
        $this->kostenstelle($id);

        $ids = array_map(static fn(CostCenter $c): int => $c->id, $this->kostenstellen->all());
        $position = array_search($id, $ids, true);
        $nachbar = $nachOben ? $position - 1 : $position + 1;

        if ($position === false || $nachbar < 0 || $nachbar >= count($ids)) {
            return;
        }

        [$ids[$position], $ids[$nachbar]] = [$ids[$nachbar], $ids[$position]];
        $this->kostenstellen->setOrder($ids);
    }

    private function kostenstelle(int $id): CostCenter
    {
        return $this->kostenstellen->find($id) ?? throw new CostCenterRuleViolation('Diese Kostenstelle gibt es nicht.');
    }

    private function pruefeName(string $name, ?int $id): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new CostCenterRuleViolation('Bitte einen Namen angeben.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new CostCenterRuleViolation(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::NAME_MAX));
        }
        if ($this->kostenstellen->nameExists($name, $id)) {
            throw new CostCenterRuleViolation('Eine Kostenstelle mit diesem Namen gibt es schon.');
        }

        return $name;
    }
}
