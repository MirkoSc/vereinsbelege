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
 * The moment is the whole point: it exists once per installation and cannot
 * be produced again from the database alone. A MAC that is missing later
 * (deleted, or a row added by an older release after a rollback) is never
 * filled in here - it stays a failed check until the paper recovery key
 * proves the key (VaultRepository::beglaubige()).
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
     * @param ?AuditLog $audit one `tresor.anker_gesetzt` per generation
     */
    public function __construct(
        private VaultRepository $vaults,
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
     * @return list<int> the generations anchored
     */
    public function ausfuehren(): array
    {
        $versionen = $this->vaults->erstbeglaubigen();
        // The MACs are what counts: a failing audit entry must not fail the
        // migration step - its retry finds nothing pending and could not
        // anchor again (the way out then is the recovery key).
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
     * Whether the anchor migration is among these - for the installer's
     * restore, which learns the final server key only requests later.
     *
     * @param list<Migration> $migrationen
     */
    public static function darunter(array $migrationen): bool
    {
        foreach ($migrationen as $migration) {
            if (self::betrifft($migration)) {
                return true;
            }
        }

        return false;
    }
}
