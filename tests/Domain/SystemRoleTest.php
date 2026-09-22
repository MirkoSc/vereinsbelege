<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Permission;
use App\Domain\PermissionScope;
use App\Domain\SystemRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The six shipped roles (docs/spec/01-sicherheit.md section 4, issue
 * #19/M3-6). Three places carry the same matrix - the spec's table, the
 * enum, and the seed in migrations/010_role.sql - and this test holds them
 * together.
 */
final class SystemRoleTest extends TestCase
{
    /**
     * The table of docs/spec/01-sicherheit.md section 4, copied by hand:
     * `x` = yes, `k` = own cost center only, `-` = no. Columns: Admin,
     * Vorstand, Finanzen, Kassenprüfer, Steuerberater, Vereinsverantwortlicher.
     */
    private const array SPEC = [
        'inbox.view' => 'xxxxxk',
        'document.edit' => 'x-x---',
        'document.submit_internal' => 'xxx--x',
        'supplier.manage' => 'x-x---',
        'bank.import' => 'x-x---',
        'bank.book' => 'x-x---',
        'bank.view' => 'xxxxx-',
        'matching.edit' => 'x-x---',
        'report.view' => 'xxxxxk',
        'export.zip' => 'xxxxx-',
        'export.csv' => 'xxxxx-',
        'archive.import' => 'x-x---',
        'audit.view' => 'xx-x--',
        'admin.users' => 'x-----',
        'admin.vault_grant' => 'x-----',
        'admin.settings' => 'x-----',
        'admin.system' => 'x-----',
    ];

    private const array SPALTEN = [
        SystemRole::Admin,
        SystemRole::Vorstand,
        SystemRole::Finanzen,
        SystemRole::Kassenpruefer,
        SystemRole::Steuerberater,
        SystemRole::Vereinsverantwortlicher,
    ];

    public function testTheSpecTableNamesEveryPermissionAndNothingElse(): void
    {
        self::assertSame(
            array_map(static fn(Permission $p): string => $p->value, Permission::cases()),
            array_keys(self::SPEC),
        );
    }

    /**
     * @return iterable<string, array{SystemRole}>
     */
    public static function rollen(): iterable
    {
        foreach (SystemRole::cases() as $rolle) {
            yield $rolle->value => [$rolle];
        }
    }

    #[DataProvider('rollen')]
    public function testTheEnumMatchesTheSpecTable(SystemRole $rolle): void
    {
        self::assertSame(self::ausSpec($rolle), self::alsText($rolle->standardRechte()));
    }

    #[DataProvider('rollen')]
    public function testTheMigrationSeedMatchesTheEnum(SystemRole $rolle): void
    {
        $seed = self::seed();

        self::assertArrayHasKey($rolle->value, $seed, 'migrations/010_role.sql seeds ' . $rolle->value);
        self::assertSame(self::alsText($rolle->standardRechte()), $seed[$rolle->value]['rechte']);
        self::assertSame($rolle->istExtern(), $seed[$rolle->value]['extern']);
        self::assertSame($rolle->bezeichnung(), $seed[$rolle->value]['name']);
    }

    public function testTheMigrationSeedsExactlyTheSixRoles(): void
    {
        self::assertSame(
            array_map(static fn(SystemRole $r): string => $r->value, SystemRole::cases()),
            array_keys(self::seed()),
        );
    }

    /** "ausschließlich lesend" (docs/spec/01-sicherheit.md section 4). */
    public function testExternalRolesOnlyRead(): void
    {
        foreach (SystemRole::cases() as $rolle) {
            if (!$rolle->istExtern()) {
                continue;
            }
            foreach (array_keys($rolle->standardRechte()) as $wert) {
                self::assertTrue(Permission::from($wert)->istLesend(), $rolle->value . ' holds ' . $wert);
            }
        }
    }

    public function testExactlyKassenpruferAndSteuerberaterAreExternal(): void
    {
        $extern = array_values(array_filter(SystemRole::cases(), static fn(SystemRole $r): bool => $r->istExtern()));

        self::assertSame([SystemRole::Kassenpruefer, SystemRole::Steuerberater], $extern);
    }

    public function testOnlyAdminRightsAreAdminRights(): void
    {
        foreach (Permission::cases() as $recht) {
            self::assertSame(str_starts_with($recht->value, 'admin.'), $recht->istAdmin(), $recht->value);
            self::assertNotSame('', $recht->bezeichnung());
        }
    }

    public function testOnlyCostCenterCapableRightsAreScopedInTheShippedRoles(): void
    {
        foreach (SystemRole::cases() as $rolle) {
            foreach ($rolle->standardRechte() as $wert => $scope) {
                if ($scope === PermissionScope::Kostenstelle) {
                    self::assertTrue(Permission::from($wert)->kostenstellenFaehig(), $rolle->value . ' ' . $wert);
                }
            }
        }
    }

    // ----------------------------------------------------------- scaffolding

    /**
     * @return array<string, string> permission value => 'alle'|'kostenstelle'
     */
    private static function ausSpec(SystemRole $rolle): array
    {
        $spalte = array_search($rolle, self::SPALTEN, true);
        self::assertIsInt($spalte);

        $rechte = [];
        foreach (self::SPEC as $wert => $zeile) {
            $rechte[$wert] = match ($zeile[$spalte]) {
                'x' => 'alle',
                'k' => 'kostenstelle',
                default => null,
            };
        }

        return array_filter($rechte, static fn(?string $s): bool => $s !== null);
    }

    /**
     * @param array<string, PermissionScope> $rechte
     * @return array<string, string>
     */
    private static function alsText(array $rechte): array
    {
        $text = [];
        foreach (Permission::cases() as $recht) {
            if (isset($rechte[$recht->value])) {
                $text[$recht->value] = $rechte[$recht->value]->value;
            }
        }

        return $text;
    }

    /**
     * @return array<string, array{name: string, extern: bool, rechte: array<string, string>}>
     */
    private static function seed(): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/010_role.sql');
        preg_match_all("/^\('([a-z]+)', '([^']+)', 1, ([01]), '(\{[^']*\})', NOW\(\)\)/mu", $sql, $treffer, PREG_SET_ORDER);

        $seed = [];
        foreach ($treffer as [, $schluessel, $name, $extern, $json]) {
            /** @var array<string, string> $roh */
            $roh = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
            $geordnet = [];
            foreach (Permission::cases() as $recht) {
                if (isset($roh[$recht->value])) {
                    $geordnet[$recht->value] = $roh[$recht->value];
                }
            }
            $seed[$schluessel] = ['name' => $name, 'extern' => $extern === '1', 'rechte' => $geordnet];
        }

        return $seed;
    }
}
