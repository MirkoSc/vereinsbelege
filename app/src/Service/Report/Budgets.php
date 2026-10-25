<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\BudgetRecord;
use App\Domain\CategoryDirection;
use App\Domain\Zugriffsbereich;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Processing\Betrag;

/**
 * Budgets per category and cost center with Soll/Ist (issue #92,
 * docs/spec/05-auswertung-und-export.md section 1 "Stand #92", E-58).
 *
 * A budget is the planned expenses of one calendar year (= fiscal year,
 * E-16) for a category, a cost center or the pair. Its amount is vault data
 * (`budget.data_enc`, AAD "budget|<id>|data_enc", own DEK); year and keys
 * are plaintext structure. The Ist comes from the ReportService on the
 * input of the Auswertungsquelle (the session cache in production).
 */
final readonly class Budgets
{
    public const string TABLE = 'budget';
    public const string COLUMN = 'data_enc';
    public const string CURRENCY = 'EUR';

    public function __construct(
        private \PDO $pdo,
        private BudgetRepository $budgets,
        private CategoryRepository $kategorien,
        private CostCenterRepository $kostenstellen,
        private Auswertungsquelle $quelle,
    ) {
    }

    /** Soll against Ist of every budget of $jahr the reader may see. */
    public function vergleich(Vault $vault, Grundlage $grundlage, int $jahr, Zugriffsbereich $bereich): Budgetergebnis
    {
        $zeitraum = Zeitraum::jahr($jahr);
        $sichtbar = $zeitraum->begrenztAuf($bereich);
        if ($sichtbar === null) {
            return new Budgetergebnis($jahr, null, []);
        }

        $soll = [];
        $unlesbar = 0;
        foreach ($this->budgets->forYear($jahr, $bereich) as $record) {
            try {
                $soll[] = self::entschluesseln($vault, $record);
            } catch (CryptoException | \JsonException | \UnexpectedValueException) {
                ++$unlesbar;
            }
        }

        $kategorien = $this->namenKategorien();
        $kostenstellen = $this->namenKostenstellen();
        if ($soll === []) {
            return new Budgetergebnis($jahr, $sichtbar, [], $kategorien, $kostenstellen, $unlesbar);
        }

        $daten = $this->quelle->grundlage($vault, $grundlage, $zeitraum, $bereich);
        $zeilen = $this->quelle->rechner()->budgetvergleich($daten, $bereich, $soll);
        // "alle …" (a null key) first, then by name, then by id.
        $sortierung = static fn(Budgetzeile $z): array => [
            $z->soll->kategorieId === null ? '' : "\u{0001}" . mb_strtolower($kategorien[$z->soll->kategorieId] ?? ''),
            $z->soll->kostenstelleId === null ? '' : "\u{0001}" . mb_strtolower($kostenstellen[$z->soll->kostenstelleId] ?? ''),
            $z->soll->id,
        ];
        usort($zeilen, static fn(Budgetzeile $a, Budgetzeile $b): int => $sortierung($a) <=> $sortierung($b));

        return new Budgetergebnis($jahr, $sichtbar, $zeilen, $kategorien, $kostenstellen, $unlesbar, $daten->unlesbar, $daten->fremdwaehrung);
    }

    /**
     * Creates the budget of a year and pair, or changes its amount when it
     * exists ("speichern" is an upsert: one budget per year and pair).
     *
     * @param array<string, string> $felder `kategorie`, `kostenstelle` (id or
     *        empty), `betrag` (German amount)
     * @param Zugriffsbereich $bereich the writer's cost-center scope (N-07):
     *        a scoped writer keeps only budgets of his cost centers, never
     *        one without a cost center - the same rows forYear() shows him
     *
     * @return array{id: int, neu: bool, jahr: int, kategorie: int|null, kostenstelle: int|null}
     *
     * @throws BudgetFehler
     */
    public function speichern(Vault $vault, int $jahr, array $felder, Zugriffsbereich $bereich, ?int $userId, \DateTimeImmutable $now): array
    {
        $letztes = (int) $now->format('Y') + 1;
        if ($jahr < ZeitraumAuswahl::ERSTES_JAHR || $jahr > $letztes) {
            throw new BudgetFehler(sprintf('Budgets gibt es für die Jahre %d bis %d.', ZeitraumAuswahl::ERSTES_JAHR, $letztes), 'jahr');
        }
        $kategorie = $this->kategorieAus($felder['kategorie'] ?? '');
        $kostenstelle = $this->kostenstelleAus($felder['kostenstelle'] ?? '');
        if ($kategorie === null && $kostenstelle === null) {
            throw new BudgetFehler('Bitte eine Kategorie, eine Kostenstelle oder beides wählen.', 'kategorie');
        }
        // Only the cost centers count: a year is no date for the period scope.
        if (!new Zugriffsbereich($bereich->kostenstellen)->erlaubt($kostenstelle, $now)) {
            throw new BudgetFehler('Budgets pflegen Sie nur für Ihre eigenen Kostenstellen. Bitte eine davon wählen.', 'kostenstelle');
        }
        // Betrag::parse() refuses anything above Betrag::MAX_CENT (999.999.999,99).
        $betrag = Betrag::parse(trim($felder['betrag'] ?? ''));
        if ($betrag === null) {
            throw new BudgetFehler('Bitte einen Betrag in Euro bis 999.999.999,99 angeben, z. B. 1.500,00.', 'betrag');
        }
        if ($betrag < 0) {
            throw new BudgetFehler('Ein Budget kann nicht negativ sein.', 'betrag');
        }

        // Two saves of the same new pair at once (a double click): the second
        // insert hits the UNIQUE key - then it is a change of the first.
        try {
            [$id, $neu] = $this->schreiben($vault, $jahr, $kategorie, $kostenstelle, $betrag, $userId, $now);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            [$id, $neu] = $this->schreiben($vault, $jahr, $kategorie, $kostenstelle, $betrag, $userId, $now);
        }

        return ['id' => $id, 'neu' => $neu, 'jahr' => $jahr, 'kategorie' => $kategorie, 'kostenstelle' => $kostenstelle];
    }

    /**
     * @return array{0: int, 1: bool} the id and whether the row is new
     */
    private function schreiben(Vault $vault, int $jahr, ?int $kategorie, ?int $kostenstelle, int $betrag, ?int $userId, \DateTimeImmutable $now): array
    {
        $key = DataKey::generate();
        $this->pdo->beginTransaction();
        try {
            $id = $this->budgets->idFor($jahr, $kategorie, $kostenstelle);
            $neu = $id === null;
            if ($id === null) {
                $id = $this->budgets->insert($jahr, $kategorie, $kostenstelle, $vault->sealDataKey($key), $userId, $now);
                $this->budgets->setData($id, self::verschluesseln($key, $id, $betrag));
            } else {
                // A fresh key for the new amount: nothing old has to be decrypted.
                $this->budgets->replaceData($id, $vault->sealDataKey($key), self::verschluesseln($key, $id, $betrag), $now);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return [$id, $neu];
    }

    /**
     * Deletes a budget within the writer's cost-center scope (N-07); the row
     * as it was, null if there was none or it is outside the scope.
     */
    public function loeschen(int $id, Zugriffsbereich $bereich): ?BudgetRecord
    {
        $record = $this->budgets->find($id, $bereich);
        if ($record === null || !$this->budgets->delete($id, $bereich)) {
            return null;
        }

        return $record;
    }

    /**
     * What the form offers: the active categories for expenses (an income
     * category has no expenses to compare with) and the active cost centers
     * within the writer's scope.
     *
     * @return array{kategorien: array<int, string>, kostenstellen: array<int, string>}
     */
    public function auswahl(Zugriffsbereich $bereich): array
    {
        $kostenstellen = $this->kostenstellen->active();
        if ($bereich->kostenstellen !== null) {
            $kostenstellen = array_intersect_key($kostenstellen, array_flip($bereich->kostenstellen));
        }

        return [
            'kategorien' => $this->kategorien->active(CategoryDirection::Ausgabe),
            'kostenstellen' => $kostenstellen,
        ];
    }

    private function kategorieAus(string $wert): ?int
    {
        $wert = trim($wert);
        if ($wert === '') {
            return null;
        }
        $kategorie = preg_match('/^[1-9]\d{0,18}$/', $wert) === 1 ? $this->kategorien->find((int) $wert) : null;
        if ($kategorie === null || !$kategorie->active || $kategorie->direction === CategoryDirection::Einnahme) {
            throw new BudgetFehler('Diese Kategorie gibt es nicht, sie ist deaktiviert oder eine reine Einnahmen-Kategorie.', 'kategorie');
        }

        return $kategorie->id;
    }

    private function kostenstelleAus(string $wert): ?int
    {
        $wert = trim($wert);
        if ($wert === '') {
            return null;
        }
        $kostenstelle = preg_match('/^[1-9]\d{0,18}$/', $wert) === 1 ? $this->kostenstellen->find((int) $wert) : null;
        if ($kostenstelle === null || !$kostenstelle->active) {
            throw new BudgetFehler('Diese Kostenstelle gibt es nicht oder sie ist deaktiviert.', 'kostenstelle');
        }

        return $kostenstelle->id;
    }

    private static function verschluesseln(DataKey $key, int $id, int $betrag): string
    {
        return FieldCipher::encrypt(
            $key,
            json_encode(['amount' => $betrag, 'currency' => self::CURRENCY], JSON_THROW_ON_ERROR),
            new FieldContext(self::TABLE, $id, self::COLUMN),
        );
    }

    /**
     * @throws CryptoException|\JsonException|\UnexpectedValueException
     */
    private static function entschluesseln(Vault $vault, BudgetRecord $record): Budgetsoll
    {
        $json = FieldCipher::decrypt(
            $vault->openDataKey($record->dekSealed),
            $record->dataEnc,
            new FieldContext(self::TABLE, $record->id, self::COLUMN),
        );
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !is_int($payload['amount'] ?? null) || ($payload['currency'] ?? null) !== self::CURRENCY) {
            throw new \UnexpectedValueException('Budget payload is malformed.');
        }

        return new Budgetsoll($record->id, $record->year, $record->categoryId, $record->costCenterId, $payload['amount']);
    }

    /** @return array<int, string> */
    private function namenKategorien(): array
    {
        $namen = [];
        foreach ($this->kategorien->all() as $kategorie) {
            $namen[$kategorie->id] = $kategorie->name;
        }

        return $namen;
    }

    /** @return array<int, string> */
    private function namenKostenstellen(): array
    {
        $namen = [];
        foreach ($this->kostenstellen->all() as $kostenstelle) {
            $namen[$kostenstelle->id] = $kostenstelle->name;
        }

        return $namen;
    }
}
