<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\AssignmentRule;
use App\Domain\AssignmentRuleRecord;
use App\Domain\BankTransaction;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionRecord;
use App\Domain\BankTransactionSetBy;
use App\Domain\Buchungsmerkmale;
use App\Domain\CategoryDirection;
use App\Domain\Iban;
use App\Domain\Zugriffsbereich;
use App\Repository\AssignmentRuleRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\CategoryRepository;
use App\Repository\SettingRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * Rules "kein Beleg nötig" / category for bookings (M9-6, issue #64,
 * docs/spec/04-bank-und-abgleich.md section 5 "Stand M9-6") - the rules
 * about rules, their encryption and their effect on bookings, not the SQL:
 *
 * - A rule has a label, a condition - a word in purpose or booking text
 *   and/or a counterparty name or IBAN, optionally one direction - and an
 *   effect: "kein Beleg nötig" and/or a category that fits the direction.
 *   Matching is App\Service\Bank\Regelabgleich.
 * - Rules act on imported bookings only, and on a booking only where it
 *   still has the default receipt status or no category; one rule per
 *   booking (`rule_id`). Whoever set something by hand wins.
 * - The statement import applies the oldest matching active rule to every
 *   new booking (fuerImport(), App\Service\Bank\Import\KontoauszugImport).
 *   Existing bookings get a rule only on request (anwenden()), and only
 *   those no rule has touched yet, within the scope of whoever asks.
 * - Changing a rule takes its effect back and applies it again to the
 *   bookings it had touched, where it still matches. Deactivating or
 *   deleting it takes the effect back - receipt status to the default of
 *   the direction (App\Service\Bank\BelegStandard), category removed.
 * - Label and pattern are vault data: `data_enc` under the rule's own DEK,
 *   AAD "assignment_rule|<id>|data_enc", written in two steps inside one
 *   transaction like a booking.
 *
 * Reading and applying need the unlocked vault; taking a rule back does
 * not - it only touches plaintext structure.
 */
