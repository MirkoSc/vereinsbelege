<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\VaultRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * The `vault` table holds VK_pub and nothing else (docs/spec/01-sicherheit.md
 * section 2). What comes back out of it must be a locked vault: sealing yes,
 * reading no.
 */
final class VaultRepositoryTest extends DatabaseTestCase
{
    private VaultRepository $vaults;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->vaults = new VaultRepository($this->pdo());
    }

    public function testAFreshInstallationHasNoVaultYet(): void
    {
        self::assertNull($this->vaults->current(), 'the installer creates it with M3-2');
    }

    public function testTheStoredVaultComesBackLocked(): void
    {
        $tresor = Vault::create();
        $this->vaults->insert($tresor->publicKey());

        $geladen = $this->vaults->current();
        self::assertNotNull($geladen);
        self::assertFalse($geladen->isUnlocked(), 'the private key is never in this table');
        self::assertSame($tresor->publicKey(), $geladen->publicKey());
        self::assertSame(Vault::FIRST_VERSION, $geladen->version);
    }

    public function testWhatTheStoredVaultSealsTheRealOneCanOpen(): void
    {
        $tresor = Vault::create();
        $this->vaults->insert($tresor->publicKey());

        $schluessel = DataKey::generate();
        $versiegelt = $this->vaults->current()?->sealDataKey($schluessel);

        self::assertIsString($versiegelt);
        self::assertTrue($tresor->openDataKey($versiegelt)->equals($schluessel));
    }

    public function testTheNewestGenerationWins(): void
    {
        $alt = Vault::create();
        $neu = Vault::create(2);
        $this->vaults->insert($alt->publicKey());
        $this->vaults->insert($neu->publicKey(), 2);

        $geladen = $this->vaults->current();
        self::assertSame(2, $geladen?->version);
        self::assertSame($neu->publicKey(), $geladen?->publicKey());
    }
}
