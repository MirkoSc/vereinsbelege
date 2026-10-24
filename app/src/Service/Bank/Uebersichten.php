<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransactionDocStatus;
use App\Domain\Belegstand;
use App\Domain\InvoiceDirection;
use App\Domain\OffenerBelegRecord;
use App\Domain\PaymentStatus;
use App\Domain\Zahlungsstand;
use App\Domain\Zahlungsweg;
use App\Domain\Zugriffsbereich;
use App\Repository\InvoiceRepository;
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
        private SupplierService $lieferanten,
        private Zuordnung $zuordnung,
        private Buchungen $buchungen,
    ) {
    }

    /**
     * Receipts paid via an account (`konto`) that are `offen` or
     * `teilbezahlt`: due date first. Either the receipts from before the
     * matching ($altbestand) or the others - only those are decrypted; the
     * other set is just counted.
     *
     * @return array{posten: list<OffenerPosten>, unlesbar: int, andere: int}
     */
    public function unbezahlt(Vault $vault, Zugriffsbereich $bereich, \DateTimeImmutable $heute, bool $altbestand = false): array
    {
        $records = $this->belege->offen(Zahlungsweg::Konto, [PaymentStatus::Offen, PaymentStatus::Teilbezahlt], $bereich);
        $gewaehlt = array_values(array_filter($records, static fn(OffenerBelegRecord $r): bool => $r->altbestand === $altbestand));

        return [...$this->posten($vault, $gewaehlt, $heute), 'andere' => count($records) - count($gewaehlt)];
    }

    /**
     * Receipts the club reimburses (`erstattung`) not yet (fully) paid out,
     * grouped by submitter name and IBAN, by name; receipts without a
     * submission (captured internally) form a group with an empty name,
     * last.
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

        // Name AND IBAN: /einreichen is public, so anybody can submit under
        // somebody else's name with their own IBAN. One group per payee
        // account keeps a foreign IBAN from standing next to another
        // person's sum (E-37).
        $gruppen = [];
        foreach ($posten as $p) {
            $iban = self::iban($p->iban);
            $schluessel = self::schluessel($p->einreicher) . '|' . $iban;
            $gruppen[$schluessel] ??= ['name' => trim($p->einreicher), 'iban' => $iban, 'posten' => [], 'summen' => []];
            $gruppen[$schluessel]['posten'][] = $p;
            $gruppen[$schluessel]['summen'][$p->waehrung] = ($gruppen[$schluessel]['summen'][$p->waehrung] ?? 0) + $p->offen;
        }
        uksort($gruppen, static fn(string $a, string $b): int => (str_starts_with($a, '|')) <=> (str_starts_with($b, '|')) ?: strcmp($a, $b));

        // The same name with more than one account (or with and without
        // one): every such group is marked, so nobody transfers a sum to
        // an account without checking whose it is.
        $kontenJeName = [];
        foreach ($gruppen as $g) {
            $kontenJeName[self::schluessel($g['name'])][] = $g['iban'];
        }

        return [
            'empfaenger' => array_values(array_map(
                static fn(array $g): Erstattungsempfaenger => new Erstattungsempfaenger(
                    $g['name'],
                    $g['iban'],
                    $g['posten'],
                    $g['summen'],
                    $g['name'] !== '' && count($kontenJeName[self::schluessel($g['name'])]) > 1,
                ),
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
                $zugeordnet = 0;
                if ($status === BankTransactionDocStatus::Teilweise) {
                    try {
                        foreach ($this->zuordnung->fuerBuchung($vault, $buchung->id) as $z) {
                            $zugeordnet += $z->amount;
                        }
                    } catch (CryptoException | \JsonException) {
                        // Unreadable allocation: show the booking, without the part.
                        $zugeordnet = 0;
                    }
                }
                $fehlt = new Belegstand($buchung->docRequired, $buchung->amount, $zugeordnet)->offen();
                $liste[] = new FehlenderBeleg($buchung, $fehlt);
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
                $zugeordnet = 0;
                if ($record->paymentStatus === PaymentStatus::Teilbezahlt) {
                    foreach ($this->zuordnung->fuerBeleg($vault, $record->id) as $z) {
                        $zugeordnet += $z->amount;
                    }
                }
                $offen = new Zahlungsstand($record->paymentKind, $daten->gross, $zugeordnet, $record->differenceReason)->offen();
            } catch (CryptoException | \JsonException) {
                ++$unlesbar;
                continue;
            }
            if ($record->supplierId !== null && !isset($namen[$record->supplierId])) {
                try {
                    $namen[$record->supplierId] = $this->lieferanten->finde($vault, $record->supplierId)?->data->name ?? '';
                } catch (CryptoException | \JsonException) {
                    $namen[$record->supplierId] = '';
                }
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

    private static function iban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    private static function schluessel(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
}
