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
     *
     * @return array{id: int, neu: bool, jahr: int, kategorie: int|null, kostenstelle: int|null}
     *
     * @throws BudgetFehler
     */
    public function speichern(Vault $vault, int $jahr, array $felder, ?int $userId, \DateTimeImmutable $now): array
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
        // Betrag::parse() refuses anything above Betrag::MAX_CENT (999.999.999,99).
        $betrag = Betrag::parse(trim($felder['betrag'] ?? ''));
        if ($betrag === null) {
            throw new BudgetFehler('Bitte einen Betrag in Euro bis 999.999.999,99 angeben, z. B. 1.500,00.', 'betrag');
        }
        if ($betrag < 0) {
            throw new BudgetFehler('Ein Budget kann nicht negativ sein.', 'betrag');
        }

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

        return ['id' => $id, 'neu' => $neu, 'jahr' => $jahr, 'kategorie' => $kategorie, 'kostenstelle' => $kostenstelle];
    }

    /** Deletes a budget; the row as it was, null if there was none. */
    public function loeschen(int $id): ?BudgetRecord
    {
        $record = $this->budgets->find($id);
        if ($record === null || !$this->budgets->delete($id)) {
            return null;
        }

        return $record;
    }

    /**
     * What the form offers: the active categories for expenses (an income
     * category has no expenses to compare with) and the active cost centers.
     *
     * @return array{kategorien: array<int, string>, kostenstellen: array<int, string>}
     */
    public function auswahl(): array
    {
        return [
            'kategorien' => $this->kategorien->active(CategoryDirection::Ausgabe),
            'kostenstellen' => $this->kostenstellen->active(),
        ];
    }

    private function kategorieAus(string $wert): ?int
    {
        $wert = trim($wert);
        if ($wert === '') {
            return null;
        }
        $kategorie = preg_match('/^[1-9]\d{0,18}$/', $wert) === 1 ? $this->kategorien->find((int) $wert) : null;
        if ($kategorie === null || $kategorie->direction === CategoryDirection::Einnahme) {
            throw new BudgetFehler('Diese Kategorie gibt es nicht oder sie ist eine reine Einnahmen-Kategorie.', 'kategorie');
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
        if ($kostenstelle === null) {
            throw new BudgetFehler('Diese Kostenstelle gibt es nicht.', 'kostenstelle');
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
