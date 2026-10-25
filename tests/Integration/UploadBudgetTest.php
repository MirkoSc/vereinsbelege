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
use App\Service\Upload\UploadService;
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

        self::assertNull($budget->reservieren('198.51.100.7', self::id(), self::MB, $zehnUhr));
        self::assertSame(EinreichungAbgelehnt::BudgetIp, $budget->reservieren('198.51.100.7', self::id(), 1, $zehnUhr->modify('+40 minutes')));
        self::assertNull($budget->reservieren('198.51.100.7', self::id(), self::MB, $zehnUhr->modify('+45 minutes')), 'a new hour');
    }

    public function testTheDailyBudgetCountsStoredBytesFromMidnightToMidnight(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen(maxDateiMb: 1, budgetIpMbStunde: 1, budgetGesamtMbTag: 2));
        $morgens = new \DateTimeImmutable('2026-10-20 08:00:00');

        self::assertNull($budget->verbuchen(self::MB, $morgens));
        self::assertNull($budget->verbuchen(self::MB, $morgens->modify('+1 hour')));
        self::assertSame(EinreichungAbgelehnt::BudgetGesamt, $budget->verbuchen(1, $morgens->modify('+15 hours')));
        self::assertSame(EinreichungAbgelehnt::BudgetGesamt, $budget->reservieren('198.51.100.3', self::id(), 1, $morgens->modify('+15 hours')), 'a full day turns new uploads away up front');
        self::assertTrue($budget->stand($morgens->modify('+15 hours'))->budgetErreicht());

        $budget->zurueckbuchen(self::MB);
        self::assertNull($budget->verbuchen(self::MB, $morgens->modify('+15 hours')), 'a failed store gives its charge back');

        $morgen = new \DateTimeImmutable('2026-10-21 00:00:01');
        self::assertFalse($budget->stand($morgen)->budgetErreicht());
        self::assertNull($budget->verbuchen(self::MB, $morgen));
        self::assertSame(self::MB, $budget->stand($morgen)->verbrauchtHeute);
    }

    /**
     * Coordinator review of #242: announcing without sending must not close
     * the form for everybody. Five addresses announce as much as they may
     * and never send a byte - the day stays untouched and a real visitor
     * still gets through.
     */
    public function testAnnouncingWithoutSendingCannotUseUpTheDay(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen());
        $angreifer = ['203.0.113.1', '203.0.113.2', '2001:db8:0:1::1', '2001:db8:0:2::1', '2001:db8:0:3::1'];

        $angenommen = 0;
        foreach ($angreifer as $ip) {
            for ($i = 0; $i < 10; $i++) {
                if ($budget->reservieren($ip, self::id(), 10 * self::MB) === null) {
                    $angenommen++;
                }
            }
        }

        self::assertSame(5 * 3, $angenommen, 'three open files per address');
        self::assertSame(0, $budget->stand()->verbrauchtHeute);
        self::assertNull($budget->reservieren('198.51.100.99', self::id(), 10 * self::MB), 'a real visitor is not affected');
    }

    public function testOpenUploadsPerAddressAreCappedAndComeBackOnFinishOrAbort(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen(maxDateiMb: 10, offenMbIp: 20));
        $erste = self::id();

        self::assertNull($budget->reservieren('198.51.100.7', $erste, 10 * self::MB));
        self::assertNull($budget->reservieren('198.51.100.7', self::id(), 10 * self::MB));
        self::assertSame(EinreichungAbgelehnt::BudgetIp, $budget->reservieren('198.51.100.7', self::id(), 1));
        self::assertNull($budget->reservieren('2001:db8::1', self::id(), 10 * self::MB), 'another address has its own');

        $budget->freigeben($erste);
        $budget->freigeben($erste);
        self::assertNull($budget->reservieren('198.51.100.7', self::id(), 10 * self::MB), 'given back once, not twice');
        self::assertSame(EinreichungAbgelehnt::BudgetIp, $budget->reservieren('198.51.100.7', self::id(), 1));
    }

    public function testTheCronGivesBackWhatWasAnnouncedButNeverSent(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen(maxDateiMb: 10, offenMbIp: 10));
        $task = new UploadBudgetCleanupTask(new UploadBudgetRepository($this->pdo()));
        $angekuendigt = new \DateTimeImmutable('2026-10-20 10:15:00');

        self::assertNull($budget->reservieren('198.51.100.7', self::id(), 10 * self::MB, $angekuendigt));
        self::assertSame(EinreichungAbgelehnt::BudgetIp, $budget->reservieren('198.51.100.7', self::id(), 1, $angekuendigt->modify('+2 hours')));

        $task->run($angekuendigt->modify('+23 hours'));
        self::assertSame(EinreichungAbgelehnt::BudgetIp, $budget->reservieren('198.51.100.7', self::id(), 1, $angekuendigt->modify('+23 hours')), 'not before the upload cleanup gives up');

        $task->run($angekuendigt->modify('+24 hours +1 minute'));
        self::assertNull($budget->reservieren('198.51.100.7', self::id(), 10 * self::MB, $angekuendigt->modify('+24 hours +1 minute')));
    }

    public function testFreeSpaceBelowTheThresholdRefusesBeforeAnythingIsCounted(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen(minFreiMb: 10));
        $this->frei = 9.0 * self::MB;

        self::assertSame(EinreichungAbgelehnt::SpeicherKnapp, $budget->reservieren('198.51.100.1', self::id(), 8));
        self::assertSame(EinreichungAbgelehnt::SpeicherKnapp, $budget->pruefeSpeicher());
        self::assertTrue($budget->stand()->speicherKnapp());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM upload_reservierung')->fetchColumn());

        $this->frei = 10.0 * self::MB;
        self::assertNull($budget->reservieren('198.51.100.1', self::id(), 8));
    }

    public function testTheCronSweepsWindowsThatHaveEnded(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen());
        $jetzt = new \DateTimeImmutable('2026-10-20 10:15:00');
        $budget->reservieren('198.51.100.1', self::id(), 8, $jetzt);
        $budget->verbuchen(8, $jetzt);
        $task = new UploadBudgetCleanupTask(new UploadBudgetRepository($this->pdo()));

        self::assertSame(0, $task->run(new \DateTimeImmutable('2026-10-20 10:59:00')));
        self::assertSame(1, $task->run(new \DateTimeImmutable('2026-10-20 11:00:00')), 'the hour of the address');
        self::assertSame(1, $task->run(new \DateTimeImmutable('2026-10-21 00:00:00')), 'the day');
        self::assertSame(2, $task->run(new \DateTimeImmutable('2026-10-21 10:16:00')), 'the reservation and its empty counter');
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM upload_offen')->fetchColumn());
    }

    /**
     * 2026-10-25: 02:00-03:00 happens twice. Each of the two hours is a
     * window of its own and ends an hour after it starts.
     */
    public function testTheHourWindowsSurviveTheAutumnClockChange(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen(maxDateiMb: 1, budgetIpMbStunde: 1));
        $berlin = new \DateTimeZone('Europe/Berlin');
        $sommerzeit = new \DateTimeImmutable('2026-10-25 00:30:00', new \DateTimeZone('UTC'))->setTimezone($berlin);
        $winterzeit = new \DateTimeImmutable('2026-10-25 01:10:00', new \DateTimeZone('UTC'))->setTimezone($berlin);
        self::assertSame('02:30 02:10', $sommerzeit->format('H:i') . ' ' . $winterzeit->format('H:i'));
        $task = new UploadBudgetCleanupTask(new UploadBudgetRepository($this->pdo()));

        self::assertNull($budget->reservieren('198.51.100.7', self::id(), self::MB, $sommerzeit));
        self::assertSame(0, $task->run($sommerzeit->modify('+29 minutes')), 'nothing swept inside the window');
        self::assertSame(EinreichungAbgelehnt::BudgetIp, $budget->reservieren('198.51.100.7', self::id(), 1, $sommerzeit->modify('+29 minutes')));
        self::assertNull($budget->reservieren('198.51.100.7', self::id(), self::MB, $winterzeit), 'the second 02:00 hour is a new window');
    }

    public function testAnEmptyFileReservesNothing(): void
    {
        $budget = $this->budget(new EinreichungsEinstellungen());

        self::assertNull($budget->reservieren('198.51.100.7', self::id(), 0));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM upload_reservierung')->fetchColumn());
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

    private static function id(): string
    {
        return UploadService::neueId();
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
