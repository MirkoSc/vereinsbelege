<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank;

use App\Service\Bank\AbgleichFilter;
use PHPUnit\Framework\TestCase;

/**
 * Filters of the matching page (M10-4, issue #68, E-41): structure in the
 * URL, amount and search text only from POST and never back into a URL.
 */
final class AbgleichFilterTest extends TestCase
{
    public function testAGetTakesOnlyTheStructure(): void
    {
        $f = AbgleichFilter::aus(['konto' => '3', 'von' => '2026-01-01', 'bis' => '2026-02-30', 'beleg' => '17', 'seite_belege' => '2', 'betrag' => '12,50', 'suche' => 'Müller']);

        self::assertSame(3, $f->kontoId);
        self::assertSame('2026-01-01', $f->von?->format('Y-m-d'));
        self::assertNull($f->bis, 'no such day');
        self::assertSame(17, $f->belegId);
        self::assertSame(2, $f->seiteBelege);
        self::assertNull($f->betrag, 'an amount never comes from the URL');
        self::assertSame('', $f->suche);
        self::assertFalse($f->vertraulich());
    }

    public function testAPostTakesAmountAndSearchButTheUrlKeepsThemOut(): void
    {
        $f = AbgleichFilter::aus([], ['konto' => '3', 'betrag' => '-1.234,50', 'suche' => '  Getränke Müller  ', 'beleg' => '5', 'seite_buchungen' => '4']);

        self::assertSame(123450, $f->betrag, 'without a sign');
        self::assertSame('Getränke Müller', $f->suche);
        self::assertTrue($f->vertraulich());
        self::assertSame(['konto' => '3', 'beleg' => '5', 'seite_buchungen' => '4'], $f->abfrage());
        self::assertSame('/app/abgleich?konto=3&beleg=17', $f->url(['beleg' => 17, 'seite_buchungen' => null]));
        self::assertStringNotContainsString('1234', $f->url());
        self::assertStringNotContainsString('Getr', $f->url());
    }

    public function testRubbishIsIgnored(): void
    {
        $f = AbgleichFilter::aus(['konto' => '-1', 'beleg' => '0', 'seite_belege' => 'x', 'von' => '1.1.2026'], []);

        self::assertNull($f->kontoId);
        self::assertNull($f->belegId);
        self::assertSame(1, $f->seiteBelege);
        self::assertNull($f->von);
        self::assertSame('/app/abgleich', $f->url());
        self::assertNull(AbgleichFilter::aus([], ['betrag' => 'zwölf'])->betrag);
    }

    public function testMatching(): void
    {
        $f = new AbgleichFilter(von: new \DateTimeImmutable('2026-03-01'), bis: new \DateTimeImmutable('2026-03-31'), betrag: 5000, suche: 'müller');

        self::assertTrue($f->imZeitraum(new \DateTimeImmutable('2026-03-31 23:00')));
        self::assertFalse($f->imZeitraum(new \DateTimeImmutable('2026-04-01')));
        self::assertTrue($f->passtZumBetrag(-5000));
        self::assertTrue($f->passtZumBetrag(7000, 5000));
        self::assertFalse($f->passtZumBetrag(4999));
        self::assertTrue($f->passtZurSuche('', 'Getränke MÜLLER'));
        self::assertFalse($f->passtZurSuche('Stadtwerke'));
        self::assertTrue(new AbgleichFilter()->passtZumBetrag(1));
    }
}
