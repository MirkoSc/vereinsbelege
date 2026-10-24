<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransactionDocStatus;
use App\Domain\InvoiceDirection;
use App\Domain\OffenerBelegRecord;
use App\Domain\PaymentStatus;
use App\Domain\Zahlungsweg;
use App\Domain\Zugriffsbereich;
use App\Repository\InvoiceRepository;
use App\Repository\SupplierRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Inbox\EinreichungsDaten;
use App\Service\Invoice\Pruefung;
use App\Service\MasterData\SupplierService;

/**
 * The work lists "what is still missing" (M10-5, issue #69, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Übersichten", E-37): unpaid
 * receipts, open reimbursements per submitter and bookings without a
 * receipt. The expected receipts of recurring series come from
 * App\Service\Invoice\Serien - nothing is duplicated here.
 *
 * Read-only. SQL narrows by the plaintext statuses App\Service\Bank\
 * Zuordnung derives (E-27); amounts are vault data, so open amounts and
 * sums are computed here after decrypting - only with the unlocked vault.
 * A row that cannot be decrypted (sealed to an older vault version,
 * damaged) is left out and counted.
 */
final readonly class Uebersichten
{
    public function __construct(
        private InvoiceRepository $belege,
        private SupplierRepository $lieferantenRepository,
        private SupplierService $lieferanten,
        private Zuordnung $zuordnung,
        private Buchungen $buchungen,
    ) {
    }

    /**
     * Receipts paid via an account (`konto`) that are `offen` or
     * `teilbezahlt`: due date first.
     *
     * @return array{posten: list<OffenerPosten>, unlesbar: int}
     */
    public function unbezahlt(Vault $vault, Zugriffsbereich $bereich, \DateTimeImmutable $heute): array
    {
        return $this->posten($vault, $this->belege->offen(Zahlungsweg::Konto, [PaymentStatus::Offen, PaymentStatus::Teilbezahlt], $bereich), $heute);
    }

    /**
     * Receipts the club reimburses (`erstattung`) not yet (fully) paid out,
     * grouped by submitter - largest open sum first is not possible across
     * currencies, so by name; receipts without a submission (captured
     * internally) form a group with an empty name, last.
     *
     * @return array{empfaenger: list<Erstattungsempfaenger>, unlesbar: int}
     */
    public function erstattungen(Vault $vault, Zugriffsbereich $bereich, \DateTimeImmutable $heute): array
    {
        ['posten' => $posten, 'unlesbar' => $unlesbar] = $this->posten(
            $vault,
            $this->belege->offen(Zahlungsweg::Erstattung, [PaymentStatus::ErstattungOffen, PaymentStatus::Teilbezahlt], $bereich),
            $heute,
        );

        $gruppen = [];
        foreach ($posten as $p) {
            $schluessel = self::schluessel($p->einreicher);
            $gruppen[$schluessel] ??= ['name' => trim($p->einreicher), 'iban' => '', 'posten' => [], 'summen' => []];
            $gruppen[$schluessel]['posten'][] = $p;
            $gruppen[$schluessel]['summen'][$p->waehrung] = ($gruppen[$schluessel]['summen'][$p->waehrung] ?? 0) + $p->offen;
            // The last IBAN a submission of this person gave, in list order.
            if ($p->iban !== '') {
                $gruppen[$schluessel]['iban'] = $p->iban;
            }
        }
        uksort($gruppen, static fn(string $a, string $b): int => ($a === '') <=> ($b === '') ?: strcmp($a, $b));

        return [
            'empfaenger' => array_values(array_map(
                static fn(array $g): Erstattungsempfaenger => new Erstattungsempfaenger($g['name'], $g['iban'], $g['posten'], $g['summen']),
                $gruppen,
            )),
            'unlesbar' => $unlesbar,
        ];
    }

    /**
     * Bookings that need a receipt and have none (`fehlt`) or not enough
     * (`teilweise`, a collective transfer with a receipt missing) - newest
     * first, every period. Rules (M9-6) and "kein Beleg nötig" by hand set
     * `nicht_noetig`, so what they cover is not here.
     *
     * @return list<FehlenderBeleg>
     */
    public function buchungenOhneBeleg(Vault $vault, Zugriffsbereich $bereich): array
    {
        $liste = [];
        foreach ([BankTransactionDocStatus::Fehlt, BankTransactionDocStatus::Teilweise] as $status) {
            foreach ($this->buchungen->liste($vault, new BuchungFilter(belegStatus: $status), $bereich) as $buchung) {
                $fehlt = abs($buchung->amount);
                if ($status === BankTransactionDocStatus::Teilweise) {
                    foreach ($this->zuordnung->fuerBuchung($vault, $buchung->id) as $z) {
                        $fehlt -= $z->amount;
                    }
                }
                $liste[] = new FehlenderBeleg($buchung, max(0, $fehlt));
            }
        }
        usort($liste, static fn(FehlenderBeleg $a, FehlenderBeleg $b): int => [$b->buchung->bookingDate, $b->buchung->id] <=> [$a->buchung->bookingDate, $a->buchung->id]);

        return $liste;
    }

    /**
     * Sums of what is still open, per direction and currency.
     *
     * @param list<OffenerPosten> $posten
     *
     * @return list<array{richtung: InvoiceDirection, waehrung: string, cent: int, anzahl: int}>
     */
    public static function summen(array $posten): array
    {
        $summen = [];
        foreach ($posten as $p) {
            $schluessel = $p->record->direction->value . '|' . $p->waehrung;
            $summen[$schluessel] ??= ['richtung' => $p->record->direction, 'waehrung' => $p->waehrung, 'cent' => 0, 'anzahl' => 0];
            $summen[$schluessel]['cent'] += $p->offen;
            ++$summen[$schluessel]['anzahl'];
        }
        ksort($summen);

        return array_values($summen);
    }

    /**
     * @param list<OffenerBelegRecord> $records
     *
     * @return array{posten: list<OffenerPosten>, unlesbar: int}
     */
    private function posten(Vault $vault, array $records, \DateTimeImmutable $heute): array
    {
        $namen = [];
        $posten = [];
        $unlesbar = 0;
        foreach ($records as $zeile) {
            $record = $zeile->record;
            try {
                $daten = Pruefung::entschluesseln($vault, $record);
                // Nothing allocated yet: all of it is open - no need to read allocations.
                $offen = $record->paymentStatus === PaymentStatus::Teilbezahlt
                    ? $this->zuordnung->zahlungsstand($vault, $record)->offen()
                    : $daten->gross;
                if ($record->supplierId !== null) {
                    $namen[$record->supplierId] ??= $this->lieferantName($vault, $record->supplierId);
                }
            } catch (CryptoException | \JsonException) {
                ++$unlesbar;
                continue;
            }
            $einreichung = EinreichungsDaten::ausChiffrat($zeile->submissionId, $zeile->submissionDekSealed, $zeile->submissionPayloadEnc, $vault);
            $posten[] = new OffenerPosten(
                $record,
                $record->supplierId === null ? '' : $namen[$record->supplierId],
                $daten->invoiceNumber,
                $daten->purposeShort,
                $daten->gross,
                $offen,
                $daten->currency,
                BelegFaelligkeit::fuer($record->dueDate, $heute),
                $zeile->altbestand,
                ($einreichung?->name ?? '') !== '' ? $einreichung->name : ($einreichung?->kontoinhaber ?? ''),
                $einreichung?->iban ?? '',
            );
        }

        return ['posten' => $posten, 'unlesbar' => $unlesbar];
    }

    private function lieferantName(Vault $vault, int $id): string
    {
        $record = $this->lieferantenRepository->find($id);

        return $record === null ? '' : $this->lieferanten->entschluesseln($vault, $record)->data->name;
    }

    private static function schluessel(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
}
