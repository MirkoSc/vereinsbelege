<?php

declare(strict_types=1);

namespace App\Tests\Service\Report;

use App\Domain\BankAccount;
use App\Domain\BankAccountData;
use App\Domain\BankAccountKind;
use App\Domain\InvoiceDirection;
use App\Domain\Zugriffsbereich;
use App\Service\Crypto\Vault;
use App\Service\Report\AuswertungsCache;
use App\Service\Report\Auswertungsgrundlage;
use App\Service\Report\Auswertungsquelle;
use App\Service\Report\Datenstand;
use App\Service\Report\Grundlage;
use App\Service\Report\Kontobewegung;
use App\Service\Report\Posten;
use App\Service\Report\ReportService;
use App\Service\Report\Zeitraum;
use PHPUnit\Framework\TestCase;

/**
 * App\Service\Report\AuswertungsCache (issue #72): the report input kept
 * encrypted in the session - a hit returns exactly what was loaded, and
 * every change of data state, account, vault, period, basis or scope, and
 * age, leads to a reload instead of a stale figure. The session holds only
 * ciphertext. Made-up figures only.
 */
final class AuswertungsCacheTest extends TestCase
{
    private const int BENUTZER = 7;

    /** A marker amount that must never appear in the session in clear. */
    private const int BETRAG = 1234567;

    private Vault $tresor;
    private int $jetzt = 1_800_000_000;
    private ?string $stand = 'stand-1';

    /** @var array{grundlage: int, konten: int} */
    private array $aufrufe = ['grundlage' => 0, 'konten' => 0];

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->tresor = Vault::create();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testASecondReadIsServedFromTheSessionWithTheSameLines(): void
    {
        $cache = $this->cache();
        $erst = $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        $zweit = $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());

