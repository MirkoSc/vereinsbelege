<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

use App\Domain\Iban;
use App\Service\Crypto\BlindIndex;

/**
 * The duplicate key of a booking (`bank_transaction.dedup_bi`, M9-4,
 * issue #62, docs/spec/04-bank-und-abgleich.md section 4):
 *
 *   HMAC(account, booking date, amount, normalised purpose,
 *        counterparty IBAN, running number among identical keys)
 *
 * as a blind index (purpose `bank_transaction.dedup`), so only an unlocked
 * session can compute or compare it (CLAUDE.md section 5). Two exports that
 * overlap carry the same booking with the same key; the same export twice
 * yields only keys the account already has.
 *
 * - The purpose is compared lower case and with letters and digits only:
 *   whether the bank joined the 27-character parts of an MT940 purpose with
 *   or without a space, or wrote it in another case, does not matter.
 * - The running number tells apart bookings that agree in everything else
 *   (two equal transfers on one day). It counts in file order, which for
 *   identical bookings is no choice at all - they are interchangeable.
 *
 * Pure and framework-free (CLAUDE.md section 6a): no repository, no
 * session; the caller looks the keys up.
 */
final class Dedupschluessel
{
    /** Never rename: stored keys would no longer match. */
    public const string ZWECK = 'bank_transaction.dedup';

    /**
     * One key per posting, in the order of $posten.
     *
     * @param list<ImportPosten> $posten
     *
     * @return list<string> BlindIndex::BYTES raw bytes each
     */
    public static function fuer(BlindIndex $index, int $kontoId, array $posten): array
    {
        $gesehen = [];
        $schluessel = [];
        foreach ($posten as $p) {
            $basis = self::basis($kontoId, $p);
            $nummer = $gesehen[$basis] ?? 0;
            $gesehen[$basis] = $nummer + 1;
            $schluessel[] = $index->forValue(self::ZWECK, $basis . '|' . $nummer);
        }

        return $schluessel;
    }

    /** The purpose as the key compares it: lower case, letters and digits only. */
    public static function zweck(string $zweck): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($zweck, 'UTF-8'));
    }

    private static function basis(int $kontoId, ImportPosten $p): string
    {
        return json_encode([
            $kontoId,
            $p->umsatz->buchungsdatum->format('Y-m-d'),
            $p->umsatz->cent,
            self::zweck($p->umsatz->details->verwendungszweck ?? ''),
            Iban::normalisieren($p->umsatz->details->iban ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
