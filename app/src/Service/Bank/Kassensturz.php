<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankAccount;
use App\Domain\BankAccountKind;
use App\Domain\CashCount;
use App\Domain\CashCountRecord;
use App\Domain\Zugriffsbereich;
use App\Repository\CashCountRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Processing\Betrag;

/**
 * Cash counts of a cash box (M9-1, issue #59, docs/spec/
 * 04-bank-und-abgleich.md section 1 "Kassensturz"): enter what is in the
 * box, see the difference to what should be in it, record both.
 *
 * - The expected amount is computed here, never taken from the form.
 * - A count is append-only: stored with the figures of its time, never
 *   changed or deleted. A miscount is followed by a new count.
 * - Expected, counted amount and note are vault data (`cash_count.data_enc`,
 *   own DEK, AAD "cash_count|<id>|data_enc"); the date is plaintext so the
 *   period scope of external roles can filter on it in SQL.
 * - The difference is not booked by the count itself: the page suggests
 *   a manual booking "Kassendifferenz" (M9-5, issue #63, App\Service\Bank\
 *   Buchungen), and offeneDifferenz() says whether the books still differ
 *   from the latest count.
 */
final readonly class Kassensturz
{
    public const int NOTE_MAX = 500;

    private const string TABLE = 'cash_count';

    private const string COLUMN = 'data_enc';

    public function __construct(
        private \PDO $pdo,
        private CashCountRepository $kassenstuerze,
        private Buchungen $buchungen,
    ) {
    }

    /**
     * What the cash box should hold at the end of $stichtag: the opening
     * balance plus the cash bookings from the opening date up to and
     * including that day (M9-5) - decrypted, so it needs the vault.
     */
    public function sollBestand(Vault $vault, BankAccount $kasse, \DateTimeImmutable $stichtag): int
    {
        return $kasse->openingBalance + $this->buchungen->summe($vault, $kasse->id, $kasse->openingDate, $stichtag);
    }

    /**
     * Counted minus what the books say today for the day of that count: what
     * is still unexplained. Zero once the difference has been booked (or the
     * missing bookings entered); a stored count keeps its own figures.
     */
    public function offeneDifferenz(Vault $vault, BankAccount $kasse, CashCount $zaehlung): int
    {
        return $zaehlung->counted - $this->sollBestand($vault, $kasse, $zaehlung->countedOn);
    }

    /** The cash box a count belongs to, or null when there is no such count. */
    public function kasseVon(int $id): ?int
    {
        return $this->kassenstuerze->find($id)?->accountId;
    }

    /** One count of this cash box, or null. */
    public function finde(Vault $vault, BankAccount $kasse, int $id): ?CashCount
    {
        $record = $this->kassenstuerze->find($id);

        return $record === null || $record->accountId !== $kasse->id ? null : self::entschluesseln($vault, $record);
    }

    /**
     * Checks the form and computes the difference without storing anything.
     *
     * @param array<string, string> $felder datum (Y-m-d), ist, notiz
     *
     * @throws BankRuleViolation
     */
    public function pruefen(Vault $vault, BankAccount $kasse, array $felder, \DateTimeImmutable $heute): KassensturzVorschau
    {
        if ($kasse->kind !== BankAccountKind::Kasse) {
            throw new BankRuleViolation('Einen Kassensturz gibt es nur für eine Kasse.');
        }
        if (!$kasse->active) {
            throw new BankRuleViolation('Die Kasse ist deaktiviert – ein Kassensturz ist nur für eine aktive Kasse möglich.');
        }

        $feld = static fn(string $name): string => trim($felder[$name] ?? '');

        $wert = $feld('datum');
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);
        if ($datum === false || $datum->format('Y-m-d') !== $wert) {
            throw new BankRuleViolation('Bitte das Datum des Kassensturzes angeben.', 'datum');
        }
        if ($wert < $kasse->openingDate->format('Y-m-d')) {
            throw new BankRuleViolation(
                sprintf('Der Kassensturz kann nicht vor dem Stichtag des Anfangsbestands (%s) liegen.', $kasse->openingDate->format('d.m.Y')),
                'datum',
            );
        }
        if ($wert > $heute->format('Y-m-d')) {
            throw new BankRuleViolation('Der Kassensturz kann nicht in der Zukunft liegen.', 'datum');
        }

        $ist = Betrag::parse($feld('ist'))
            ?? throw new BankRuleViolation('Den gezählten Bestand bitte als Betrag wie 123,45 angeben (höchstens zwei Nachkommastellen).', 'ist');
        if ($ist < 0) {
            throw new BankRuleViolation('Der gezählte Bestand kann nicht negativ sein.', 'ist');
        }

        $notiz = $feld('notiz');
        if (mb_strlen($notiz) > self::NOTE_MAX) {
            throw new BankRuleViolation(sprintf('Die Notiz darf höchstens %d Zeichen lang sein.', self::NOTE_MAX), 'notiz');
        }

        return new KassensturzVorschau($datum, $this->sollBestand($vault, $kasse, $datum), $ist, $notiz);
    }

    /**
     * Checks the form like pruefen() and records the count.
     *
     * @param array<string, string> $felder
     *
     * @return int the id of the new `cash_count` row
     *
     * @throws BankRuleViolation
     */
    public function erfassen(Vault $vault, BankAccount $kasse, array $felder, ?int $userId, \DateTimeImmutable $now): int
    {
        $vorschau = $this->pruefen($vault, $kasse, $felder, $now);

        $key = DataKey::generate();
        $this->pdo->beginTransaction();
        try {
            $id = $this->kassenstuerze->insert($kasse->id, $vorschau->datum, $vault->sealDataKey($key), $userId, $now);
            $this->kassenstuerze->setData($id, FieldCipher::encrypt(
                $key,
                json_encode([
                    'expected' => $vorschau->soll,
                    'counted' => $vorschau->ist,
                    'currency' => $kasse->currency,
                    'note' => $vorschau->notiz,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                new FieldContext(self::TABLE, $id, self::COLUMN),
            ));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $id;
    }

    /**
     * The counts of one cash box the reader may see, newest first.
     *
     * @return list<CashCount>
     */
    public function liste(Vault $vault, BankAccount $kasse, Zugriffsbereich $bereich): array
    {
        return array_map(
            fn(CashCountRecord $r): CashCount => self::entschluesseln($vault, $r),
            $this->kassenstuerze->forAccount($kasse->id, $bereich),
        );
    }

    private static function entschluesseln(Vault $vault, CashCountRecord $record): CashCount
    {
        $json = FieldCipher::decrypt(
            $vault->openDataKey($record->dekSealed),
            $record->dataEnc,
            new FieldContext(self::TABLE, $record->id, self::COLUMN),
        );
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $payload = is_array($payload) ? $payload : [];
        $cent = static fn(string $key): int => is_int($payload[$key] ?? null) ? $payload[$key] : 0;

        return new CashCount(
            id: $record->id,
            accountId: $record->accountId,
            countedOn: $record->countedOn,
            expected: $cent('expected'),
            counted: $cent('counted'),
            currency: is_string($payload['currency'] ?? null) ? $payload['currency'] : BankAccountService::CURRENCY,
            note: is_string($payload['note'] ?? null) ? $payload['note'] : '',
            createdBy: $record->createdBy,
            createdAt: $record->createdAt,
        );
    }
}
