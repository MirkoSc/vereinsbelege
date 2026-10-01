<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankAccount;
use App\Domain\BankAccountData;
use App\Domain\BankAccountKind;
use App\Domain\BankAccountRecord;
use App\Domain\Iban;
use App\Repository\BankAccountRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\MasterData\SupplierKeys;
use App\Service\Processing\Betrag;

/**
 * Bank accounts and cash boxes (M9-1, issue #59, docs/spec/
 * 04-bank-und-abgleich.md section 1, docs/spec/02-datenmodell.md "Konten") -
 * the rules and the encryption, not the SQL:
 *
 * - Name and bank connection are vault data in `data_enc`, the opening
 *   balance in `opening_balance_enc`, both under the row's DEK with AAD
 *   "bank_account|<id>|<column>". Reading and writing need the unlocked
 *   vault of the session - writing too, because `iban_bi` is keyed from the
 *   vault's private key.
 * - The kind (bank account or cash box) is fixed once the account exists.
 *   A cash box has no bank connection and no negative opening balance.
 * - An IBAN belongs to one account only: a second one is refused with a
 *   pointer to the first.
 * - Deleting only while unused (BankAccountRepository::usageCount()),
 *   otherwise the account is deactivated.
 */
final readonly class BankAccountService
{
    public const int NAME_MAX = 100;

    public const int BANK_MAX = 100;

    /** Purpose of `bank_account.iban_bi` (docs/spec/01-sicherheit.md section 2) - never rename. */
    public const string IBAN_PURPOSE = 'bank_account.iban';

    /** Accounts are kept in euro; the currency is stored with every amount all the same (CLAUDE.md section 5). */
    public const string CURRENCY = 'EUR';

    private const string TABLE = 'bank_account';

    public function __construct(
        private \PDO $pdo,
        private BankAccountRepository $konten,
    ) {
    }

    /**
     * Every account, decrypted: active ones first, then bank accounts
     * before cash boxes, then by name - sorted in PHP, SQL cannot see the
     * names.
     *
     * @return list<BankAccount>
     */
    public function liste(Vault $vault): array
    {
        $alle = array_map(fn(BankAccountRecord $r): BankAccount => $this->entschluesseln($vault, $r), $this->konten->all());
        $rang = static fn(BankAccount $k): int => $k->kind === BankAccountKind::Bank ? 0 : 1;
        usort($alle, static fn(BankAccount $a, BankAccount $b): int => ($b->active <=> $a->active)
            ?: ($rang($a) <=> $rang($b))
            ?: strnatcasecmp($a->data->name, $b->data->name)
            ?: $a->id <=> $b->id);

        return $alle;
    }

    public function finde(Vault $vault, int $id): ?BankAccount
    {
        $record = $this->konten->find($id);

        return $record === null ? null : $this->entschluesseln($vault, $record);
    }

    /**
     * @param array<string, string> $felder the form, see felder() in App\App\AccountController
     *
     * @throws BankRuleViolation
     */
    public function anlegen(Vault $vault, BankAccountKind $art, array $felder, \DateTimeImmutable $now): BankAccountSaved
    {
        [$daten, $saldo, $stichtag] = $this->pruefe($art, $felder);
        $ibanBi = $this->ibanBi($vault, $daten);
        $this->pruefeEindeutig($vault, $ibanBi, null);

        $key = DataKey::generate();
        $this->pdo->beginTransaction();
        try {
            $id = $this->konten->insert($art, $vault->sealDataKey($key), $ibanBi, $stichtag, $now);
            $this->konten->setCiphertexts($id, self::verschluesseln($key, $id, $daten), self::verschluesselnSaldo($key, $id, $saldo));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        $felderMitWert = array_keys(array_filter($daten->toPayload(), static fn(string $v): bool => $v !== ''));

        return new BankAccountSaved($id, ['kind', ...$felderMitWert, 'opening_balance', 'opening_date']);
    }

    /**
     * @param array<string, string> $felder
     *
     * @throws BankRuleViolation
     */
    public function aendern(Vault $vault, int $id, array $felder, \DateTimeImmutable $now): BankAccountSaved
    {
        $record = $this->konten->find($id) ?? throw new BankRuleViolation('Dieses Konto gibt es nicht.');
        $vorher = $this->entschluesseln($vault, $record);

        [$daten, $saldo, $stichtag] = $this->pruefe($record->kind, $felder);
        $aktiv = ($felder['active'] ?? '') === '1';
        $ibanBi = $this->ibanBi($vault, $daten);
        $this->pruefeEindeutig($vault, $ibanBi, $id);

        // The row keeps its data key: re-sealing would gain nothing.
        $key = $vault->openDataKey($record->dekSealed);
        $this->konten->update(
            $id,
            self::verschluesseln($key, $id, $daten),
            $ibanBi,
            self::verschluesselnSaldo($key, $id, $saldo),
            $stichtag,
            $aktiv,
            $now,
        );

        $geaendert = [];
        $alt = $vorher->data->toPayload();
        foreach ($daten->toPayload() as $feld => $wert) {
            if ($alt[$feld] !== $wert) {
                $geaendert[] = $feld;
            }
        }
        if ($saldo !== $vorher->openingBalance) {
            $geaendert[] = 'opening_balance';
        }
        if ($stichtag->format('Y-m-d') !== $vorher->openingDate->format('Y-m-d')) {
            $geaendert[] = 'opening_date';
        }
        if ($aktiv !== $vorher->active) {
            $geaendert[] = 'active';
        }

        return new BankAccountSaved($id, $geaendert);
    }

    /**
     * @throws BankRuleViolation
     */
    public function loeschen(int $id): BankAccountRecord
    {
        $record = $this->konten->find($id) ?? throw new BankRuleViolation('Dieses Konto gibt es nicht.');
        if ($this->konten->usageCount($id) > 0) {
            throw new BankRuleViolation('Das Konto wird verwendet und lässt sich nicht löschen – bitte stattdessen deaktivieren.');
        }

        $this->konten->delete($id);

        return $record;
    }

    public function verwendungen(int $id): int
    {
        return $this->konten->usageCount($id);
    }

    private function entschluesseln(Vault $vault, BankAccountRecord $record): BankAccount
    {
        $key = $vault->openDataKey($record->dekSealed);
        $daten = self::json(FieldCipher::decrypt($key, $record->dataEnc, new FieldContext(self::TABLE, $record->id, 'data_enc')));
        $saldo = self::json(FieldCipher::decrypt($key, $record->openingBalanceEnc, new FieldContext(self::TABLE, $record->id, 'opening_balance_enc')));

        return new BankAccount(
            id: $record->id,
            kind: $record->kind,
            data: BankAccountData::fromPayload($daten),
            openingBalance: is_int($saldo['amount'] ?? null) ? $saldo['amount'] : 0,
            currency: is_string($saldo['currency'] ?? null) ? $saldo['currency'] : self::CURRENCY,
            openingDate: $record->openingDate,
            active: $record->active,
            createdAt: $record->createdAt,
            updatedAt: $record->updatedAt,
        );
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $json): array
    {
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload) ? $payload : [];
    }

    private static function verschluesseln(DataKey $key, int $id, BankAccountData $daten): string
    {
        return FieldCipher::encrypt(
            $key,
            json_encode($daten->toPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            new FieldContext(self::TABLE, $id, 'data_enc'),
        );
    }

    private static function verschluesselnSaldo(DataKey $key, int $id, int $saldo): string
    {
        return FieldCipher::encrypt(
            $key,
            json_encode(['amount' => $saldo, 'currency' => self::CURRENCY], JSON_THROW_ON_ERROR),
            new FieldContext(self::TABLE, $id, 'opening_balance_enc'),
        );
    }

    private function ibanBi(Vault $vault, BankAccountData $daten): ?string
    {
        return $daten->iban === '' ? null : $vault->blindIndex()->forValue(self::IBAN_PURPOSE, $daten->iban);
    }

    /**
     * @throws BankRuleViolation
     */
    private function pruefeEindeutig(Vault $vault, ?string $ibanBi, ?int $id): void
    {
        if ($ibanBi === null) {
            return;
        }
        $anderes = $this->konten->idWithIban($ibanBi, $id);
        if ($anderes === null) {
            return;
        }

        throw new BankRuleViolation(
            sprintf('Diese IBAN gehört bereits zum Konto „%s“. Eine IBAN gehört zu genau einem Konto.', $this->finde($vault, $anderes)?->data->name ?? '?'),
            'iban',
            $anderes,
        );
    }

    /**
     * @param array<string, string> $felder
     *
     * @return array{BankAccountData, int, \DateTimeImmutable}
     *
     * @throws BankRuleViolation
     */
    private function pruefe(BankAccountKind $art, array $felder): array
    {
        $feld = static fn(string $name): string => trim($felder[$name] ?? '');

        $name = $feld('name');
        if ($name === '') {
            throw new BankRuleViolation('Bitte einen Namen angeben.', 'name');
        }
        self::laenge($name, self::NAME_MAX, 'Der Name', 'name');

        $iban = Iban::normalisieren($feld('iban'));
        $bic = SupplierKeys::kennung($feld('bic'));
        $bank = $feld('bank');
        if (!$art->hatBankverbindung()) {
            if ($iban !== '' || $bic !== '' || $bank !== '') {
                throw new BankRuleViolation('Eine Kasse hat keine Bankverbindung.', 'iban');
            }
        } else {
            if ($iban !== '' && !Iban::istGueltig($iban)) {
                throw new BankRuleViolation('Die IBAN ist ungültig – bitte Länge und Prüfziffer kontrollieren.', 'iban');
            }
            if ($bic !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) !== 1) {
                throw new BankRuleViolation('Die BIC ist ungültig (8 oder 11 Zeichen).', 'bic');
            }
            self::laenge($bank, self::BANK_MAX, 'Der Name der Bank', 'bank');
        }

        $saldo = Betrag::parse($feld('opening_balance'))
            ?? throw new BankRuleViolation('Den Anfangssaldo bitte als Betrag wie 1.234,56 angeben (höchstens zwei Nachkommastellen).', 'opening_balance');
        if ($saldo < 0 && !$art->darfNegativSein()) {
            throw new BankRuleViolation('Der Anfangsbestand einer Kasse kann nicht negativ sein.', 'opening_balance');
        }

        $wert = $feld('opening_date');
        $stichtag = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);
        if ($stichtag === false || $stichtag->format('Y-m-d') !== $wert) {
            throw new BankRuleViolation('Bitte den Stichtag des Anfangssaldos als Datum angeben.', 'opening_date');
        }

        return [new BankAccountData(name: $name, iban: $iban, bic: $bic, bank: $bank), $saldo, $stichtag];
    }

    /**
     * @throws BankRuleViolation
     */
    private static function laenge(string $wert, int $max, string $was, string $feld): void
    {
        if (mb_strlen($wert) > $max) {
            throw new BankRuleViolation(sprintf('%s darf höchstens %d Zeichen lang sein.', $was, $max), $feld);
        }
    }
}
