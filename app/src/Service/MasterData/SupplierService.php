<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\Iban;
use App\Domain\Supplier;
use App\Domain\SupplierData;
use App\Domain\SupplierKeyKind;
use App\Domain\SupplierOrigin;
use App\Domain\SupplierRecord;
use App\Domain\SupplierRole;
use App\Repository\CategoryRepository;
use App\Repository\SupplierRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * Suppliers and payers (M6-2, issue #36, docs/spec/03-erfassung-und-ki.md
 * section 7, docs/spec/02-datenmodell.md "Lieferanten") - the rules and the
 * encryption, not the SQL:
 *
 * - Everything but the structure is vault data: `data_enc` under the row's
 *   DEK, AAD "supplier|<id>|data_enc". Reading and writing both need the
 *   unlocked vault of the session - writing too, because the blind indexes
 *   in `supplier_key` are keyed from the vault's private key.
 * - `supplier_key` is rewritten from the saved data on every save
 *   (App\Service\MasterData\SupplierKeys decides which values, normalised
 *   how).
 * - An IBAN, VAT id, tax number or creditor id belongs to one supplier
 *   only: a second one is refused with a pointer to the first. The same
 *   name or alias is allowed and reported, so the page can hint at a
 *   duplicate.
 * - The default category must exist, be active (unless it is the one
 *   already stored) and fit the role: expenses for a supplier, income for a
 *   payer.
 * - Deleting only while unused (SupplierRepository::usageCount()).
 * - A supplier merged into another (App\Service\MasterData\
 *   SupplierZusammenfuehrung, issue #39/M6-5) is neither changed nor
 *   deleted.
 */
final readonly class SupplierService
{
    public const int NAME_MAX = 200;

    public const int TEXT_MAX = 200;

    public const int ADDRESS_MAX = 500;

    public const int NOTES_MAX = 2000;

    /** Per multi-value field: aliases, IBANs, mandate references. */
    public const int LIST_MAX = 20;

    private const string TABLE = 'supplier';

    private const string COLUMN = 'data_enc';

    public function __construct(
        private \PDO $pdo,
        private SupplierRepository $lieferanten,
        private CategoryRepository $kategorien,
    ) {
    }

    /**
     * Every supplier (not merged away), decrypted, sorted by name. The
     * search looks at name, aliases and IBANs - in PHP, after decrypting:
     * a club has a few hundred suppliers, and SQL cannot see into
     * `data_enc` anyway.
     *
     * @return list<Supplier>
     */
    public function liste(Vault $vault, ?SupplierRole $rolle = null, string $suche = ''): array
    {
        $alle = array_map(fn(SupplierRecord $r): Supplier => $this->entschluesseln($vault, $r), $this->lieferanten->all($rolle));

        $suche = SupplierKeys::umschrift($suche);
        if ($suche !== '') {
            $alle = array_values(array_filter($alle, static function (Supplier $s) use ($suche): bool {
                foreach ([$s->data->name, ...$s->data->aliases] as $name) {
                    if (str_contains(SupplierKeys::umschrift($name), $suche)) {
                        return true;
                    }
                }
                $iban = Iban::normalisieren($suche);
                foreach ($s->data->ibans as $vorhanden) {
                    if (str_contains($vorhanden, $iban)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        usort($alle, static fn(Supplier $a, Supplier $b): int => strnatcmp(SupplierKeys::umschrift($a->data->name), SupplierKeys::umschrift($b->data->name)) ?: $a->id <=> $b->id);

        return $alle;
    }

    public function finde(Vault $vault, int $id): ?Supplier
    {
        $record = $this->lieferanten->find($id);

        return $record === null ? null : $this->entschluesseln($vault, $record);
    }

    /**
     * @param array<string, string> $felder the form, see felder() in App\App\SupplierController
     *
     * @throws SupplierRuleViolation
     */
    public function anlegen(Vault $vault, array $felder, \DateTimeImmutable $now): SupplierSaved
    {
        [$rolle, $kategorie, $daten] = $this->pruefe($felder, null);
        $keys = $this->keys($vault, $daten);
        $this->pruefeEindeutig($vault, $keys, []);

        $key = DataKey::generate();
        $this->pdo->beginTransaction();
        try {
            $id = $this->lieferanten->insert($rolle, $kategorie, SupplierOrigin::Manuell, $vault->sealDataKey($key), $now);
            $this->lieferanten->setData($id, self::verschluesseln($key, $id, $daten));
            $this->lieferanten->replaceKeys($id, $keys);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        $felderMitWert = array_keys(array_filter($daten->toPayload(), static fn(string|array $v): bool => $v !== '' && $v !== []));

        return new SupplierSaved(
            $id,
            ['role', ...($kategorie === null ? [] : ['default_category_id']), ...$felderMitWert],
            $this->namensgleich($keys, $id),
        );
    }

    /**
     * @param array<string, string> $felder
     *
     * @throws SupplierRuleViolation
     */
    public function aendern(Vault $vault, int $id, array $felder, \DateTimeImmutable $now): SupplierSaved
    {
        $record = $this->lieferanten->find($id) ?? throw new SupplierRuleViolation('Diesen Lieferanten gibt es nicht.');
        self::nichtZusammengefuehrt($record);
        $vorher = $this->entschluesseln($vault, $record);

        [$rolle, $kategorie, $daten] = $this->pruefe($felder, $record->defaultCategoryId);
        $keys = $this->keys($vault, $daten);
        $this->pruefeEindeutig($vault, $keys, [$id]);

        // The row keeps its data key: re-sealing would gain nothing, and the
        // audit and later tables never see it anyway.
        $key = $vault->openDataKey($record->dekSealed);
        $this->pdo->beginTransaction();
        try {
            $this->lieferanten->update($id, $rolle, $kategorie, self::verschluesseln($key, $id, $daten), $now);
            $this->lieferanten->replaceKeys($id, $keys);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        $geaendert = [];
        if ($rolle !== $record->role) {
            $geaendert[] = 'role';
        }
        if ($kategorie !== $record->defaultCategoryId) {
            $geaendert[] = 'default_category_id';
        }
        $alt = $vorher->data->toPayload();
        foreach ($daten->toPayload() as $feld => $wert) {
            if ($alt[$feld] !== $wert) {
                $geaendert[] = $feld;
            }
        }

        return new SupplierSaved($id, $geaendert, $this->namensgleich($keys, $id));
    }

    /**
     * @throws SupplierRuleViolation
     */
    public function loeschen(int $id): SupplierRecord
    {
        $record = $this->lieferanten->find($id) ?? throw new SupplierRuleViolation('Diesen Lieferanten gibt es nicht.');
        // A merged supplier stays: locked receipts and the audit log refer to it.
        self::nichtZusammengefuehrt($record);
        if ($this->lieferanten->usageCount($id) > 0) {
            throw new SupplierRuleViolation('Der Lieferant wird verwendet und lässt sich nicht löschen.');
        }

        $this->lieferanten->delete($id);

        return $record;
    }

    public function verwendungen(int $id): int
    {
        return $this->lieferanten->usageCount($id);
    }

    /**
     * @throws SupplierRuleViolation
     */
    public static function nichtZusammengefuehrt(SupplierRecord $record): void
    {
        if ($record->mergedInto !== null) {
            throw new SupplierRuleViolation('Dieser Lieferant wurde bereits mit einem anderen zusammengeführt.', $record->mergedInto);
        }
    }

    public function entschluesseln(Vault $vault, SupplierRecord $record): Supplier
    {
        $json = FieldCipher::decrypt(
            $vault->openDataKey($record->dekSealed),
            $record->dataEnc,
            new FieldContext(self::TABLE, $record->id, self::COLUMN),
        );
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new Supplier(
            id: $record->id,
            role: $record->role,
            data: SupplierData::fromPayload(is_array($payload) ? $payload : []),
            defaultCategoryId: $record->defaultCategoryId,
            createdVia: $record->createdVia,
            needsReview: $record->needsReview,
            createdAt: $record->createdAt,
            updatedAt: $record->updatedAt,
            mergedInto: $record->mergedInto,
        );
    }

    public static function verschluesseln(DataKey $key, int $id, SupplierData $daten): string
    {
        return FieldCipher::encrypt(
            $key,
            json_encode($daten->toPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            new FieldContext(self::TABLE, $id, self::COLUMN),
        );
    }

    /**
     * @return list<array{SupplierKeyKind, string}> kind and blind index
     */
    public function keys(Vault $vault, SupplierData $daten): array
    {
        $index = $vault->blindIndex();

        return array_map(
            static fn(array $key): array => [$key[0], $index->forValue($key[0]->purpose(), $key[1])],
            SupplierKeys::fuer($daten),
        );
    }

    /**
     * @param list<array{SupplierKeyKind, string}> $keys
     * @param list<int> $ausser the supplier(s) the keys are meant for
     *
     * @throws SupplierRuleViolation
     */
    public function pruefeEindeutig(Vault $vault, array $keys, array $ausser): void
    {
        foreach ($keys as [$kind, $bi]) {
            if (!$kind->eindeutig()) {
                continue;
            }
            $andere = array_values(array_diff($this->lieferanten->idsWithKey($kind, $bi), $ausser));
            if ($andere === []) {
                continue;
            }

            $anderer = $this->finde($vault, $andere[0]);
            throw new SupplierRuleViolation(
                sprintf(
                    'Diese %s ist bereits beim Lieferanten „%s“ hinterlegt. Eine Kennung gehört zu genau einem Lieferanten – doppelte Lieferanten werden zusammengeführt.',
                    $kind->label(),
                    $anderer?->data->name ?? '?',
                ),
                $andere[0],
            );
        }
    }

    /**
     * @param list<array{SupplierKeyKind, string}> $keys
     */
    private function namensgleich(array $keys, int $id): bool
    {
        foreach ($keys as [$kind, $bi]) {
            if ($kind === SupplierKeyKind::Name && $this->lieferanten->idsWithKey($kind, $bi, $id) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $felder
     *
     * @return array{SupplierRole, int|null, SupplierData}
     *
     * @throws SupplierRuleViolation
     */
    private function pruefe(array $felder, ?int $bisherigeKategorie): array
    {
        $feld = static fn(string $name): string => trim($felder[$name] ?? '');

        $name = $feld('name');
        if ($name === '') {
            throw new SupplierRuleViolation('Bitte einen Namen angeben.');
        }
        self::laenge($name, self::NAME_MAX, 'Der Name');

        $rolle = SupplierRole::tryFrom($feld('rolle')) ?? throw new SupplierRuleViolation('Bitte wählen, ob Lieferant oder Zahler.');
        $kategorie = $this->kategorie($feld('kategorie'), $rolle, $bisherigeKategorie);

        $aliases = [];
        foreach (self::zeilen($felder['aliases'] ?? '', 'Aliasse') as $alias) {
            self::laenge($alias, self::NAME_MAX, 'Ein Alias');
            $aliases[mb_strtolower($alias)] ??= $alias;
        }
        unset($aliases[mb_strtolower($name)]);

        $ibans = [];
        foreach (self::zeilen($felder['ibans'] ?? '', 'IBANs') as $i => $iban) {
            if (!Iban::istGueltig($iban)) {
                throw new SupplierRuleViolation(sprintf('Die %d. IBAN ist ungültig – bitte Länge und Prüfziffer kontrollieren.', $i + 1));
            }
            $ibans[Iban::normalisieren($iban)] = true;
        }

        $bic = SupplierKeys::kennung($feld('bic'));
        if ($bic !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) !== 1) {
            throw new SupplierRuleViolation('Die BIC ist ungültig (8 oder 11 Zeichen).');
        }

        $ustId = SupplierKeys::kennung($feld('vat_id'));
        if ($ustId !== '' && preg_match('/^[A-Z]{2}[A-Z0-9+*]{2,13}$/', $ustId) !== 1) {
            throw new SupplierRuleViolation('Die USt-ID ist ungültig (Ländercode und bis zu 13 Zeichen, z. B. DE123456789).');
        }

        $glaeubigerId = SupplierKeys::kennung($feld('creditor_id'));
        if ($glaeubigerId !== '' && preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{3}[A-Z0-9]{1,28}$/', $glaeubigerId) !== 1) {
            throw new SupplierRuleViolation('Die Gläubiger-ID ist ungültig (z. B. DE98ZZZ09999999999).');
        }

        $email = $feld('email');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SupplierRuleViolation('Die E-Mail-Adresse ist ungültig.');
        }

        $mandate = [];
        foreach (self::zeilen($felder['mandate_refs'] ?? '', 'Mandatsreferenzen') as $mandat) {
            self::laenge($mandat, 35, 'Eine Mandatsreferenz');
            $mandate[SupplierKeys::kennung($mandat)] ??= $mandat;
        }

        $steuernummer = $feld('tax_number');
        $website = $feld('website');
        $kundennummer = $feld('customer_number');
        foreach ([[$steuernummer, 'Die Steuernummer'], [$website, 'Die Website'], [$kundennummer, 'Die Kundennummer']] as [$wert, $was]) {
            self::laenge($wert, self::TEXT_MAX, $was);
        }
        self::laenge($feld('address'), self::ADDRESS_MAX, 'Die Anschrift');
        self::laenge($feld('notes'), self::NOTES_MAX, 'Die Notiz');

        return [$rolle, $kategorie, new SupplierData(
            name: $name,
            aliases: array_values($aliases),
            address: $feld('address'),
            ibans: array_map(strval(...), array_keys($ibans)),
            bic: $bic,
            vatId: $ustId,
            taxNumber: $steuernummer,
            email: $email,
            website: $website,
            creditorId: $glaeubigerId,
            mandateRefs: array_values($mandate),
            customerNumber: $kundennummer,
            notes: $feld('notes'),
        )];
    }

    /**
     * @throws SupplierRuleViolation
     */
    private function kategorie(string $wert, SupplierRole $rolle, ?int $bisher): ?int
    {
        if ($wert === '') {
            return null;
        }

        $kategorie = ctype_digit($wert) ? $this->kategorien->find((int) $wert) : null;
        if ($kategorie === null) {
            throw new SupplierRuleViolation('Diese Kategorie gibt es nicht.');
        }
        if (!$kategorie->active && $kategorie->id !== $bisher) {
            throw new SupplierRuleViolation('Diese Kategorie ist deaktiviert.');
        }
        if (!$rolle->passtZu($kategorie->direction)) {
            throw new SupplierRuleViolation(sprintf(
                'Die Kategorie „%s“ passt nicht zur Rolle – ein Lieferant bekommt eine Ausgaben-, ein Zahler eine Einnahmen-Kategorie.',
                $kategorie->name,
            ));
        }

        return $kategorie->id;
    }

    /**
     * One value per line, trimmed, empty lines dropped.
     *
     * @return list<string>
     *
     * @throws SupplierRuleViolation
     */
    private static function zeilen(string $text, string $was): array
    {
        $zeilen = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', $text) ?: []),
            static fn(string $zeile): bool => $zeile !== '',
        ));
        if (count($zeilen) > self::LIST_MAX) {
            throw new SupplierRuleViolation(sprintf('Höchstens %d %s je Lieferant.', self::LIST_MAX, $was));
        }

        return $zeilen;
    }

    /**
     * @throws SupplierRuleViolation
     */
    private static function laenge(string $wert, int $max, string $was): void
    {
        if (mb_strlen($wert) > $max) {
            throw new SupplierRuleViolation(sprintf('%s darf höchstens %d Zeichen lang sein.', $was, $max));
        }
    }
}
