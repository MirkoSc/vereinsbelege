<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\Area;
use App\View\NavItem;
use PHPUnit\Framework\TestCase;

/**
 * The navigation is declared once per area (CLAUDE.md section 3) and grows
 * with every milestone. These are the rules that keep it honest while it
 * does.
 */
final class AreaTest extends TestCase
{
    /**
     * An entry with a route must point at one that exists - otherwise the
     * navigation produces its own 404.
     */
    public function testEveryLinkedEntryHasARegisteredRoute(): void
    {
        $routen = (string) file_get_contents(dirname(__DIR__, 2) . '/app/src/routes.php');

        foreach (Area::cases() as $bereich) {
            foreach ($bereich->navigation() as $eintrag) {
                if (!$eintrag->verfuegbar()) {
                    continue;
                }

                self::assertStringContainsString(
                    "'" . $eintrag->href . "'",
                    $routen,
                    $bereich->value . ' navigation links ' . $eintrag->href . ', which no route serves',
                );
            }
        }
    }

    /**
     * An entry without a route says which milestone brings it - that label
     * is all the user gets instead of a link.
     */
    public function testEveryEntryWithoutARouteNamesItsMilestone(): void
    {
        foreach (Area::cases() as $bereich) {
            foreach ($bereich->navigation() as $eintrag) {
                if ($eintrag->verfuegbar()) {
                    continue;
                }

                self::assertNotNull($eintrag->meilenstein, $eintrag->label . ' has neither route nor milestone');
            }
        }
    }

    public function testEntriesAreUnique(): void
    {
        foreach (Area::cases() as $bereich) {
            $labels = array_map(static fn(NavItem $e): string => $e->label, $bereich->navigation());

            self::assertSame(array_values(array_unique($labels)), $labels, $bereich->value);
        }
    }

    /**
     * The public pages carry no navigation: the submission page is reachable
     * without an account and must not advertise what is behind the login.
     */
    public function testThePublicAreaHasNoNavigation(): void
    {
        self::assertSame([], Area::Oeffentlich->navigation());
    }

    public function testSubPagesKeepTheirNavigationEntryMarked(): void
    {
        $eintrag = new NavItem('Update', '/admin/update');

        self::assertTrue($eintrag->istAktiv('/admin/update'));
        self::assertTrue($eintrag->istAktiv('/admin/update/verlauf'));
        self::assertFalse($eintrag->istAktiv('/admin/updateverlauf'));
        self::assertFalse($eintrag->istAktiv('/admin'));
    }

    public function testAnEntryWithoutARouteIsNeverTheCurrentPage(): void
    {
        self::assertFalse((new NavItem('Belege', meilenstein: 'M6'))->istAktiv('/app/belege'));
    }

    public function testEveryAreaHasAStartPage(): void
    {
        foreach (Area::cases() as $bereich) {
            self::assertStringStartsWith('/', $bereich->startseite());
            self::assertNotSame('', $bereich->bezeichnung());
        }
    }
}
