<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Differenzgrund;
use App\Domain\Erstattungsart;
use App\Domain\PaymentStatus;
use App\Domain\Zahlungsstand;
use App\Domain\Zahlungsweg;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The derived payment status of a receipt (M10-1, issue #65, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Modell"): a pure function of
 * Zahlungsweg, gross amount, allocated sum and a closed difference - the
 * mandatory test "abgeleitete Status" of the spec, receipt side.
 */
final class PaymentStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{Zahlungsweg, int, int, Differenzgrund|null, PaymentStatus, int}>
     */
    public static function faelle(): iterable
    {
        yield 'nothing allocated' => [Zahlungsweg::Konto, 10000, 0, null, PaymentStatus::Offen, 10000];
        yield 'one instalment' => [Zahlungsweg::Konto, 10000, 4000, null, PaymentStatus::Teilbezahlt, 6000];
        yield 'one cent short' => [Zahlungsweg::Konto, 10000, 9999, null, PaymentStatus::Teilbezahlt, 1];
        yield 'exactly covered' => [Zahlungsweg::Konto, 10000, 10000, null, PaymentStatus::Bezahlt, 0];
        yield 'covered beyond the gross amount (gross lowered later)' => [Zahlungsweg::Konto, 9000, 10000, null, PaymentStatus::Bezahlt, 0];
        yield 'cash discount closed' => [Zahlungsweg::Konto, 10000, 9700, Differenzgrund::Skonto, PaymentStatus::Bezahlt, 0];
        yield 'a closed difference without anything allocated does not settle' => [Zahlungsweg::Konto, 10000, 0, Differenzgrund::Sonstiges, PaymentStatus::Offen, 10000];
        yield 'reimbursement open' => [Zahlungsweg::Erstattung, 2550, 0, null, PaymentStatus::ErstattungOffen, 2550];
        yield 'reimbursement in part' => [Zahlungsweg::Erstattung, 2550, 1000, null, PaymentStatus::Teilbezahlt, 1550];
        yield 'reimbursed' => [Zahlungsweg::Erstattung, 2550, 2550, null, PaymentStatus::Erstattet, 0];
        yield 'reimbursed with a difference' => [Zahlungsweg::Erstattung, 2550, 2500, Differenzgrund::Sonstiges, PaymentStatus::Erstattet, 0];
        yield 'paid in cash is paid, whatever the sums' => [Zahlungsweg::Bar, 1299, 0, null, PaymentStatus::Bezahlt, 0];
        yield 'no payment expected' => [Zahlungsweg::Keine, 1299, 0, null, PaymentStatus::KeinZahlungsbezug, 0];
        yield 'a zero receipt stays open until something is allocated' => [Zahlungsweg::Konto, 0, 0, null, PaymentStatus::Offen, 0];
        yield 'a negative gross amount cannot be settled by a positive allocation' => [Zahlungsweg::Konto, -5000, 0, null, PaymentStatus::Offen, 0];
    }

    #[DataProvider('faelle')]
    public function testTheStatusAndTheOpenAmountFollowTheSums(Zahlungsweg $weg, int $brutto, int $zugeordnet, ?Differenzgrund $differenz, PaymentStatus $status, int $offen): void
    {
        $stand = new Zahlungsstand($weg, $brutto, $zugeordnet, $differenz);

        self::assertSame($status, $stand->status());
        self::assertSame($status, PaymentStatus::ableiten($weg, $brutto, $zugeordnet, $differenz));
        self::assertSame($offen, $stand->offen());
    }

    public function testOpenMeansMoneyIsStillExpected(): void
    {
        self::assertTrue(PaymentStatus::Offen->offen());
        self::assertTrue(PaymentStatus::Teilbezahlt->offen());
        self::assertTrue(PaymentStatus::ErstattungOffen->offen());
        self::assertFalse(PaymentStatus::Bezahlt->offen());
        self::assertFalse(PaymentStatus::Erstattet->offen());
        self::assertFalse(PaymentStatus::KeinZahlungsbezug->offen());

        self::assertTrue(PaymentStatus::Bezahlt->ausgeglichen());
        self::assertTrue(PaymentStatus::Erstattet->ausgeglichen());
        self::assertFalse(PaymentStatus::KeinZahlungsbezug->ausgeglichen(), 'nothing was settled, nothing was due');
    }

    public function testTheDifferenceAmountIsWhatAReasonWouldExplain(): void
    {
        self::assertSame(300, (new Zahlungsstand(Zahlungsweg::Konto, 10000, 9700, null))->differenzbetrag());
        self::assertSame(0, (new Zahlungsstand(Zahlungsweg::Konto, 10000, 0, null))->differenzbetrag(), 'nothing allocated: nothing to close');
        self::assertSame(0, (new Zahlungsstand(Zahlungsweg::Konto, 10000, 10000, null))->differenzbetrag());
    }

    public function testOnlyKontoAndErstattungExpectBookings(): void
    {
        self::assertTrue(Zahlungsweg::Konto->erwartetBuchung());
        self::assertTrue(Zahlungsweg::Erstattung->erwartetBuchung());
        self::assertFalse(Zahlungsweg::Bar->erwartetBuchung());
        self::assertFalse(Zahlungsweg::Keine->erwartetBuchung());
    }

    public function testASubmissionsWishPrefillsTheZahlungsweg(): void
    {
        self::assertSame(Zahlungsweg::Erstattung, Zahlungsweg::ausErstattungsart(Erstattungsart::Ueberweisung));
        self::assertSame(Zahlungsweg::Bar, Zahlungsweg::ausErstattungsart(Erstattungsart::Bar));
        self::assertSame(Zahlungsweg::Konto, Zahlungsweg::ausErstattungsart(Erstattungsart::Keine), 'already paid by the club: a booking of the club exists');
    }
}
