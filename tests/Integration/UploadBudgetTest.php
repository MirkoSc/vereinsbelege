<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\UploadBudgetRepository;
use App\Service\Cron\UploadBudgetCleanupTask;
use App\Service\Migration\Migrator;
use App\Service\Submission\EinreichungAbgelehnt;
use App\Service\Submission\EinreichungsEinstellungen;
use App\Service\Submission\FreierSpeicher;
use App\Service\Submission\UploadBudget;
use App\Tests\Support\DatabaseTestCase;

/**
 * The byte budget of the public upload on its own (issue #242, security
 * review N-04): windows, the /64 rule, the threshold and the cron sweep.
 * App\Tests\Integration\SubmissionSpamProtectionTest covers it through the
 * upload endpoint.
 */
final class UploadBudgetTest extends DatabaseTestCase
{
    private const int MB = 1024 * 1024;

    private float|false $frei = false;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
    }

    public function testTheAddressBudgetStartsAgainInTheNextHour(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen(maxDateiMb: 1, budgetIpMbStunde: 1));
        $zehnUhr = new \DateTimeImmutable('2026-10-20 10:15:00');

        self::assertNull($budget->reservieren('198.51.100.7', self::MB, $zehnUhr));
        self::assertSame(EinreichungAbgelehnt::BudgetIp, $budget->reservieren('198.51.100.7', 1, $zehnUhr->modify('+40 minutes')));
        self::assertNull($budget->reservieren('198.51.100.7', self::MB, $zehnUhr->modify('+45 minutes')), 'a new hour');
    }

    public function testTheDailyBudgetRunsFromMidnightToMidnight(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen(maxDateiMb: 1, budgetIpMbStunde: 1, budgetGesamtMbTag: 2));
        $abend = new \DateTimeImmutable('2026-10-20 08:00:00');

        self::assertNull($budget->reservieren('198.51.100.1', self::MB, $abend));
        self::assertNull($budget->reservieren('198.51.100.2', self::MB, $abend->modify('+1 hour')));
        self::assertSame(EinreichungAbgelehnt::BudgetGesamt, $budget->reservieren('198.51.100.3', 1, $abend->modify('+15 hours')));
        self::assertTrue($budget->stand($abend->modify('+15 hours'))->budgetErreicht());

        $morgen = new \DateTimeImmutable('2026-10-21 00:00:01');
        self::assertFalse($budget->stand($morgen)->budgetErreicht());
        self::assertNull($budget->reservieren('198.51.100.3', self::MB, $morgen));
        self::assertSame(self::MB, $budget->stand($morgen)->verbrauchtHeute);
    }

    public function testFreeSpaceBelowTheThresholdRefusesBeforeAnythingIsCounted(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen(minFreiMb: 10));
        $this->frei = 9.0 * self::MB;

        self::assertSame(EinreichungAbgelehnt::SpeicherKnapp, $budget->reservieren('198.51.100.1', 8));
        self::assertSame(EinreichungAbgelehnt::SpeicherKnapp, $budget->pruefeSpeicher());
        self::assertTrue($budget->stand()->speicherKnapp());
        self::assertSame(0, $budget->stand()->verbrauchtHeute);

        $this->frei = 10.0 * self::MB;
        self::assertNull($budget->reservieren('198.51.100.1', 8));
    }

    public function testTheCronSweepsWindowsThatHaveEnded(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen());
        $budget->reservieren('198.51.100.1', 8, new \DateTimeImmutable('2026-10-20 10:15:00'));
        $task = new UploadBudgetCleanupTask(new UploadBudgetRepository($this->pdo()));

        self::assertSame(0, $task->run(new \DateTimeImmutable('2026-10-20 10:59:00')));
        self::assertSame(1, $task->run(new \DateTimeImmutable('2026-10-20 11:00:00')), 'the hour of the address');
        self::assertSame(1, $task->run(new \DateTimeImmutable('2026-10-21 00:00:00')), 'the day');
    }

    public function testAnIpv6AddressCountsAsItsSlash64(): void
    {
        self::assertSame('20010db800010002/64', UploadBudget::ipBereich('2001:db8:1:2::1'));
        self::assertSame('20010db800010002/64', UploadBudget::ipBereich('2001:0db8:0001:0002:ffff:ffff:ffff:ffff'));
        self::assertSame('20010db800010003/64', UploadBudget::ipBereich('2001:db8:1:3::1'));
        self::assertSame('198.51.100.7', UploadBudget::ipBereich('198.51.100.7'));
        self::assertSame('198.51.100.7', UploadBudget::ipBereich('::ffff:198.51.100.7'), 'IPv4-mapped is the IPv4 address');
        self::assertSame('', UploadBudget::ipBereich(''));
    }

    private function budget(EinreichungsEinstellungen $einstellungen): UploadBudget
    {
        return new UploadBudget(
            new UploadBudgetRepository($this->pdo()),
            $einstellungen,
            new FreierSpeicher(sys_get_temp_dir(), fn(): float|false => $this->frei),
        );
    }
}
