<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The packaging rules of the worker image (docs/spec/07-worker.md section 7,
 * issue #55, E-53) that would otherwise only break on a Raspberry Pi: the
 * compose template, the Dockerfile and the publishing workflow. What the
 * image does is tested end to end by tests/e2e/worker/run.sh.
 */
final class WorkerImageTest extends TestCase
{
    private static function datei(string $pfad): string
    {
        $inhalt = file_get_contents(dirname(__DIR__, 2) . '/' . $pfad);
        self::assertIsString($inhalt, $pfad);

        return $inhalt;
    }

    public function testTheTemplateGivesTheRunningJobTimeToBeHandedBack(): void
    {
        // ~5 s to end the tool + up to 10 s for `fail`; Docker's default is 10 s.
        self::assertSame(1, preg_match('/^\s*stop_grace_period:\s*(\d+)s\s*$/m', self::datei('worker/docker-compose.yml'), $treffer));
        self::assertGreaterThanOrEqual(30, (int) $treffer[1]);
    }

    public function testTheTemplateKeepsTheWorkingDirectoryInMemoryAndOnlyTheIdentityOnAVolume(): void
    {
        $vorlage = self::datei('worker/docker-compose.yml');

        self::assertMatchesRegularExpression('/^\s*tmpfs:\s*\n\s*-\s*\/arbeit:size=/m', $vorlage);
        self::assertMatchesRegularExpression('/^\s*volumes:\s*\n\s*-\s*daten:\/daten\s*$/m', $vorlage);
        self::assertMatchesRegularExpression('/^\s*restart:\s*unless-stopped\s*$/m', $vorlage);
        self::assertMatchesRegularExpression('/^\s*mem_limit:\s*\S+/m', $vorlage);
        self::assertMatchesRegularExpression('/max-size:/', $vorlage);
        self::assertMatchesRegularExpression('/^\s*image:\s*ghcr\.io\/mirkosc\/vereinsbelege-worker:stable\s*$/m', $vorlage);
    }

    public function testTheDockerfilePinsItsBaseImagesAndRefusesAnImageWithoutPcntl(): void
    {
        $dockerfile = self::datei('worker/Dockerfile');

        self::assertMatchesRegularExpression('/^ARG PHP_IMAGE=php:\d+\.\d+\.\d+-cli-trixie$/m', $dockerfile, 'PHP base pinned to a patch release');
        self::assertMatchesRegularExpression('/^ARG COMPOSER_IMAGE=composer:\d+\.\d+\.\d+$/m', $dockerfile);
        self::assertMatchesRegularExpression('/docker-php-ext-install[^\n]*\bpcntl\b/', $dockerfile);
        self::assertStringContainsString('extension_loaded("pcntl")', $dockerfile, 'the build checks the extension is loaded');
        self::assertMatchesRegularExpression('/^USER worker$/m', $dockerfile);
        self::assertMatchesRegularExpression('/^ENV WORKER_DATEN=\/daten \\\\\n\s*WORKER_ARBEIT=\/arbeit/m', $dockerfile);
        // The web app's code never comes along beyond app/src and the prompts.
        self::assertDoesNotMatchRegularExpression('/^COPY (public|migrations|app\/views|setup\.php)/m', $dockerfile);
    }

    public function testTheWorkerProgramRefusesToRunWithoutPcntl(): void
    {
        $quelle = self::datei('bin/worker.php');
        $pruefung = strpos($quelle, "!function_exists('pcntl_async_signals')");

        self::assertNotFalse($pruefung, 'bin/worker.php checks for pcntl');
        self::assertMatchesRegularExpression('/pcntl fehlt[^\n]*\n\s*exit\(1\);/', substr($quelle, $pruefung), 'and ends with an error');
    }

    public function testOnlyTagsAndPromotedReleasesPushAndOnlyWithTheWorkflowToken(): void
    {
        $workflow = self::datei('.github/workflows/worker-image.yml');

        self::assertStringContainsString('linux/amd64,linux/arm64', $workflow);
        self::assertStringContainsString("push: \${{ github.ref_type == 'tag' }}", $workflow);
        self::assertStringContainsString(':beta', $workflow);
        self::assertStringContainsString('${IMAGE}:stable', $workflow);
        self::assertMatchesRegularExpression('/release:\s*\n\s*types:\s*\[released\]/', $workflow);
        // No stored secret: the only one named is the workflow's own token.
        preg_match_all('/secrets\.([A-Za-z_]+)/', $workflow, $secrets);
        self::assertSame(['GITHUB_TOKEN'], array_values(array_unique($secrets[1])));
        // A tag is tested before it is pushed.
        self::assertLessThan(
            strpos($workflow, 'push: ${{'),
            strpos($workflow, 'tests/e2e/worker/run.sh'),
        );
    }

    public function testEveryPullRequestRunsTheEndToEndTest(): void
    {
        $ci = self::datei('.github/workflows/ci.yml');

        self::assertMatchesRegularExpression('/^\s*pull_request:\s*$/m', $ci);
        self::assertStringContainsString('run: tests/e2e/worker/run.sh', $ci);
    }
}
