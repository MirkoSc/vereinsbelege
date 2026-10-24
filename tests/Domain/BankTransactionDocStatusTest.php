<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\BankTransactionDocStatus;
use App\Domain\Belegstand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The derived receipt status of a booking (M10-1, issue #65): without
 * allocations what the booking needs, with them whether their sum reaches
 * the booking's amount - the mandatory test "abgeleitete Status", booking
 * side.
 */
final class BankTransactionDocStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, int, int, BankTransactionDocStatus, int}>
     */
    public static function faelle(): iterable
    {
        yield 'expense without receipt' => [true, -10000, 0, BankTransactionDocStatus::Fehlt, 10000];
        yield 'income that needs none' => [false, 10000, 0, BankTransactionDocStatus::NichtNoetig, 10000];
        yield 'collective transfer with one invoice missing' => [true, -10000, 6000, BankTransactionDocStatus::Teilweise, 4000];
        yield 'fully explained' => [true, -10000, 10000, BankTransactionDocStatus::Zugeordnet, 0];
        yield 'income fully explained although none was needed' => [false, 10000, 10000, BankTransactionDocStatus::Zugeordnet, 0];
        yield 'more allocated than booked (the booking is what the bank says)' => [true, -10000, 12000, BankTransactionDocStatus::Zugeordnet, 0];
    }

    #[DataProvider('faelle')]
    public function testTheStatusFollowsTheSums(bool $noetig, int $betrag, int $zugeordnet, BankTransactionDocStatus $status, int $offen): void
    {
        $stand = new Belegstand($noetig, $betrag, $zugeordnet);

        self::assertSame($status, $stand->status());
        self::assertSame($status, BankTransactionDocStatus::ableiten($noetig, $betrag, $zugeordnet));
        self::assertSame($offen, $stand->offen());
    }

    public function testAllocatedInPartOrInFullCountsAsHavingAReceipt(): void
    {
        self::assertTrue(BankTransactionDocStatus::Zugeordnet->hatBeleg());
        self::assertTrue(BankTransactionDocStatus::Teilweise->hatBeleg());
        self::assertFalse(BankTransactionDocStatus::Fehlt->hatBeleg());
        self::assertFalse(BankTransactionDocStatus::NichtNoetig->hatBeleg());
    }
}
