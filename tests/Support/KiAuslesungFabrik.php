<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\AiCallLogRepository;
use App\Repository\AiProviderRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\SupplierRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Document\KiAuslesung;
use App\Service\Document\LieferantenAufloesung;
use App\Service\Document\Texterkennung;
use App\Service\Ki\KiAbfrage;
use App\Service\Ki\KiPrompts;
use App\Service\Ki\LlmClient;
use App\Service\MasterData\SupplierAufloesung;
use App\Service\MasterData\SupplierService;
use App\Service\Processing\KiAuslese\Auslesung;
use App\Service\Storage\BlobService;

/**
 * The `ai_extract` handler (issue #47/M7-5) as app/src/bootstrap.php builds
 * it, with a test double instead of the real AI client - for the tests of
 * the job itself and of the pages that read its suggestions; and the
 * `resolve_supplier` handler that follows it (issue #48/M7-6).
 */
final class KiAuslesungFabrik
{
    public static function bauen(\PDO $pdo, BlobService $blobs, ServerCrypto $crypto, ?LlmClient $client = null): KiAuslesung
    {
        $prompts = new KiPrompts(dirname(__DIR__, 2) . '/app/prompts');

        return new KiAuslesung(
            new DocumentRepository($pdo),
            new DocumentArtifactRepository($pdo),
            new JobRepository($pdo),
            $blobs,
            new Texterkennung(new DocumentRepository($pdo), new DocumentArtifactRepository($pdo), $blobs),
            new CategoryRepository($pdo),
            new CostCenterRepository($pdo),
            new AiProviderRepository($pdo, $crypto),
            new KiAbfrage($client ?? new FakeLlmClient([]), $prompts),
            new Auslesung($prompts),
            new AiCallLogRepository($pdo),
            self::aufloesung($pdo),
        );
    }

    /**
     * The `resolve_supplier` handler (issue #48/M7-6) as app/src/bootstrap.php
     * builds it - it reads the suggestions of the `ai_extract` one above.
     */
    public static function lieferanten(\PDO $pdo, BlobService $blobs, ServerCrypto $crypto, AuditLog $audit): LieferantenAufloesung
    {
        return new LieferantenAufloesung(
            $pdo,
            new DocumentRepository($pdo),
            new DocumentArtifactRepository($pdo),
            self::bauen($pdo, $blobs, $crypto),
            new Texterkennung(new DocumentRepository($pdo), new DocumentArtifactRepository($pdo), $blobs),
            self::aufloesung($pdo),
            $audit,
        );
    }

    public static function aufloesung(\PDO $pdo): SupplierAufloesung
    {
        $lieferanten = new SupplierRepository($pdo);

        return new SupplierAufloesung($lieferanten, new SupplierService($pdo, $lieferanten, new CategoryRepository($pdo)));
    }
}
