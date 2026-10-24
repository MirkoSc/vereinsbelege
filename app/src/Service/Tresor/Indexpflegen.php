<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\CategoryRepository;
use App\Repository\JobRepository;
use App\Repository\SupplierRepository;
use App\Repository\VaultRotationRepository;
use App\Service\Bank\BankAccountService;
use App\Service\MasterData\SupplierService;

/**
 * Every blind index handler of the key rotation (issue #90), built from one
 * connection - the one list the web app and the tests share.
 *
 * Columns that are deliberately NOT here:
 *   - `user.email_bi`: keyed with the server key, not the vault.
 *   - `export_run_upload.plan_hash`: a vault-keyed digest of an upload plan,
 *     compared only with itself; after a rotation the next run sees a
 *     "changed plan" and starts its upload over - which skips files that
 *     are already there (App\Service\Export\Nextcloud\NextcloudAblage).
 */
final class Indexpflegen
{
    /**
     * Blind indexes in tables WITHOUT a sealed key of their own: the
     * row-by-row chain never visits them, the rotation's last step clears
     * them in one go.
     *   - `bank_import.source_bi`: the account line of an imported statement,
     *     only used to suggest the account for the next file of the same
     *     source; recomputing means parsing every stored file. Effect: the
     *     first statement after a rotation is matched by the account's IBAN
     *     (recomputed) or picked by hand once.
     */
    public const array ZU_LEEREN = ['bank_import.source_bi'];

    /**
     * @return array<string, Indexpflege> table => handler
     */
    public static function alle(\PDO $pdo): array
    {
        $rotation = new VaultRotationRepository($pdo);
        $lieferanten = new SupplierRepository($pdo);
        $handler = [
            new LieferantenIndexpflege(new SupplierService($pdo, $lieferanten, new CategoryRepository($pdo)), $lieferanten),
            new RechnungsIndexpflege($rotation),
            new KontoIndexpflege(new BankAccountService($pdo, new BankAccountRepository($pdo)), $rotation),
            new BuchungsIndexpflege(new BankTransactionRepository($pdo), $rotation),
            new DokumentIndexpflege(new JobRepository($pdo), $rotation),
        ];

        $alle = [];
        foreach ($handler as $h) {
            $alle[$h->tabelle()] = $h;
        }

        return $alle;
    }
}
