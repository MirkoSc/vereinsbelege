<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

use App\Domain\BalanceCheck;
use App\Service\Bank\Kontoauszug;

/**
 * The balance check of a statement import (M9-4, issue #62, docs/spec/
 * 04-bank-und-abgleich.md sections 2-4). A mismatch is a warning in the
 * preview, never a reason to refuse the import.
 *
 * The file against itself:
 * - MT940: per statement opening balance + bookings = closing balance
 *   (App\Service\Bank\Kontoauszug::saldoStimmt()), and between two
 *   statements the closing balance of one is the opening balance of the
 *   next - otherwise a statement is missing from the file.
 * - CSV with a balance column ("Saldo nach Buchung"): every row's balance is
 *   the previous one plus its amount. Without such a column there is
 *   nothing to check (BalanceCheck::NichtVerfuegbar).
 *
 * The file against the application ("Anschluss", 04 section 3 "zuletzt
 * bekannter Kontostand"): the balance before the file's first booking day,
 * as the file states it, against the account's opening balance plus the
 * stored bookings from the opening date up to the day before. The caller
 * works out the latter (it needs the vault) and passes null when it cannot
 * be compared - the file begins before the opening date.
 *
 * Pure and framework-free (CLAUDE.md section 6a).
 */
final class Saldenpruefung
{
    public static function pruefe(ImportDatei $datei, ?int $kontostandVorBeginn, string $kontoWaehrung): SaldenErgebnis
    {
        if (!$datei->hatSalden()) {
            return new SaldenErgebnis(BalanceCheck::NichtVerfuegbar, [], null);
        }

        $punkte = $datei->format->istMt940() ? self::mt940($datei) : self::csv($datei);

        $anschluss = null;
        $anfang = self::dateiAnfangCent($datei);
        $beginn = $datei->zeitraum()[0] ?? null;
        if ($kontostandVorBeginn !== null && $anfang !== null && $beginn !== null) {
            $waehrung = self::waehrung($datei);
            $anschluss = new Saldenpruefpunkt(
                sprintf('Anschluss an den Kontostand vor dem %s', $beginn->format('d.m.Y')),
                $kontostandVorBeginn,
                0,
                $anfang,
                $waehrung,
                $waehrung === $kontoWaehrung,
            );
        }

        $ergebnis = new SaldenErgebnis(BalanceCheck::Ok, $punkte, $anschluss);

        return $ergebnis->abweichungen() === [] ? $ergebnis : new SaldenErgebnis(BalanceCheck::Abweichung, $punkte, $anschluss);
    }

    /**
     * The balance before the file's first booking, as the file states it:
     * the opening balance of the earliest MT940 statement, or the first
     * CSV balance minus its amount. Null when the file carries no balances.
     */
    public static function dateiAnfangCent(ImportDatei $datei): ?int
    {
        if (!$datei->hatSalden()) {
            return null;
        }
        if ($datei->format->istMt940()) {
            return self::chronologisch($datei->auszuege)[0]->anfangssaldo->cent;
        }
        $erster = $datei->posten[0];

        return $erster->saldoNachCent === null ? null : $erster->saldoNachCent - $erster->umsatz->cent;
    }

    /**
     * @return list<Saldenpruefpunkt>
     */
    private static function mt940(ImportDatei $datei): array
    {
        $punkte = [];
        $vorher = null;
        foreach (self::chronologisch($datei->auszuege) as $auszug) {
            if ($vorher !== null) {
                $punkte[] = new Saldenpruefpunkt(
                    sprintf('Übergang zum Auszug vom %s', $auszug->schlusssaldo->datum->format('d.m.Y')),
                    $vorher->schlusssaldo->cent,
                    0,
                    $auszug->anfangssaldo->cent,
                    $auszug->anfangssaldo->waehrung,
                    $vorher->schlusssaldo->waehrung === $auszug->anfangssaldo->waehrung,
                );
            }
            $punkte[] = new Saldenpruefpunkt(
                sprintf(
                    'Auszug %svom %s',
                    $auszug->auszugsnummer === null ? '' : $auszug->auszugsnummer . ' ',
                    $auszug->schlusssaldo->datum->format('d.m.Y'),
                ),
                $auszug->anfangssaldo->cent,
                $auszug->summeUmsaetzeCent(),
                $auszug->schlusssaldo->cent,
                $auszug->schlusssaldo->waehrung,
                $auszug->anfangssaldo->waehrung === $auszug->schlusssaldo->waehrung,
            );
            $vorher = $auszug;
        }

        return $punkte;
    }

    /**
     * Every break of the balance chain; without a break, one line for the
     * whole file.
     *
     * @return list<Saldenpruefpunkt>
     */
    private static function csv(ImportDatei $datei): array
    {
        $posten = $datei->posten;
        $waehrung = self::waehrung($datei);
        $brueche = [];
        $summe = $posten[0]->umsatz->cent;
        for ($i = 1, $n = count($posten); $i < $n; $i++) {
            $p = $posten[$i];
            $summe += $p->umsatz->cent;
            $vorher = (int) $posten[$i - 1]->saldoNachCent;
            if ($vorher + $p->umsatz->cent !== $p->saldoNachCent) {
                $brueche[] = new Saldenpruefpunkt(
                    sprintf('Zeile %d', (int) $p->zeile),
                    $vorher,
                    $p->umsatz->cent,
                    (int) $p->saldoNachCent,
                    $p->waehrung,
                    $p->waehrung === $posten[$i - 1]->waehrung,
                );
            }
        }
        if ($brueche !== []) {
            return $brueche;
        }

        [$von, $bis] = $datei->zeitraum() ?? [null, null];
        $letzter = $posten[count($posten) - 1];

        return [new Saldenpruefpunkt(
            sprintf('Saldo nach Buchung, %s bis %s', $von?->format('d.m.Y'), $bis?->format('d.m.Y')),
            (int) self::dateiAnfangCent($datei),
            $summe,
            (int) $letzter->saldoNachCent,
            $waehrung,
        )];
    }

    /**
     * Statements in the order of their opening balance date; the sort is
     * stable, so statements of one day keep the file's order.
     *
     * @param list<Kontoauszug> $auszuege
     *
     * @return non-empty-list<Kontoauszug>
     */
    private static function chronologisch(array $auszuege): array
    {
        usort($auszuege, static fn(Kontoauszug $a, Kontoauszug $b): int => $a->anfangssaldo->datum <=> $b->anfangssaldo->datum);

        return $auszuege;
    }

    private static function waehrung(ImportDatei $datei): string
    {
        if ($datei->format->istMt940()) {
            return self::chronologisch($datei->auszuege)[0]->anfangssaldo->waehrung;
        }

        return $datei->posten[0]->waehrung ?? 'EUR';
    }
}
