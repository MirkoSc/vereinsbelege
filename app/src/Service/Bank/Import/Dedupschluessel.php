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

    /**
     * The key of a booking that is already stored, from its decrypted
     * values and running number - what the vault key rotation recomputes
     * (issue #90). The same values an import saw give the same key.
     */
    public static function fuerBuchung(
        BlindIndex $index,
        int $kontoId,
        \DateTimeImmutable $buchungsdatum,
        int $cent,
        string $verwendungszweck,
        string $iban,
        int $nummer,
    ): string {
        return $index->forValue(self::ZWECK, self::basisAus($kontoId, $buchungsdatum, $cent, $verwendungszweck, $iban) . '|' . $nummer);
    }

    private static function basis(int $kontoId, ImportPosten $p): string
    {
        return self::basisAus(
            $kontoId,
            $p->umsatz->buchungsdatum,
            $p->umsatz->cent,
            $p->umsatz->details->verwendungszweck ?? '',
            $p->umsatz->details->iban ?? '',
        );
    }

    private static function basisAus(int $kontoId, \DateTimeImmutable $buchungsdatum, int $cent, string $verwendungszweck, string $iban): string
    {
        return json_encode([
            $kontoId,
            $buchungsdatum->format('Y-m-d'),
            $cent,
            self::zweck($verwendungszweck),
            Iban::normalisieren($iban),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