final readonly class Buchungsregeln
{
    public const int BEZEICHNUNG_MAX = 100;

    public const int MUSTER_MAX = 100;

    /** Shortest word or name part a rule may look for - "AG" would match half the bank. */
    public const int MUSTER_MIN = 3;

    /** Form value of `kein_beleg` when ticked. */
    public const string KEIN_BELEG = '1';

    private const string TABLE = 'assignment_rule';

    private const string COLUMN = 'data_enc';

    public function __construct(
        private \PDO $pdo,
        private AssignmentRuleRepository $regeln,
        private BankTransactionRepository $buchungen,
        private CategoryRepository $kategorien,
        private SettingRepository $settings,
    ) {
    }

    /**
     * Every rule, oldest first - the order in which they win.
     *
     * @return list<AssignmentRule>
     */
    public function liste(Vault $vault): array
    {
        return array_map(static fn(AssignmentRuleRecord $r): AssignmentRule => self::entschluesseln($vault, $r), $this->regeln->all());
    }

    public function finde(Vault $vault, int $id): ?AssignmentRule
    {
        $record = $this->regeln->find($id);

        return $record === null ? null : self::entschluesseln($vault, $record);
    }

    /**
     * The active rules, decrypted once for a whole import step.
     *
     * @return list<AssignmentRule>
     */
    public function fuerImport(Vault $vault): array
    {
        return array_map(static fn(AssignmentRuleRecord $r): AssignmentRule => self::entschluesseln($vault, $r), $this->regeln->all(nurAktive: true));
    }

    public function belegStandard(): BelegStandard
    {
        return BelegStandard::fromSettings($this->settings);
    }

    /** Switches whether income needs a receipt by default. Returns whether it changed. */
    public function belegStandardSpeichern(bool $einnahmeBelegNoetig): bool
    {
        if ($this->belegStandard()->einnahmeBelegNoetig === $einnahmeBelegNoetig) {
            return false;
        }
        new BelegStandard($einnahmeBelegNoetig)->speichern($this->settings);

        return true;
    }

    /**
     * How many bookings each rule has acted on, within the reader's scope.
     *
     * @return array<int, int>
     */
    public function betroffene(Zugriffsbereich $bereich): array
    {
        return $this->buchungen->countByRule($bereich);
    }

    /**
     * @param array<string, string> $felder bezeichnung, stichwort, gegenseite, richtung, kein_beleg, kategorie
     *
     * @throws BankRuleViolation
     */
    public function anlegen(Vault $vault, array $felder, ?int $userId, \DateTimeImmutable $now): int
    {
        $eingabe = $this->pruefen($felder, null);

        $key = DataKey::generate();
        $this->pdo->beginTransaction();
        try {
            $id = $this->regeln->insert($vault->sealDataKey($key), $eingabe['richtung'], $eingabe['keinBeleg'], $eingabe['kategorie'], $userId, $now);
            $this->regeln->setCiphertext($id, self::verschluesseln($key, $id, $eingabe));
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
     * Changes a rule: its old effect is taken back and - while it is
     * active - applied again to the bookings it had touched, where it still
     * matches. Nothing is written when nothing changed.
     *
     * @param array<string, string> $felder as for anlegen()
     *
     * @return array{felder: list<string>, zurueckgenommen: int, erneut: int}
     *
     * @throws BankRuleViolation
     */
    public function aendern(Vault $vault, AssignmentRule $alt, array $felder, \DateTimeImmutable $now): array
    {
        $neu = $this->pruefen($felder, $alt);
        $geaendert = array_keys(array_filter([
            'label' => $neu['bezeichnung'] !== $alt->label,
            'stichwort' => $neu['stichwort'] !== $alt->stichwort,
            'gegenseite' => $neu['gegenseite'] !== $alt->gegenseite,
            'direction' => $neu['richtung'] !== $alt->direction,
            'no_receipt' => $neu['keinBeleg'] !== $alt->noReceipt,
            'category_id' => $neu['kategorie'] !== $alt->categoryId,
        ]));
        if ($geaendert === []) {
            return ['felder' => [], 'zurueckgenommen' => 0, 'erneut' => 0];
        }

        $record = $this->regeln->find($alt->id) ?? throw new BankRuleViolation('Diese Regel gibt es nicht mehr.');
        $this->pdo->beginTransaction();
        try {
            $bisher = $this->buchungen->idsForRule($alt->id);
            $zurueck = $this->buchungen->regelZuruecknehmen($alt->id, $this->belegStandard()->einnahmeBelegNoetig, $now);
            $this->regeln->update($alt->id, $neu['richtung'], $neu['keinBeleg'], $neu['kategorie'], $now);
            $this->regeln->setCiphertext($alt->id, self::verschluesseln($vault->openDataKey($record->dekSealed), $alt->id, $neu));
            $erneut = 0;
            if ($alt->active && $bisher !== []) {
                $regel = $this->finde($vault, $alt->id) ?? throw new \LogicException('The rule just written is gone.');
                // Bringing back what the rule had done, so the editor's
                // scope does not narrow it.
                $erneut = $this->wirken($vault, $regel, Zugriffsbereich::unbeschraenkt(), $now, $bisher);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return ['felder' => array_values($geaendert), 'zurueckgenommen' => $zurueck, 'erneut' => $erneut];
    }

    /**
     * Switches a rule on or off. Off takes its effect back - returns how
     * many bookings that touched; on applies it to nothing yet (that is
     * anwenden(), on request).
     */
    public function aktivieren(AssignmentRule $regel, bool $aktiv, \DateTimeImmutable $now): int
    {
        if ($regel->active === $aktiv) {
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            $zurueck = $aktiv ? 0 : $this->buchungen->regelZuruecknehmen($regel->id, $this->belegStandard()->einnahmeBelegNoetig, $now);
            $this->regeln->setActive($regel->id, $aktiv, $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $zurueck;
    }

    /** Takes the rule's effect back and deletes it. Returns how many bookings that touched. */
    public function loeschen(AssignmentRule $regel, \DateTimeImmutable $now): int
    {
        $this->pdo->beginTransaction();
        try {
            $zurueck = $this->buchungen->regelZuruecknehmen($regel->id, $this->belegStandard()->einnahmeBelegNoetig, $now);
            $this->regeln->delete($regel->id);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $zurueck;
    }

    /**
     * How many existing bookings within the scope the rule would act on if
     * applied now - for "Auf N Buchungen anwenden".
     */
    public function kandidaten(Vault $vault, AssignmentRule $regel, Zugriffsbereich $bereich): int
    {
        if (!$regel->active) {
            return 0;
        }

        $anzahl = 0;
        foreach ($this->buchungen->regelKandidaten($bereich) as $record) {
            if ($this->wirkung($vault, $regel, $record) !== null) {
                $anzahl++;
            }
        }

        return $anzahl;
    }

    /**
     * Applies an active rule to the existing bookings within the scope no
     * rule has touched yet. Returns how many it acted on.
     *
     * @throws BankRuleViolation
     */
    public function anwenden(Vault $vault, AssignmentRule $regel, Zugriffsbereich $bereich, \DateTimeImmutable $now): int
    {
        if (!$regel->active) {
            throw new BankRuleViolation('Die Regel ist deaktiviert – erst aktivieren, dann anwenden.');
        }

        $this->pdo->beginTransaction();
        try {
            $anzahl = $this->wirken($vault, $regel, $bereich, $now, null);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $anzahl;
    }

    /**
     * What a new imported booking gets (App\Service\Bank\Import\
     * KontoauszugImport): the default of the direction, then the oldest
     * matching rule on top.
     *
     * @param list<AssignmentRule> $regeln from fuerImport()
     *
     * @return array{docRequired: bool, docSource: BankTransactionSetBy, categoryId: ?int, categorySource: ?BankTransactionSetBy, ruleId: ?int}
     */
    public static function fuerNeueBuchung(array $regeln, BelegStandard $standard, Buchungsmerkmale $buchung): array
    {
        $ergebnis = [
            'docRequired' => $standard->noetig($buchung->richtung),
            'docSource' => BankTransactionSetBy::Standard,
            'categoryId' => null,
            'categorySource' => null,
            'ruleId' => null,
        ];
        $regel = Regelabgleich::ersteTreffende($regeln, $buchung);
        if ($regel === null) {
            return $ergebnis;
        }

        $ergebnis['ruleId'] = $regel->id;
        if ($regel->noReceipt) {
            $ergebnis['docRequired'] = false;
            $ergebnis['docSource'] = BankTransactionSetBy::Regel;
        }
        if ($regel->categoryId !== null) {
            $ergebnis['categoryId'] = $regel->categoryId;
            $ergebnis['categorySource'] = BankTransactionSetBy::Regel;
        }

        return $ergebnis;
    }

    /**
     * A rule prefilled from a booking ("Regel daraus machen"): its
     * counterparty - the IBAN if there is one, else the name -, direction,
     * category and "kein Beleg nötig".
     *
     * @return array<string, string>
     */
    public static function vorschlagAus(BankTransaction $buchung): array
    {
        return [
            ...self::leer(),
            'bezeichnung' => mb_substr($buchung->counterpartyName, 0, self::BEZEICHNUNG_MAX),
            'gegenseite' => $buchung->counterpartyIban !== ''
                ? Iban::formatieren($buchung->counterpartyIban)
                : mb_substr($buchung->counterpartyName, 0, self::MUSTER_MAX),
            'richtung' => $buchung->direction->value,
            'kein_beleg' => self::KEIN_BELEG,
            'kategorie' => $buchung->categoryId === null ? '' : (string) $buchung->categoryId,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function leer(): array
    {
        return ['bezeichnung' => '', 'stichwort' => '', 'gegenseite' => '', 'richtung' => '', 'kein_beleg' => '', 'kategorie' => ''];
    }

    /**
     * @return array<string, string>
     */
    public static function felderAus(AssignmentRule $regel): array
    {
        return [
            'bezeichnung' => $regel->label,
            'stichwort' => $regel->stichwort,
            'gegenseite' => $regel->gegenseiteIstIban() ? Iban::formatieren($regel->gegenseite) : $regel->gegenseite,
            'richtung' => $regel->direction->value ?? '',
            'kein_beleg' => $regel->noReceipt ? self::KEIN_BELEG : '',
            'kategorie' => $regel->categoryId === null ? '' : (string) $regel->categoryId,
        ];
    }

    /**
     * Lets the rule act on the candidates (all within the scope, or only
     * $ids). Runs inside the caller's transaction.
     *
     * @param list<int>|null $ids
     */
    private function wirken(Vault $vault, AssignmentRule $regel, Zugriffsbereich $bereich, \DateTimeImmutable $now, ?array $ids): int
    {
        $anzahl = 0;
        foreach ($this->buchungen->regelKandidaten($bereich, $ids) as $record) {
            $wirkung = $this->wirkung($vault, $regel, $record);
            if ($wirkung === null) {
                continue;
            }
            [$docRequired, $docStatus, $kategorie] = $wirkung;
            if ($this->buchungen->regelSetzen($record->id, $regel->id, $docRequired, $docStatus, $kategorie, $now)) {
                $anzahl++;
            }
        }

        return $anzahl;
    }

    /**
     * What the rule would change on this booking - receipt (required,
     * status) and/or category -, or null when it does not match or would
     * change nothing.
     *
     * @return array{?bool, ?BankTransactionDocStatus, ?int}|null
     */
    private function wirkung(Vault $vault, AssignmentRule $regel, BankTransactionRecord $record): ?array
    {
        $beleg = $regel->noReceipt && $record->docSource === BankTransactionSetBy::Standard;
        $kategorie = $regel->categoryId !== null && $record->categoryId === null;
        if (!$beleg && !$kategorie) {
            return null;
        }
        if (!Regelabgleich::trifft($regel, Buchungen::entschluesseln($vault, $record)->merkmale())) {
            return null;
        }

        return [
            $beleg ? false : null,
            $beleg ? ($record->docStatus === BankTransactionDocStatus::Zugeordnet ? BankTransactionDocStatus::Zugeordnet : BankTransactionDocStatus::NichtNoetig) : null,
            $kategorie ? $regel->categoryId : null,
        ];
    }

    /**
     * Checks the form against the rules of a rule.
     *
     * @param array<string, string> $felder
     *
     * @return array{bezeichnung: string, stichwort: string, gegenseite: string, richtung: ?BankTransactionDirection, keinBeleg: bool, kategorie: ?int}
     *
     * @throws BankRuleViolation
     */
    private function pruefen(array $felder, ?AssignmentRule $alt): array
    {
        $feld = static fn(string $name): string => trim((string) preg_replace('/\s+/u', ' ', $felder[$name] ?? ''));

        $bezeichnung = $feld('bezeichnung');
        if ($bezeichnung === '') {
            throw new BankRuleViolation('Bitte der Regel einen Namen geben, zum Beispiel „Kontoführungsgebühren“.', 'bezeichnung');
        }
        if (mb_strlen($bezeichnung) > self::BEZEICHNUNG_MAX) {
            throw new BankRuleViolation(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::BEZEICHNUNG_MAX), 'bezeichnung');
        }

        $stichwort = $feld('stichwort');
        $gegenseite = $feld('gegenseite');
        if ($stichwort === '' && $gegenseite === '') {
            throw new BankRuleViolation('Bitte ein Stichwort oder die Gegenseite angeben – sonst träfe die Regel jede Buchung.', 'stichwort');
        }
        foreach (['stichwort' => $stichwort, 'gegenseite' => $gegenseite] as $name => $wert) {
            if ($wert !== '' && mb_strlen($wert) < self::MUSTER_MIN) {
                throw new BankRuleViolation(sprintf('Bitte mindestens %d Zeichen angeben.', self::MUSTER_MIN), $name);
            }
            if (mb_strlen($wert) > self::MUSTER_MAX) {
                throw new BankRuleViolation(sprintf('Bitte höchstens %d Zeichen angeben.', self::MUSTER_MAX), $name);
            }
        }
        if (Iban::istGueltig($gegenseite)) {
            $gegenseite = Iban::normalisieren($gegenseite);
        } elseif (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,}$/', Iban::normalisieren($gegenseite)) === 1) {
            throw new BankRuleViolation('Die IBAN der Gegenseite ist ungültig – bitte prüfen.', 'gegenseite');
        }

        $richtung = match ($feld('richtung')) {
            '' => null,
            default => BankTransactionDirection::tryFrom($feld('richtung'))
                ?? throw new BankRuleViolation('Bitte eine gültige Richtung wählen.', 'richtung'),
        };

        $keinBeleg = $feld('kein_beleg') === self::KEIN_BELEG;
        $kategorie = $this->kategorie($feld('kategorie'), $richtung, $alt);
        if (!$keinBeleg && $kategorie === null) {
            throw new BankRuleViolation('Bitte angeben, was die Regel tun soll: „kein Beleg nötig“ und/oder eine Kategorie.', 'kein_beleg');
        }

        return [
            'bezeichnung' => $bezeichnung,
            'stichwort' => $stichwort,
            'gegenseite' => $gegenseite,
            'richtung' => $richtung,
            'keinBeleg' => $keinBeleg,
            'kategorie' => $kategorie,
        ];
    }

    /**
     * @throws BankRuleViolation
     */
    private function kategorie(string $wert, ?BankTransactionDirection $richtung, ?AssignmentRule $alt): ?int
    {
        if ($wert === '') {
            return null;
        }
        $kategorie = ctype_digit($wert) ? $this->kategorien->find((int) $wert) : null;
        if ($kategorie === null) {
            throw new BankRuleViolation('Diese Kategorie gibt es nicht.', 'kategorie');
        }
        if (!$kategorie->active && $kategorie->id !== $alt?->categoryId) {
            throw new BankRuleViolation(sprintf('Die Kategorie „%s“ ist deaktiviert.', $kategorie->name), 'kategorie');
        }
        if ($kategorie->direction === CategoryDirection::Beide) {
            return $kategorie->id;
        }
        if ($richtung === null) {
            throw new BankRuleViolation(
                sprintf('„%s“ gehört zu den %s – bitte die Richtung der Regel passend wählen.', $kategorie->name, $kategorie->direction->gruppe()),
                'richtung',
            );
        }
        if ($kategorie->direction->value !== $richtung->value) {
            throw new BankRuleViolation(
                sprintf('Die Kategorie „%s“ gehört zu den %s, die Regel gilt für %s.', $kategorie->name, $kategorie->direction->gruppe(), $richtung === BankTransactionDirection::Ausgabe ? 'Ausgaben' : 'Einnahmen'),
                'kategorie',
            );
        }

        return $kategorie->id;
    }

    /**
     * @param array{bezeichnung: string, stichwort: string, gegenseite: string, ...} $eingabe
     */
    private static function verschluesseln(DataKey $key, int $id, array $eingabe): string
    {
        return FieldCipher::encrypt(
            $key,
            json_encode(
                ['label' => $eingabe['bezeichnung'], 'stichwort' => $eingabe['stichwort'], 'gegenseite' => $eingabe['gegenseite']],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            new FieldContext(self::TABLE, $id, self::COLUMN),
        );
    }

    private static function entschluesseln(Vault $vault, AssignmentRuleRecord $record): AssignmentRule
    {
        $json = FieldCipher::decrypt($vault->openDataKey($record->dekSealed), $record->dataEnc, new FieldContext(self::TABLE, $record->id, self::COLUMN));
        $daten = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        $daten = is_array($daten) ? $daten : [];
        $text = static fn(string $key): string => is_string($daten[$key] ?? null) ? $daten[$key] : '';

        return new AssignmentRule(
            id: $record->id,
            label: $text('label'),
            stichwort: $text('stichwort'),
            gegenseite: $text('gegenseite'),
            direction: $record->direction,
            noReceipt: $record->noReceipt,
            categoryId: $record->categoryId,
            active: $record->active,
            createdAt: $record->createdAt,
            updatedAt: $record->updatedAt,
        );
    }
}
