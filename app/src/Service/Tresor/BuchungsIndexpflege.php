<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Repository\BankTransactionRepository;
use App\Repository\VaultRotationRepository;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Import\Dedupschluessel;
use App\Service\Bank\Import\KontoauszugImport;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;

/**
 * `bank_transaction.counterparty_bi` and `.dedup_bi` (issue #90).
 *
 * The duplicate key carries a running number among identical bookings
 * (App\Service\Bank\Import\Dedupschluessel) that is stored nowhere else.
 * It is recovered by trying the numbers under the OLD key until one gives
 * the stored value; the same number then goes under the new key, so a
 * re-import of an old statement is still recognised. When none fits (the
 * values no longer match what was imported), the booking gets the lowest
 * number that is free under both keys - unique, and never one a booking not
 * re-sealed yet still needs.
 */
final readonly class BuchungsIndexpflege implements Indexpflege
{
    /** Identical bookings on one day of one account - far more than real. */
    public const int MAX_LAUFNUMMER = 256;

    public function __construct(
        private BankTransactionRepository $buchungen,
        private VaultRotationRepository $rotation,
    ) {
    }

    public function tabelle(): string
    {
        return 'bank_transaction';
    }

    public function spalten(): array
    {
        return ['bank_transaction.counterparty_bi', 'bank_transaction.dedup_bi'];
    }

    public function pflege(int $id, array $zeile, Vault $tresor, int $alteVersion, \DateTimeImmutable $now): void
    {
        if ($zeile['counterparty_bi'] === null && $zeile['dedup_bi'] === null) {
            return;
        }

        $record = $this->buchungen->find($id);
        if ($record === null) {
            return;
        }
        try {
            $buchung = Buchungen::entschluesseln($tresor, $record);
        } catch (CryptoException | \JsonException) {
            $buchung = null;
        }

        if ($zeile['counterparty_bi'] !== null) {
            $iban = $buchung?->counterpartyIban ?? '';
            $this->rotation->setzeSpalte($this->tabelle(), 'id', $id, 'counterparty_bi', $iban === ''
                ? null
                : $tresor->blindIndex()->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, $iban));
        }

        if ($zeile['dedup_bi'] !== null) {
            $neu = $buchung === null ? null : $this->dedup($tresor, $alteVersion, $record->accountId, (string) $zeile['dedup_bi'], $buchung);
            $this->rotation->setzeSpalte($this->tabelle(), 'id', $id, 'dedup_bi', $neu);
        }
    }

    private function dedup(Vault $tresor, int $alteVersion, int $kontoId, string $gespeichert, \App\Domain\BankTransaction $b): string
    {
        $alt = $tresor->blindIndexFor($alteVersion);
        $neu = $tresor->blindIndex();
        $schluessel = static fn(\App\Service\Crypto\BlindIndex $index, int $k): string => Dedupschluessel::fuerBuchung(
            $index,
            $kontoId,
            $b->bookingDate,
            $b->amount,
            $b->purpose,
            $b->counterpartyIban,
            $k,
        );

        for ($k = 0; $k < self::MAX_LAUFNUMMER; $k++) {
            if (hash_equals($gespeichert, $schluessel($alt, $k))) {
                return $schluessel($neu, $k);
            }
        }

        for ($k = 0; ; $k++) {
            $kandidatNeu = $schluessel($neu, $k);
            $kandidatAlt = $schluessel($alt, $k);
            if ($this->rotation->vorhandeneDedup($kontoId, [$kandidatNeu, $kandidatAlt]) === []) {
                return $kandidatNeu;
            }
        }
    }
}
