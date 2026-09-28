<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\Category;
use App\Domain\CategoryColor;
use App\Domain\CategoryDirection;
use App\Repository\CategoryRepository;

/**
 * Creating, changing, ordering and deleting categories (M6-1, issue #35,
 * docs/spec/02-datenmodell.md "Kategorien") - the rules, not the SQL:
 *
 * - Name is required, unique and at most NAME_MAX characters.
 * - Direction must be one of App\Domain\CategoryDirection, the colour one of
 *   App\Domain\CategoryColor or none; the AI hint at most AI_HINT_MAX
 *   characters.
 * - A category in use (CategoryRepository::usageCount()) is not deleted -
 *   the RESTRICT foreign keys back this up in the schema, this check exists
 *   to give a German message instead of a PDOException. Deactivating is
 *   how it stops being offered while existing assignments keep it.
 * - Moving one place up/down happens within the category's direction and
 *   normalises that direction's `sort` to 10, 20, 30, ...
 */
final readonly class CategoryService
{
    public const int NAME_MAX = 100;

    public const int AI_HINT_MAX = 500;

    public function __construct(private CategoryRepository $kategorien)
    {
    }

    /**
     * @throws CategoryRuleViolation
     */
    public function anlegen(string $name, string $richtung, string $farbe, string $kiHinweis): int
    {
        $name = $this->pruefeName($name, null);

        return $this->kategorien->create($name, self::richtung($richtung), self::farbe($farbe), self::kiHinweis($kiHinweis));
    }

    /**
     * @throws CategoryRuleViolation
     */
    public function aendern(int $id, string $name, string $richtung, string $farbe, string $kiHinweis, bool $active): void
    {
        $this->kategorie($id);
        $name = $this->pruefeName($name, $id);
        $this->kategorien->update($id, $name, self::richtung($richtung), self::farbe($farbe), self::kiHinweis($kiHinweis), $active);
    }

    /**
     * @throws CategoryRuleViolation
     */
    public function loeschen(int $id): void
    {
        $this->kategorie($id);
        if ($this->kategorien->usageCount($id) > 0) {
            throw new CategoryRuleViolation('Die Kategorie wird verwendet und lässt sich nur noch deaktivieren.');
        }

        $this->kategorien->delete($id);
    }

    /**
     * Swaps the category with its neighbour of the same direction; a no-op
     * at either end of that list.
     *
     * @throws CategoryRuleViolation
     */
    public function verschieben(int $id, bool $nachOben): void
    {
        $kategorie = $this->kategorie($id);

        $ids = array_map(static fn(Category $c): int => $c->id, $this->kategorien->byDirection($kategorie->direction));
        $position = array_search($id, $ids, true);
        if ($position === false) {
            return;
        }
        $nachbar = $nachOben ? $position - 1 : $position + 1;
        if ($nachbar < 0 || $nachbar >= count($ids)) {
            return;
        }

        [$ids[$position], $ids[$nachbar]] = [$ids[$nachbar], $ids[$position]];
        $this->kategorien->setOrder($ids);
    }

    private function kategorie(int $id): Category
    {
        return $this->kategorien->find($id) ?? throw new CategoryRuleViolation('Diese Kategorie gibt es nicht.');
    }

    private function pruefeName(string $name, ?int $id): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new CategoryRuleViolation('Bitte einen Namen angeben.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new CategoryRuleViolation(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::NAME_MAX));
        }
        if ($this->kategorien->nameExists($name, $id)) {
            throw new CategoryRuleViolation('Eine Kategorie mit diesem Namen gibt es schon.');
        }

        return $name;
    }

    private static function richtung(string $richtung): CategoryDirection
    {
        return CategoryDirection::tryFrom($richtung) ?? throw new CategoryRuleViolation('Bitte eine Richtung wählen.');
    }

    private static function farbe(string $farbe): ?CategoryColor
    {
        if ($farbe === '') {
            return null;
        }

        return CategoryColor::tryFrom($farbe) ?? throw new CategoryRuleViolation('Diese Farbe gibt es nicht.');
    }

    private static function kiHinweis(string $kiHinweis): string
    {
        $kiHinweis = trim($kiHinweis);
        if (mb_strlen($kiHinweis) > self::AI_HINT_MAX) {
            throw new CategoryRuleViolation(sprintf('Der KI-Hinweis darf höchstens %d Zeichen lang sein.', self::AI_HINT_MAX));
        }

        return $kiHinweis;
    }
}
