<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Domain\AuditAction;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditLog;
use App\Service\Migration\Migration;

/**
 * Trust on first use for the vault anchor (issue #240,
 * docs/spec/01-sicherheit.md section 2 "Anker des Tresor-Schlüssels"):
 * installations from before migration 047 have generations without
 * `public_key_mac`. Right after that migration - in the same request, via
 * the Migrator's after-hook - every such row is anchored as it stands.
 *
 * Once per installation, recorded OUTSIDE the database in
 * shared/vault_anchor.flag (Paths::vaultAnchorFlagFile()), which the
 * installer writes together with config.php. Whether the migration is
 * "pending" is a fact of the database - and so in the hands of whoever can
 * write it (drop the column, lower schema_version, swap the key, wait for
 * the next update). The flag is not: with it present nothing is ever
 * anchored without proof. It is written BEFORE the MACs, and if it cannot
 * be written nothing is anchored (the recovery key is the way out).
 *
 * Restoring a backup never anchors on trust: its rows without MAC stay
 * unanchored until the recovery key proves the key.
 *
 * The assumption this makes - the database is not tampered with at the
 * moment of the update - is documented in the spec; a key swapped before it
 * would be anchored, as it would have been used without the anchor anyway.
 */
final readonly class Erstbeglaubigung
{
    /** The name of migration 047 - by name, not number, should it be renumbered. */
    public const string MIGRATION = 'vault_anchor';

    /**
     * @param VaultRepository $vaults with the anchor of the server key that
     *        the installation keeps using
     * @param string $markierung Paths::vaultAnchorFlagFile()
     * @param ?AuditLog $audit one `tresor.anker_gesetzt` per generation
     */
    public function __construct(
        private VaultRepository $vaults,
        private string $markierung,
        private ?AuditLog $audit = null,
    ) {
    }

    /** For Migrator's $nachMigration. */
    public function __invoke(Migration $migration): void
    {
        if (self::betrifft($migration)) {
            $this->ausfuehren();
        }
    }

    /**
     * @return list<int> the generations anchored - none when the anchor of
     *         this installation was initialised before
     */
    public function ausfuehren(): array
    {
        if (is_file($this->markierung) || !self::markieren($this->markierung)) {
            return [];
        }
        $versionen = $this->vaults->erstbeglaubigen();
        // The MACs are what counts: a failing audit entry must not fail the
        // migration step.
        try {
            foreach ($versionen as $version) {
                $this->audit?->record(AuditAction::TresorAnkerGesetzt, null, '', $version, ['weg' => 'erstbeglaubigung']);
            }
        } catch (\Throwable) {
            // Audit is best effort here; the anchors are written.
        }

        return $versionen;
    }

    public static function betrifft(Migration $migration): bool
    {
        return $migration->name === self::MIGRATION;
    }

    /**
     * Records that this installation's anchor exists - the installer calls
     * this next to config.php. Idempotent.
     */
    public static function markieren(string $datei): bool
    {
        if (is_file($datei)) {
            return true;
        }

        return @file_put_contents($datei, "vault anchor initialised\n", LOCK_EX) !== false;
    }
}
