<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankAccount;
use App\Domain\BankTransaction;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionRecord;
use App\Domain\BankTransactionSetBy;
use App\Domain\BankTransactionSource;
use App\Domain\CategoryDirection;
use App\Domain\Zugriffsbereich;
use App\Repository\BankTransactionRepository;
use App\Repository\CategoryRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Processing\Betrag;

/**
 * Bookings on accounts and cash boxes (M9-5, issue #63, docs/spec/
 * 04-bank-und-abgleich.md section 1, E-17) - the list and the bookings
 * entered by hand, rules and encryption, not the SQL:
 *
 * - A manual booking needs date, amount, direction, category and purpose;
 *   the counterparty is optional. The amount is entered positive, the
 *   direction gives the sign it is stored with (`data_enc.amount`, like an
 *   imported booking).
 * - Its date lies neither in the future nor before the opening date of
 *   the account - the balance counts bookings from that day on. Only an
 *   active account takes new bookings.
 * - The category fits the direction (or is one for both). A deactivated
 *   category is not offered any more, but a booking keeps the one it has.
 * - Whether a receipt is needed: as chosen, otherwise the default of the
 *   direction (E-17: an expense needs one, income does not unless the
 *   setting says so - App\Service\Bank\BelegStandard, M9-6). Income
 *   without a receipt is a normal, fully valid booking.
 * - Only manual bookings are changed or deleted; an imported booking is
 *   what the bank says - only its category and receipt status can be set
 *   by hand (einordnen(), M9-6), which no rule overrides afterwards.
 * - Like the statement import: `data_enc` under the row's own DEK, AAD
 *   "bank_transaction|<id>|data_enc", written in two steps inside one
 *   transaction; `dedup_bi` and `counterparty_bi` stay NULL - a manual
 *   booking has no file to be imported twice from and no IBAN.
 *
 * Every method that reads or writes amounts needs the unlocked vault.
 */
