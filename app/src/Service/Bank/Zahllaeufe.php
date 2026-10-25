<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\AuditAction;
use App\Domain\BankAccount;
use App\Domain\BankAccountKind;
use App\Domain\DocumentStatus;
use App\Domain\Erstattungsart;
use App\Domain\Iban;
use App\Domain\ZahllaufRecord;
use App\Domain\ZahllaufStatus;
use App\Domain\Zugriffsbereich;
use App\Repository\DocumentRepository;
use App\Repository\PaymentRunRepository;
use App\Service\Audit\AuditLog;
use App\Service\Bank\Sepa\Pain001;
use App\Service\Bank\Sepa\Pain001Auftrag;
use App\Service\Bank\Sepa\Pain001Ueberweisung;
use App\Service\Bank\Sepa\SepaText;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * SEPA payment runs for reimbursements (issue #85, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand SEPA-Zahllauf", E-42).
 *
 *   vorbereitung()  the open reimbursements per payee (App\Service\Bank\
 *                   Uebersichten::erstattungen(), name AND IBAN from the
 *                   sealed submission) and what of them may go into a run.
 *   anlegen()       one transaction under the row locks: the selection is
 *                   checked again, one transfer per payee with the open
 *                   amount of the chosen receipts, the receipts reserved
 *                   (UNIQUE `active_invoice_id` - never ordered twice), all
 *                   values encrypted in `payment_run.data_enc`.
 *   datei()         the pain.001.001.09 file, built from that row on every
 *                   download - it is never stored.
 *   verwerfen()     `angewiesen` -> `verworfen`, the receipts are free again.
 *
 * Whether a run is settled is derived from its receipts: the matching
 * (App\Service\Bank\Erstattungen, App\Service\Bank\Zuordnung) settles each
 * one when the transfer turns up in the statement.
 *
 * Never in a run, by design: a payee whose name also turns up with another
 * IBAN or without one (the submission is public - E-37), no or an invalid
 * IBAN, an IBAN of the club's own accounts, a reimbursement not "per
 * Überweisung", a receipt not yet checked, a currency other than EUR.
 *
 * Everything runs in the session with the unlocked vault; the scope is
 * that of `matching.edit`. The audit log names ids and counts - never a
 * name, an IBAN or an amount.
 */
final readonly class Zahllaeufe
{
    private const string TABLE = 'payment_run';

    private const string WAEHRUNG = 'EUR';

    /** How far ahead an execution date may lie. */
    private const string MAX_VORLAUF = '+1 year';

    public function __construct(
        private \PDO $pdo,
        private PaymentRunRepository $laeufe,
        private Uebersichten $uebersichten,
        private BankAccountService $konten,
        private AuditLog $audit,
        private DocumentRepository $dokumente,
    ) {
    }

    public function vorbereitung(Vault $vault, Zugriffsbereich $bereich, \DateTimeImmutable $heute): Zahllaufvorbereitung
    {
        $konten = $this->konten->liste($vault);
        ['gruppen' => $gruppen, 'unlesbar' => $unlesbar] = $this->gruppen($vault, $bereich, $konten, $heute);

        return new Zahllaufvorbereitung(
            $gruppen,
            array_values(array_filter($konten, self::kannZahlen(...))),
            $unlesbar,
        );
    }

    /**
     * @param list<int> $belege invoice ids from the form
     *
     * @return int the new run's id
     *
     * @throws BankRuleViolation nothing is written then
     */
    public function anlegen(
        Vault $vault,
        Zugriffsbereich $bereich,
        array $belege,
        int $kontoId,
        string $auftraggeber,
        \DateTimeImmutable $ausfuehrung,
        ?int $userId,
        string $ip,
        \DateTimeImmutable $now,
    ): int {
        $belege = array_values(array_unique($belege));
        sort($belege);
        if ($belege === []) {
            throw new BankRuleViolation('Bitte mindestens einen Beleg auswählen.');
        }
        $konto = $this->konten->finde($vault, $kontoId);
        if ($konto === null || !self::kannZahlen($konto)) {
            throw new BankRuleViolation('Bitte ein aktives Bankkonto des Vereins mit gültiger IBAN wählen.');
        }
        $bic = strtoupper(trim($konto->data->bic));
        if ($bic !== '' && !Pain001::istBic($bic)) {
            throw new BankRuleViolation('Die BIC des gewählten Kontos ist ungültig – bitte in den Kontodaten korrigieren.');
        }
        $name = SepaText::bereinigen($auftraggeber, Pain001::NAME_LAENGE);
        if ($name === '') {
            throw new BankRuleViolation('Bitte den Namen des Kontoinhabers (Auftraggeber) angeben.');
        }
        $heute = $now->setTime(0, 0);
        $ausfuehrung = $ausfuehrung->setTime(0, 0);
        if ($ausfuehrung < $heute || $ausfuehrung > $heute->modify(self::MAX_VORLAUF)) {
            throw new BankRuleViolation('Das Ausführungsdatum darf nicht in der Vergangenheit und höchstens ein Jahr in der Zukunft liegen.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->dokumente->sperreAlle($this->laeufe->dokumente($belege));
            $this->laeufe->sperreBelege($belege);
            // Read again under the locks: an allocation or another run in
            // the meantime changes what is open.
            ['gruppen' => $gruppen] = $this->gruppen($vault, $bereich, $this->konten->liste($vault), $heute);
            $ueberweisungen = self::auswahl($gruppen, $belege);

            $key = DataKey::generate();
            $id = $this->laeufe->insert($konto->id, $ausfuehrung, $vault->sealDataKey($key), $userId, $now);
            $msgId = sprintf('VB-Z%d-%s', $id, $now->format('YmdHis'));
            $pmtInfId = sprintf('VB-Z%d', $id);
            $nummer = 0;
            $ueberweisungen = array_map(
                static function (array $u) use ($id, &$nummer): ZahllaufUeberweisung {
                    ++$nummer;

                    return new ZahllaufUeberweisung(sprintf('VB-Z%d-%d', $id, $nummer), $u['name'], $u['iban'], $u['cent'], $u['zweck'], $u['belege']);
                },
                $ueberweisungen,
            );
            foreach ($ueberweisungen as $u) {
                foreach ($u->belege as $beleg) {
                    if (!$this->laeufe->insertItem($id, $beleg)) {
                        throw new BankRuleViolation('Ein ausgewählter Beleg ist bereits in einem anderen Zahllauf angewiesen. Bitte die Seite neu laden.');
                    }
                }
            }

            $daten = [
                'v' => 1,
                'msg_id' => $msgId,
                'pmt_inf_id' => $pmtInfId,
                'erstellt' => $now->format('Y-m-d H:i:s'),
                'konto_name' => $konto->data->name,
                'auftraggeber' => $name,
                'iban' => Iban::normalisieren($konto->data->iban),
                'bic' => $bic,
                'ueberweisungen' => array_map(static fn(ZahllaufUeberweisung $u): array => $u->toPayload(), $ueberweisungen),
            ];
            // Build the file once now: a run the bank would refuse is
            // never stored.
            try {
                Pain001::xml(self::auftrag($daten, $now, $ausfuehrung, $ueberweisungen));
            } catch (\InvalidArgumentException $e) {
                throw new BankRuleViolation('Die SEPA-Datei ließe sich nicht erzeugen: ' . $e->getMessage());
            }
            $this->laeufe->setCiphertext($id, FieldCipher::encrypt(
                $key,
                json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                new FieldContext(self::TABLE, $id, 'data_enc'),
            ));
            $this->audit->record(AuditAction::ZahllaufAngelegt, $userId, $ip, $id, [
                'bank_account_id' => $konto->id,
                'ueberweisungen' => count($ueberweisungen),
                'belege' => count($belege),
            ], $now);
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
     * The runs within $bereich, newest first; a run that cannot be
     * decrypted is left out and counted.
     *
     * @return array{laeufe: list<Zahllauf>, unlesbar: int}
     */
    public function liste(Vault $vault, Zugriffsbereich $bereich): array
    {
        $laeufe = [];
        $unlesbar = 0;
        $kontoNamen = $this->kontoNamen($vault);
        foreach ($this->laeufe->liste($bereich) as $record) {
            try {
                $laeufe[] = $this->entschluesseln($vault, $record, $kontoNamen[$record->bankAccountId] ?? null);
            } catch (CryptoException | \JsonException) {
                ++$unlesbar;
            }
        }

        return ['laeufe' => $laeufe, 'unlesbar' => $unlesbar];
    }

    /**
     * @throws CryptoException|\JsonException for a run that cannot be decrypted
     */
    public function finde(Vault $vault, int $id, Zugriffsbereich $bereich): ?Zahllauf
    {
        $record = $this->laeufe->find($id, $bereich);
        if ($record === null) {
            return null;
        }
        try {
            $kontoName = $this->konten->finde($vault, $record->bankAccountId)?->data->name;
        } catch (CryptoException | \JsonException) {
            $kontoName = null;
        }

        return $this->entschluesseln($vault, $record, $kontoName, $this->laeufe->belegeDesLaufs($id));
    }

    /**
     * The pain.001 file of an ordered run, built in memory; null when the
     * run is not visible or was discarded.
     *
     * @return array{name: string, xml: string}|null
     *
     * @throws CryptoException|\JsonException
     * @throws BankRuleViolation when the stored run no longer gives a valid file
     */
    public function datei(Vault $vault, int $id, Zugriffsbereich $bereich, ?int $userId, string $ip, \DateTimeImmutable $now): ?array
    {
        $record = $this->laeufe->find($id, $bereich);
        if ($record === null || $record->status !== ZahllaufStatus::Angewiesen) {
            return null;
        }
        $daten = $this->daten($vault, $record);
        $ueberweisungen = self::ueberweisungen($daten);
        try {
            $xml = Pain001::xml(self::auftrag($daten, new \DateTimeImmutable(self::text($daten, 'erstellt')), $record->executionDate, $ueberweisungen));
        } catch (\InvalidArgumentException | \DateMalformedStringException $e) {
            throw new BankRuleViolation('Die SEPA-Datei ließ sich nicht erzeugen: ' . $e->getMessage());
        }
        $this->audit->record(AuditAction::ZahllaufHeruntergeladen, $userId, $ip, $id, [], $now);

        return ['name' => sprintf('zahllauf-%d.xml', $id), 'xml' => $xml];
    }

    /**
     * @throws BankRuleViolation
     */
    public function verwerfen(int $id, Zugriffsbereich $bereich, ?int $userId, string $ip, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $record = $this->laeufe->find($id, $bereich, sperren: true);
            if ($record === null || $record->status !== ZahllaufStatus::Angewiesen) {
                throw new BankRuleViolation('Dieser Zahllauf ist nicht (mehr) angewiesen.');
            }
            // Settled means the money went out: discarding would free the
            // receipts for a second payment.
            if ($record->offeneBelege === 0 || !$this->laeufe->verwerfen($id, $userId, $now)) {
                throw new BankRuleViolation('Dieser Zahllauf ist bereits ausgeglichen und lässt sich nicht verwerfen.');
            }
            $this->audit->record(AuditAction::ZahllaufVerworfen, $userId, $ip, $id, ['belege' => $record->anzahlBelege], $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @param list<BankAccount> $konten
     *
     * @return array{gruppen: list<ZahllaufGruppe>, unlesbar: int}
     */
    private function gruppen(Vault $vault, Zugriffsbereich $bereich, array $konten, \DateTimeImmutable $heute): array
    {
        ['empfaenger' => $empfaenger, 'unlesbar' => $unlesbar] = $this->uebersichten->erstattungen($vault, $bereich, $heute);

        $eigene = [];
        foreach ($konten as $konto) {
            if ($konto->data->iban !== '') {
                $eigene[Iban::normalisieren($konto->data->iban)] = true;
            }
        }
        $ids = [];
        foreach ($empfaenger as $g) {
            foreach ($g->posten as $p) {
                $ids[] = $p->record->id;
            }
        }
        $angewiesen = $this->laeufe->angewieseneBelege($ids);
        $dokumente = $this->laeufe->dokumentStatus($ids);

        $gruppen = [];
        foreach ($empfaenger as $g) {
            // The payee of a transfer is the account holder the submission
            // gave (the bank may check it against the IBAN), else the name.
            $inhaber = [];
            foreach ($g->posten as $p) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $p->kontoinhaber));
                $inhaber[mb_strtolower($text)] ??= $text;
            }
            $inhaber = array_values(array_filter($inhaber, static fn(string $t): bool => $t !== ''));
            $sepaName = SepaText::bereinigen($inhaber[0] ?? $g->name, Pain001::NAME_LAENGE);
            $sperrgrund = match (true) {
                $g->name === '' => 'Ohne Namen des Einreichers – bitte von Hand erstatten.',
                $g->mehrdeutig => 'Derselbe Name kommt mit einer anderen oder ohne IBAN vor. Bitte prüfen, wem das Konto gehört, und von Hand überweisen.',
                count($inhaber) > 1 => 'Die Einreichungen nennen verschiedene Kontoinhaber für diese IBAN. Bitte prüfen und von Hand überweisen.',
                $g->iban === '' || !Iban::istGueltig($g->iban) => 'Keine gültige IBAN in der Einreichung.',
                isset($eigene[$g->iban]) => 'Die IBAN ist ein Konto des Vereins.',
                $sepaName === '' => 'Der Name lässt sich nicht in SEPA-Zeichen schreiben.',
                default => null,
            };
            $kandidaten = [];
            foreach ($g->posten as $p) {
                $grund = match (true) {
                    $sperrgrund !== null => $sperrgrund,
                    $p->offen <= 0 => 'Nichts mehr offen.',
                    $p->erstattungsart !== Erstattungsart::Ueberweisung => 'Laut Einreichung nicht per Überweisung zu erstatten.',
                    $p->waehrung !== self::WAEHRUNG => 'SEPA-Überweisungen nur in Euro.',
                    isset($angewiesen[$p->record->id]) => sprintf('Bereits in Zahllauf %d angewiesen.', $angewiesen[$p->record->id]),
                    !in_array($dokumente[$p->record->id] ?? null, [DocumentStatus::Geprueft, DocumentStatus::Festgeschrieben], true) => 'Der Beleg ist noch nicht geprüft.',
                    default => null,
                };
                $kandidaten[] = new ZahllaufKandidat($p, $grund);
            }
            $gruppen[] = new ZahllaufGruppe($g->name, $sepaName, $g->iban, $sperrgrund, $kandidaten);
        }

        return ['gruppen' => $gruppen, 'unlesbar' => $unlesbar];
    }

    /**
     * The transfers for the chosen receipts: one per payee group, the sum
     * of their open amounts.
     *
     * @param list<ZahllaufGruppe> $gruppen
     * @param list<int> $belege
     *
     * @return list<array{name: string, iban: string, cent: int, zweck: string, belege: list<int>}>
     *
     * @throws BankRuleViolation when a chosen receipt cannot (any more) go into a run
     */
    private static function auswahl(array $gruppen, array $belege): array
    {
        $gewaehlt = array_flip($belege);
        $gefunden = 0;
        $ueberweisungen = [];
        foreach ($gruppen as $g) {
            $teile = [];
            foreach ($g->kandidaten as $k) {
                if (!isset($gewaehlt[$k->posten->record->id])) {
                    continue;
                }
                if (!$k->waehlbar()) {
                    throw new BankRuleViolation('Die Auswahl ist nicht mehr aktuell: ' . $k->sperrgrund . ' Bitte die Seite neu laden.');
                }
                $teile[] = $k->posten;
                ++$gefunden;
            }
            if ($teile === []) {
                continue;
            }
            usort($teile, static fn(OffenerPosten $a, OffenerPosten $b): int => $a->record->id <=> $b->record->id);
            $cent = array_sum(array_map(static fn(OffenerPosten $p): int => $p->offen, $teile));
            if ($cent > Pain001::MAX_CENT) {
                throw new BankRuleViolation('Eine Überweisung läge über der SEPA-Höchstgrenze.');
            }
            $ueberweisungen[] = [
                'name' => $g->sepaName,
                'iban' => $g->iban,
                'cent' => $cent,
                'zweck' => self::zweck($teile),
                'belege' => array_map(static fn(OffenerPosten $p): int => $p->record->id, $teile),
            ];
        }
        if ($gefunden !== count($belege)) {
            throw new BankRuleViolation('Die Auswahl ist nicht mehr aktuell – ein Beleg ist nicht mehr offen. Bitte die Seite neu laden.');
        }

        return $ueberweisungen;
    }

    /**
     * "Auslagenerstattung" and what the payee recognises: the receipt
     * number, else its date - cut to 140 characters, ending in "u. a."
     * when not everything fits.
     *
     * @param list<OffenerPosten> $posten
     */
    private static function zweck(array $posten): string
    {
        $teile = array_map(
            static fn(OffenerPosten $p): string => SepaText::bereinigen($p->nummer, 35) !== ''
                ? SepaText::bereinigen($p->nummer, 35)
                : 'vom ' . $p->record->invoiceDate->format('d.m.Y'),
            $posten,
        );
        $anfang = count($teile) === 1 ? 'Auslagenerstattung Beleg ' : 'Auslagenerstattung Belege ';
        $zweck = $anfang . implode(', ', $teile);
        while (strlen($zweck) > Pain001::ZWECK_LAENGE && count($teile) > 1) {
            array_pop($teile);
            $zweck = $anfang . implode(', ', $teile) . ' u. a.';
        }

        return SepaText::bereinigen($zweck, Pain001::ZWECK_LAENGE);
    }

    /**
     * @param array<string, mixed> $daten
     * @param list<ZahllaufUeberweisung> $ueberweisungen
     */
    private static function auftrag(array $daten, \DateTimeImmutable $erstellt, \DateTimeImmutable $ausfuehrung, array $ueberweisungen): Pain001Auftrag
    {
        return new Pain001Auftrag(
            self::text($daten, 'msg_id'),
            self::text($daten, 'pmt_inf_id'),
            $erstellt,
            self::text($daten, 'auftraggeber'),
            self::text($daten, 'iban'),
            self::text($daten, 'bic'),
            $ausfuehrung,
            array_map(
                static fn(ZahllaufUeberweisung $u): Pain001Ueberweisung => new Pain001Ueberweisung($u->endToEndId, $u->name, $u->iban, $u->cent, $u->zweck),
                $ueberweisungen,
            ),
        );
    }

    private static function kannZahlen(BankAccount $konto): bool
    {
        return $konto->kind === BankAccountKind::Bank && $konto->active && Iban::istGueltig($konto->data->iban);
    }

    /**
     * @return array<int, string> account id => name
     */
    private function kontoNamen(Vault $vault): array
    {
        $namen = [];
        try {
            foreach ($this->konten->liste($vault) as $konto) {
                $namen[$konto->id] = $konto->data->name;
            }
        } catch (CryptoException | \JsonException) {
            // An unreadable account: the run shows the name it stored.
        }

        return $namen;
    }

    /**
     * @param string|null $kontoName the account's current name, null: the one the run stored
     * @param array<int, \App\Domain\PaymentStatus> $belegStatus
     *
     * @throws CryptoException|\JsonException
     */
    private function entschluesseln(Vault $vault, ZahllaufRecord $record, ?string $kontoName, array $belegStatus = []): Zahllauf
    {
        $daten = $this->daten($vault, $record);

        try {
            $erstellt = new \DateTimeImmutable(self::text($daten, 'erstellt'));
        } catch (\DateMalformedStringException) {
            $erstellt = $record->createdAt;
        }

        return new Zahllauf(
            $record,
            $kontoName ?? self::text($daten, 'konto_name'),
            self::text($daten, 'auftraggeber'),
            self::text($daten, 'iban'),
            self::text($daten, 'bic'),
            self::text($daten, 'msg_id'),
            self::text($daten, 'pmt_inf_id'),
            $erstellt,
            self::ueberweisungen($daten),
            $belegStatus,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CryptoException|\JsonException
     */
    private function daten(Vault $vault, ZahllaufRecord $record): array
    {
        $key = $vault->openDataKey($record->dekSealed);
        $daten = json_decode(FieldCipher::decrypt($key, $record->dataEnc, new FieldContext(self::TABLE, $record->id, 'data_enc')), true, flags: JSON_THROW_ON_ERROR);

        return is_array($daten) ? $daten : [];
    }

    /**
     * @param array<string, mixed> $daten
     *
     * @return list<ZahllaufUeberweisung>
     */
    private static function ueberweisungen(array $daten): array
    {
        $liste = is_array($daten['ueberweisungen'] ?? null) ? $daten['ueberweisungen'] : [];

        return array_values(array_map(
            static fn(mixed $u): ZahllaufUeberweisung => ZahllaufUeberweisung::fromPayload(is_array($u) ? $u : []),
            $liste,
        ));
    }

    /**
     * @param array<string, mixed> $daten
     */
    private static function text(array $daten, string $schluessel): string
    {
        return is_string($daten[$schluessel] ?? null) ? $daten[$schluessel] : '';
    }
}