        self::assertSame(1, $this->aufrufe['grundlage']);
        self::assertEquals($erst, $zweit);
        self::assertSame(Grundlage::Buchungen, $zweit->grundlage);
        self::assertSame(2, $zweit->unlesbar);
        self::assertSame(1, $zweit->fremdwaehrung);
        self::assertCount(2, $zweit->posten);
        self::assertSame(self::BETRAG, $zweit->posten[0]->betrag);
        self::assertSame('2026-03-14', $zweit->posten[0]->datum->format('Y-m-d'));
        self::assertSame(-500, $zweit->posten[1]->betrag);
        self::assertNull($zweit->posten[1]->kostenstelleId);
        self::assertSame('ideell', $zweit->posten[1]->sphaere);
    }

    public function testAChangedDataStateReloadsInsteadOfServingStaleFigures(): void
    {
        $cache = $this->cache();
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        $this->stand = 'stand-2';
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());

        self::assertSame(2, $this->aufrufe['grundlage']);
    }

    public function testWithoutADataStateNothingIsKept(): void
    {
        $this->stand = null;
        $cache = $this->cache();
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());

        self::assertSame(2, $this->aufrufe['grundlage']);
        self::assertSame([], $_SESSION);
    }

    public function testBasisPeriodAndScopeEachHaveTheirOwnEntry(): void
    {
        $cache = $this->cache();
        $alle = Zugriffsbereich::unbeschraenkt();
        $jugend = new Zugriffsbereich(kostenstellen: [3, 1]);
        $varianten = [
            [Grundlage::Buchungen, Zeitraum::jahr(2026), $alle],
            [Grundlage::Belege, Zeitraum::jahr(2026), $alle],
            [Grundlage::Buchungen, Zeitraum::jahr(2025), $alle],
            [Grundlage::Buchungen, Zeitraum::quartal(2026, 1), $alle],
            [Grundlage::Buchungen, Zeitraum::jahr(2026), $jugend],
            [Grundlage::Buchungen, Zeitraum::jahr(2026), new Zugriffsbereich(von: new \DateTimeImmutable('2026-03-01'))],
            [Grundlage::Buchungen, Zeitraum::jahr(2026), new Zugriffsbereich(kostenstellen: [])],
        ];
        foreach ($varianten as [$g, $z, $b]) {
            $cache->grundlage($this->tresor, $g, $z, $b);
        }
        self::assertSame(count($varianten), $this->aufrufe['grundlage']);

        foreach ($varianten as [$g, $z, $b]) {
            $cache->grundlage($this->tresor, $g, $z, $b);
        }
        // The same cost centers in another order are the same scope.
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), new Zugriffsbereich(kostenstellen: [1, 3]));
        self::assertSame(count($varianten), $this->aufrufe['grundlage']);
    }

    public function testAnotherAccountInTheSameSessionDoesNotSeeTheEntries(): void
    {
        $this->cache()->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        $this->cache(self::BENUTZER + 1)->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());

        self::assertSame(2, $this->aufrufe['grundlage']);
    }

    public function testAnotherVaultCannotOpenTheEntries(): void
    {
        $this->cache()->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        $this->cache()->grundlage(Vault::create(), Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());

        self::assertSame(2, $this->aufrufe['grundlage']);
    }

    public function testAnEntryIsUsedOnlyForAShortTime(): void
    {
        $cache = $this->cache();
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        $this->jetzt += AuswertungsCache::LEBENSDAUER;
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        self::assertSame(1, $this->aufrufe['grundlage']);

        $this->jetzt += 1;
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        self::assertSame(2, $this->aufrufe['grundlage']);
    }

    public function testTheSessionHoldsOnlyCiphertext(): void
    {
        $cache = $this->cache();
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), new Zugriffsbereich(kostenstellen: [42]));
        $cache->kontobewegungen($this->tresor, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());

        $session = serialize($_SESSION);
        self::assertStringNotContainsString((string) self::BETRAG, $session);
        self::assertStringNotContainsString('Girokonto Beispiel', $session);
        self::assertStringNotContainsString('DE02120300000000202051', $session);
        self::assertStringNotContainsString('2026-03-14', $session);
        self::assertStringNotContainsString('ideell', $session);
        self::assertStringNotContainsString('"p"', $session);
        // Neither the period nor the scope appears in clear in an entry id.
        self::assertStringNotContainsString('2026-01-01', $session);
    }

    public function testATamperedEntryIsReloadedNotTrusted(): void
    {
        $cache = $this->cache();
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        foreach ($_SESSION['auswertung_cache']['eintraege'] as $id => $eintrag) {
            $chiffrat = $eintrag['chiffrat'];
            $chiffrat[strlen($chiffrat) - 1] = $chiffrat[strlen($chiffrat) - 1] === 'a' ? 'b' : 'a';
            $_SESSION['auswertung_cache']['eintraege'][$id]['chiffrat'] = $chiffrat;
        }

        $g = $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());

        self::assertSame(2, $this->aufrufe['grundlage']);
        self::assertSame(self::BETRAG, $g->posten[0]->betrag);
    }

    public function testAnEntryMovedToAnotherSlotIsRejected(): void
    {
        $cache = $this->cache();
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), new Zugriffsbereich(kostenstellen: [1]));
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        [$eng, $weit] = array_keys($_SESSION['auswertung_cache']['eintraege']);
        // The narrow scope's ciphertext under the wide scope's id.
        $_SESSION['auswertung_cache']['eintraege'][$weit] = $_SESSION['auswertung_cache']['eintraege'][$eng];

        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());

        self::assertSame(3, $this->aufrufe['grundlage']);
    }

    public function testAccountMovementsRoundTripWithTheirAccounts(): void
    {
        $cache = $this->cache();
        $erst = $cache->kontobewegungen($this->tresor, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        $zweit = $cache->kontobewegungen($this->tresor, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());

        self::assertSame(1, $this->aufrufe['konten']);
        self::assertSame(3, $zweit['unlesbar']);
        self::assertCount(1, $zweit['konten']);
        $konto = $zweit['konten'][0];
        self::assertSame(5, $konto->id);
        self::assertSame(BankAccountKind::Bank, $konto->kind);
        self::assertSame('Girokonto Beispiel', $konto->data->name);
        self::assertSame('DE02120300000000202051', $konto->data->iban);
        self::assertSame(100000, $konto->openingBalance);
        self::assertSame('2025-12-31', $konto->openingDate->format('Y-m-d'));
        self::assertFalse($konto->active);
        self::assertEquals($erst['bewegungen'], $zweit['bewegungen']);
        self::assertSame(-self::BETRAG, $zweit['bewegungen'][1]->betrag);

        // Balances and report lines of the same period are separate entries.
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        self::assertSame(1, $this->aufrufe['grundlage']);
    }

    public function testTheNumberOfEntriesIsBoundedOldestFirstOut(): void
    {
        $cache = $this->cache();
        for ($jahr = 2010; $jahr < 2010 + AuswertungsCache::MAX_EINTRAEGE + 1; ++$jahr) {
            $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr($jahr), Zugriffsbereich::unbeschraenkt());
            ++$this->jetzt;
        }
        self::assertCount(AuswertungsCache::MAX_EINTRAEGE, $_SESSION['auswertung_cache']['eintraege']);

        $aufrufe = $this->aufrufe['grundlage'];
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2010 + AuswertungsCache::MAX_EINTRAEGE), Zugriffsbereich::unbeschraenkt());
        self::assertSame($aufrufe, $this->aufrufe['grundlage'], 'the newest is kept');
        $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2010), Zugriffsbereich::unbeschraenkt());
        self::assertSame($aufrufe + 1, $this->aufrufe['grundlage'], 'the oldest went');
    }

    public function testWithoutASessionItLoadsDirectly(): void
    {
        unset($_SESSION);
        try {
            $cache = $this->cache();
            $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
            $cache->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());

            self::assertSame(2, $this->aufrufe['grundlage']);
            self::assertFalse(isset($_SESSION));
        } finally {
            $_SESSION = [];
        }
    }

    public function testTheCalculatorComesFromTheSource(): void
    {
        self::assertInstanceOf(ReportService::class, $this->cache()->rechner());
    }

    // ------------------------------------------------------------ scaffolding

    private function cache(int $benutzer = self::BENUTZER): AuswertungsCache
    {
        $test = $this;
        $quelle = new class ($test) implements Auswertungsquelle {
            public function __construct(private AuswertungsCacheTest $test)
            {
            }

            public function rechner(): ReportService
            {
                return new ReportService();
            }

            public function grundlage(Vault $vault, Grundlage $grundlage, Zeitraum $zeitraum, Zugriffsbereich $bereich): Auswertungsgrundlage
            {
                return $this->test->geladen('grundlage', new Auswertungsgrundlage($grundlage, $zeitraum, [
                    new Posten(new \DateTimeImmutable('2026-03-14'), InvoiceDirection::Ausgabe, AuswertungsCacheTest::betrag(), 2, 7, 3, null, 5, 11, 21),
                    new Posten(new \DateTimeImmutable('2026-04-01'), InvoiceDirection::Einnahme, -500, null, null, null, 'ideell', 5, null, 22),
                ], 2, 1));
            }

            public function kontobewegungen(Vault $vault, Zeitraum $zeitraum, Zugriffsbereich $bereich): array
            {
                $konto = new BankAccount(
                    5,
                    BankAccountKind::Bank,
                    new BankAccountData('Girokonto Beispiel', 'DE02120300000000202051', 'BYLADEM1001', 'Beispielbank'),
                    100000,
                    'EUR',
                    new \DateTimeImmutable('2025-12-31'),
                    false,
                    new \DateTimeImmutable('2025-12-01T10:00:00+01:00'),
                    new \DateTimeImmutable('2026-01-02T11:00:00+01:00'),
                );

                return $this->test->geladen('konten', [
                    'konten' => [$konto],
                    'bewegungen' => [
                        new Kontobewegung(5, new \DateTimeImmutable('2026-01-05'), 2500),
                        new Kontobewegung(5, new \DateTimeImmutable('2026-02-05'), -AuswertungsCacheTest::betrag()),
                    ],
                    'unlesbar' => 3,
                ]);
            }
        };
        $stand = new class ($test) implements Datenstand {
            public function __construct(private AuswertungsCacheTest $test)
            {
            }

            public function fingerabdruck(): ?string
            {
                return $this->test->stand();
            }
        };

        return new AuswertungsCache($quelle, $stand, $benutzer, fn(): int => $this->jetzt);
    }

    /**
     * @template T
     *
     * @param T $wert
     *
     * @return T
     */
    public function geladen(string $art, mixed $wert): mixed
    {
        ++$this->aufrufe[$art];

        return $wert;
    }

    public function stand(): ?string
    {
        return $this->stand;
    }

    public static function betrag(): int
    {
        return self::BETRAG;
    }
}
