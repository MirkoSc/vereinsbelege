<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\AiProviderRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\Document\KiAuslesung;
use App\Service\Document\Texterkennung;
use App\Service\Ki\KiAbfrage;
use App\Service\Ki\KiPrompts;
use App\Service\Ki\LlmClient;
use App\Service\Processing\KiAuslese\Auslesung;
use App\Service\Storage\BlobService;

/**
 * The `ai_extract` handler (issue #47/M7-5) as app/src/bootstrap.php builds
 * it, with a test double instead of the real AI client - for the tests of
 * the job itself and of the pages that read its suggestions.
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
        );
    }
}