final readonly class Buchungen
{
    public const int ZWECK_MAX = 300;

    public const int GEGENSEITE_MAX = 100;

    /** Purpose a cash count difference is suggested with (04 section 1). */
    public const string KASSENDIFFERENZ = 'Kassendifferenz';

    /** Form value of `beleg`: needed / not needed; anything else is the direction's default. */
    public const string BELEG_NOETIG = '1';

    public const string BELEG_NICHT_NOETIG = '0';

    private const string TABLE = 'bank_transaction';

    private const string COLUMN = 'data_enc';

    public function __construct(
        private \PDO $pdo,
        private BankTransactionRepository $buchungen,
        private BankAccountService $konten,
        private CategoryRepository $kategorien,
        private BelegStandard $belegStandard = new BelegStandard(),
    ) {
    }

    /**
     * The bookings matching the filter within the reader's scope, newest
     * first, decrypted.
     *
     * @return list<BankTransaction>
     */
    public function liste(Vault $vault, BuchungFilter $filter, Zugriffsbereich $bereich): array
    {
        $treffer = [];
        foreach ($this->buchungen->liste($filter, $bereich) as $record) {
            $buchung = self::entschluesseln($vault, $record);
            if ($filter->passtZurSuche($buchung->purpose, $buchung->counterpartyName, $buchung->bookingText)) {
                $treffer[] = $buchung;
            }
        }

        return $treffer;
    }

    /** One booking, if it is within the reader's scope. */
    public function finde(Vault $vault, int $id, Zugriffsbereich $bereich): ?BankTransaction
    {
        $record = $this->buchungen->find($id);
        if ($record === null || !$bereich->erlaubt(null, $record->bookingDate)) {
            return null;
        }

        return self::entschluesseln($vault, $record);
    }

    /**
     * Sum in cents of the account's bookings with $von <= date <= $bis -
     * the opening balance plus this is the balance at the end of $bis.
     */
    public function summe(Vault $vault, int $kontoId, \DateTimeImmutable $von, \DateTimeImmutable $bis): int
    {
        $summe = 0;
        foreach ($this->buchungen->between($kontoId, $von, $bis->modify('+1 day')) as $record) {
            $summe += self::entschluesseln($vault, $record)->amount;
        }

        return $summe;
    }

    /**
     * Records a manual booking. Returns its id.
     *
     * @param array<string, string> $felder konto, datum, betrag, richtung, kategorie, zweck, gegenseite, beleg
     *
     * @throws BankRuleViolation
     */
    public function anlegen(Vault $vault, array $felder, \DateTimeImmutable $now): int
    {
        $eingabe = $this->pruefen($vault, $felder, null, $now);

        $key = DataKey::generate();
        $this->pdo->beginTransaction();
        try {
            $id = $this->buchungen->insert(
                $eingabe->konto->id,
                null,
                $eingabe->datum,
                null,
                $eingabe->richtung,
                $vault->sealDataKey($key),
                null,
                null,
                BankTransactionDocStatus::fuerNeueBuchung($eingabe->belegNoetig),
                $eingabe->belegNoetig,
                BankTransactionSource::Manuell,
                $now,
                $eingabe->kategorieId,
                docSource: $eingabe->belegGewaehlt ? BankTransactionSetBy::Manuell : BankTransactionSetBy::Standard,
                categorySource: BankTransactionSetBy::Manuell,
            ) ?? throw new \LogicException('A manual booking has no dedup key and cannot collide.');
            $this->buchungen->setCiphertext($id, self::verschluesseln($key, $id, $eingabe->daten($eingabe->konto->currency)));
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
     * Changes a manual booking. Returns the names of the changed fields -
     * empty when nothing changed, and then nothing is written.
     *
     * @param array<string, string> $felder as for anlegen()
     *
     * @return list<string>
     *
     * @throws BankRuleViolation
     */
    public function aendern(Vault $vault, BankTransaction $alt, array $felder, \DateTimeImmutable $now): array
    {
        if (!$alt->istManuell()) {
            throw new BankRuleViolation('Eine Buchung aus einem Kontoauszug lässt sich nicht ändern.');
        }
        $neu = $this->pruefen($vault, $felder, $alt, $now);

        $geaendert = array_keys(array_filter([
            'account_id' => $neu->konto->id !== $alt->accountId,
            'booking_date' => $neu->datum->format('Y-m-d') !== $alt->bookingDate->format('Y-m-d'),
            'amount' => $neu->cent() !== $alt->amount,
            'direction' => $neu->richtung !== $alt->direction,
            'category_id' => $neu->kategorieId !== $alt->categoryId,
            'purpose' => $neu->zweck !== $alt->purpose,
            'counterparty_name' => $neu->gegenseite !== $alt->counterpartyName,
            'doc_required' => $neu->belegNoetig !== $alt->docRequired,
        ]));
        if ($geaendert === []) {
            return [];
        }

        // A receipt already allocated (M10) stays allocated; otherwise the
        // status follows the choice.
        $status = $alt->docStatus === BankTransactionDocStatus::Zugeordnet
            ? BankTransactionDocStatus::Zugeordnet
            : BankTransactionDocStatus::fuerNeueBuchung($neu->belegNoetig);

        $record = $this->buchungen->find($alt->id) ?? throw new BankRuleViolation('Diese Buchung gibt es nicht mehr.');
        $this->pdo->beginTransaction();
        try {
            $this->buchungen->updateManual(
                $alt->id,
                $neu->konto->id,
                $neu->datum,
                $neu->richtung,
                $neu->kategorieId,
                $neu->belegNoetig,
                $status,
                $neu->belegGewaehlt ? BankTransactionSetBy::Manuell : BankTransactionSetBy::Standard,
                $now,
            );
            $this->buchungen->setCiphertext($alt->id, self::verschluesseln($vault->openDataKey($record->dekSealed), $alt->id, $neu->daten($alt->currency)));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return array_values($geaendert);
    }

    /**
     * @throws BankRuleViolation
     */
    public function loeschen(BankTransaction $buchung): void
    {
        if (!$buchung->istManuell()) {
            throw new BankRuleViolation('Eine Buchung aus einem Kontoauszug lässt sich nicht löschen.');
        }
        if ($buchung->docStatus === BankTransactionDocStatus::Zugeordnet) {
            throw new BankRuleViolation('Der Buchung ist ein Beleg zugeordnet – erst die Zuordnung lösen.');
        }
        $this->buchungen->deleteManual($buchung->id);
    }

    /**
     * Sets category and receipt status of an imported booking by hand
     * (M9-6). A field whose value does not change keeps where it came from
     * - saving the form unchanged does not turn a rule's work into a
     * person's. A changed field counts as set by hand, and no rule touches
     * it again; "nach Standard" hands the receipt status back to the
     * default of the direction. An allocated receipt (M10) stays allocated.
     * Returns the names of the changed fields - empty when nothing changed,
     * and then nothing is written.
     *
     * @param array<string, string> $felder kategorie ('' = none), beleg ('' = default, BELEG_NOETIG, BELEG_NICHT_NOETIG)
     *
     * @return list<string>
     *
     * @throws BankRuleViolation
     */
    public function einordnen(BankTransaction $buchung, array $felder, \DateTimeImmutable $now): array
    {
        if ($buchung->istManuell()) {
            throw new BankRuleViolation('Eine manuelle Buchung wird über ihr Formular geändert.');
        }
        $wert = static fn(string $name): string => trim($felder[$name] ?? '');

        $kategorieId = $wert('kategorie') === '' ? null : $this->kategorie($wert('kategorie'), $buchung->direction, $buchung);
        $kategorieGeaendert = $kategorieId !== $buchung->categoryId;
        $kategorieQuelle = match (true) {
            !$kategorieGeaendert => $buchung->categorySource,
            $kategorieId === null => null,
            default => BankTransactionSetBy::Manuell,
        };

        [$belegNoetig, $belegQuelle] = match ($wert('beleg')) {
            self::BELEG_NOETIG => [true, BankTransactionSetBy::Manuell],
            self::BELEG_NICHT_NOETIG => [false, BankTransactionSetBy::Manuell],
            '' => [$this->belegStandard->noetig($buchung->direction), BankTransactionSetBy::Standard],
            default => throw new BankRuleViolation('Bitte wählen, ob ein Beleg nötig ist.', 'beleg'),
        };
        $belegGeaendert = $belegNoetig !== $buchung->docRequired
            || ($belegQuelle === BankTransactionSetBy::Standard) !== ($buchung->docSource === BankTransactionSetBy::Standard);
        if (!$belegGeaendert) {
            $belegQuelle = $buchung->docSource;
        }

        $geaendert = array_keys(array_filter(['category_id' => $kategorieGeaendert, 'doc_required' => $belegGeaendert]));
        if ($geaendert === []) {
            return [];
        }

        // The rule stays named as long as it still accounts for one of the two.
        $regelId = $kategorieQuelle === BankTransactionSetBy::Regel || $belegQuelle === BankTransactionSetBy::Regel ? $buchung->ruleId : null;
        $status = $buchung->docStatus === BankTransactionDocStatus::Zugeordnet
            ? BankTransactionDocStatus::Zugeordnet
            : BankTransactionDocStatus::fuerNeueBuchung($belegNoetig);
        $this->buchungen->einordnen($buchung->id, $kategorieId, $kategorieQuelle, $belegNoetig, $status, $belegQuelle, $regelId, $now);

        return array_values($geaendert);
    }

    /** Whether income needs a receipt by default - for the labels of the forms. */
    public function belegStandard(): BelegStandard
    {
        return $this->belegStandard;
    }

    /**
     * The id of the active category with this name that fits the direction,
     * for a suggestion (Kassendifferenz) - null when there is none.
     */
    public function kategorieMitNamen(string $name, BankTransactionDirection $richtung): ?int
    {
        $id = array_search($name, $this->kategorien->active(self::kategorieRichtung($richtung)), true);

        return is_int($id) ? $id : null;
    }

    /**
     * Checks the form against the rules of a manual booking.
     *
     * @param array<string, string> $felder
     *
     * @throws BankRuleViolation
     */
    private function pruefen(Vault $vault, array $felder, ?BankTransaction $alt, \DateTimeImmutable $heute): BuchungEingabe
    {
        $feld = static fn(string $name): string => trim($felder[$name] ?? '');

        $kontoId = ctype_digit($feld('konto')) ? (int) $feld('konto') : 0;
        $konto = $kontoId > 0 ? $this->konten->finde($vault, $kontoId) : null;
        if ($konto === null) {
            throw new BankRuleViolation('Bitte das Konto oder die Kasse wählen.', 'konto');
        }
        if (!$konto->active && $konto->id !== $alt?->accountId) {
            throw new BankRuleViolation(sprintf('„%s“ ist deaktiviert und nimmt keine Buchungen an.', $konto->data->name), 'konto');
        }

        $datum = self::datum($feld('datum'), $konto, $heute);

        $cent = Betrag::parse($feld('betrag'));
        if ($cent === null || $cent <= 0) {
            throw new BankRuleViolation(
                'Den Betrag bitte als positiven Betrag wie 12,50 angeben – ob Einnahme oder Ausgabe, sagt die Richtung.',
                'betrag',
            );
        }

        $richtung = BankTransactionDirection::tryFrom($feld('richtung'))
            ?? throw new BankRuleViolation('Bitte angeben, ob es eine Einnahme oder eine Ausgabe ist.', 'richtung');

        $kategorieId = $this->kategorie($feld('kategorie'), $richtung, $alt);

        $zweck = $feld('zweck');
        if ($zweck === '') {
            throw new BankRuleViolation('Bitte den Zweck angeben, zum Beispiel „Spende Sommerfest“.', 'zweck');
        }
        if (mb_strlen($zweck) > self::ZWECK_MAX) {
            throw new BankRuleViolation(sprintf('Der Zweck darf höchstens %d Zeichen lang sein.', self::ZWECK_MAX), 'zweck');
        }
        $gegenseite = $feld('gegenseite');
        if (mb_strlen($gegenseite) > self::GEGENSEITE_MAX) {
            throw new BankRuleViolation(sprintf('Die Gegenseite darf höchstens %d Zeichen lang sein.', self::GEGENSEITE_MAX), 'gegenseite');
        }

        $belegNoetig = match ($feld('beleg')) {
            self::BELEG_NOETIG => true,
            self::BELEG_NICHT_NOETIG => false,
            default => $this->belegStandard->noetig($richtung),
        };
        $belegGewaehlt = in_array($feld('beleg'), [self::BELEG_NOETIG, self::BELEG_NICHT_NOETIG], true);

        return new BuchungEingabe($konto, $datum, $cent, $richtung, $kategorieId, $zweck, $gegenseite, $belegNoetig, $belegGewaehlt);
    }

    /**
     * @throws BankRuleViolation
     */
    private static function datum(string $wert, BankAccount $konto, \DateTimeImmutable $heute): \DateTimeImmutable
    {
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);
        if ($datum === false || $datum->format('Y-m-d') !== $wert) {
            throw new BankRuleViolation('Bitte das Datum der Buchung angeben.', 'datum');
        }
        if ($wert < $konto->openingDate->format('Y-m-d')) {
            throw new BankRuleViolation(
                sprintf('Die Buchung kann nicht vor dem Stichtag des Anfangssaldos (%s) liegen.', $konto->openingDate->format('d.m.Y')),
                'datum',
            );
        }
        if ($wert > $heute->format('Y-m-d')) {
            throw new BankRuleViolation('Die Buchung kann nicht in der Zukunft liegen.', 'datum');
        }

        return $datum;
    }

    /**
     * @throws BankRuleViolation
     */
    private function kategorie(string $wert, BankTransactionDirection $richtung, ?BankTransaction $alt): int
    {
        $kategorie = ctype_digit($wert) ? $this->kategorien->find((int) $wert) : null;
        if ($kategorie === null) {
            throw new BankRuleViolation('Bitte eine Kategorie wählen.', 'kategorie');
        }
        if (!$kategorie->active && $kategorie->id !== $alt?->categoryId) {
            throw new BankRuleViolation(sprintf('Die Kategorie „%s“ ist deaktiviert.', $kategorie->name), 'kategorie');
        }
        if ($kategorie->direction !== CategoryDirection::Beide && $kategorie->direction !== self::kategorieRichtung($richtung)) {
            throw new BankRuleViolation(
                sprintf('Die Kategorie „%s“ gehört zu den %s, die Buchung ist eine %s.', $kategorie->name, $kategorie->direction->gruppe(), $richtung->label()),
                'kategorie',
            );
        }

        return $kategorie->id;
    }

    private static function kategorieRichtung(BankTransactionDirection $richtung): CategoryDirection
    {
        return match ($richtung) {
            BankTransactionDirection::Einnahme => CategoryDirection::Einnahme,
            BankTransactionDirection::Ausgabe => CategoryDirection::Ausgabe,
        };
    }

    /**
     * @param array<string, int|string> $daten
     */
    private static function verschluesseln(DataKey $key, int $id, array $daten): string
    {
        return FieldCipher::encrypt(
            $key,
            json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            new FieldContext(self::TABLE, $id, self::COLUMN),
        );
    }

    public static function entschluesseln(Vault $vault, BankTransactionRecord $record): BankTransaction
    {
        $json = FieldCipher::decrypt($vault->openDataKey($record->dekSealed), $record->dataEnc, new FieldContext(self::TABLE, $record->id, self::COLUMN));
        $daten = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        $daten = is_array($daten) ? $daten : [];
        $text = static fn(string $key): string => is_string($daten[$key] ?? null) ? $daten[$key] : '';

        return new BankTransaction(
            id: $record->id,
            accountId: $record->accountId,
            importId: $record->importId,
            bookingDate: $record->bookingDate,
            valueDate: $record->valueDate,
            direction: $record->direction,
            categoryId: $record->categoryId,
            docRequired: $record->docRequired,
            docStatus: $record->docStatus,
            source: $record->source,
            amount: is_int($daten['amount'] ?? null) ? $daten['amount'] : 0,
            currency: $text('currency') === '' ? BankAccountService::CURRENCY : $text('currency'),
            counterpartyName: $text('counterparty_name'),
            counterpartyIban: $text('counterparty_iban'),
            purpose: $text('purpose'),
            bookingText: $text('booking_text'),
            createdAt: $record->createdAt ?? $record->bookingDate,
            updatedAt: $record->updatedAt ?? $record->bookingDate,
            ruleId: $record->ruleId,
            docSource: $record->docSource,
            categorySource: $record->categorySource,
        );
    }
}
