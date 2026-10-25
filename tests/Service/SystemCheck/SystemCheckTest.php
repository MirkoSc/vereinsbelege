<?php

declare(strict_types=1);

namespace App\Tests\Service\SystemCheck;

use App\Service\Submission\UploadBudgetStand;
use App\Service\SystemCheck\CheckResult;
use App\Service\SystemCheck\CheckStatus;
use App\Service\SystemCheck\SystemCheck;
use App\Service\Update\DenyHtaccess;
use PHPUnit\Framework\TestCase;

final class SystemCheckTest extends TestCase
{
    public function testExceptionIgnoreArgsIsOkWhenTheSettingIsOn(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '1');

        try {
            $result = new SystemCheck()->exceptionIgnoreArgs();

            self::assertSame(CheckStatus::Ok, $result->status);
            self::assertSame('On', $result->actual);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /**
     * A failure, not a warning: with the setting off every trace can carry a
     * password, a vault key or decrypted receipt data (issue #97).
     */
    public function testExceptionIgnoreArgsFailsWhenTheSettingIsOff(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $result = new SystemCheck()->exceptionIgnoreArgs();

            self::assertSame(CheckStatus::Fail, $result->status);
            self::assertSame('Off', $result->actual);
            self::assertStringContainsString('Bootstrap', $result->detail);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /**
     * Without a PDO and without a var directory those two probes are left
     * out - the rest of the page has to work without them.
     */
    public function testWithoutADatabaseAndVarDirectoryTheOthersStillRun(): void
    {
        $keys = $this->keys(new SystemCheck()->all());

        self::assertSame([
            'zend.exception_ignore_args',
            'max_execution_time',
            'memory_limit',
            'upload_max_filesize',
            'post_max_size',
            'ext-sodium',
            'ext-gd',
            'ext-zip',
            'ext-curl',
            'password_argon2id',
        ], $keys);
    }

    /**
     * Issue #107: every M0 probe the page promises is there, in a stable
     * order, once - with a var directory the write check joins.
     */
    public function testWithAVarDirectoryTheWriteCheckJoins(): void
    {
        $dir = $this->tempDir();

        $keys = $this->keys(new SystemCheck(null, $dir)->all());

        self::assertSame('var_writable', end($keys));
        self::assertSame(count($keys), count(array_unique($keys)));
    }

    public function testTheWriteCheckPassesAndLeavesNothingBehind(): void
    {
        $dir = $this->tempDir();

        $result = new SystemCheck()->varWritable($dir);

        self::assertSame(CheckStatus::Ok, $result->status);
        self::assertSame('beschreibbar', $result->actual);
        self::assertSame([], $this->contents($dir), 'the probe file is removed again');
    }

    public function testTheWriteCheckFailsForAMissingDirectory(): void
    {
        $result = new SystemCheck()->varWritable($this->tempDir() . '/gibt-es-nicht');

        self::assertSame(CheckStatus::Fail, $result->status);
        self::assertSame('fehlt', $result->actual);
    }

    public function testTheWriteCheckFailsWhereNothingCanBeWritten(): void
    {
        $dir = $this->tempDir();
        chmod($dir, 0555);

        try {
            if (is_writable($dir)) {
                self::markTestSkipped('Running as a user for whom read-only directories stay writable (root).');
            }

            $result = new SystemCheck()->varWritable($dir);

            self::assertSame(CheckStatus::Fail, $result->status);
            self::assertSame('nicht beschreibbar', $result->actual);
        } finally {
            chmod($dir, 0755);
        }
    }

    public function testRequiredExtensionsAreRecognisedAndAMissingOneFails(): void
    {
        $check = new SystemCheck();

        // The verdict follows what this very PHP has loaded - the test must
        // not depend on the build it happens to run on.
        foreach (['sodium', 'gd', 'zip', 'curl'] as $name) {
            $ergebnis = $check->extension($name);

            self::assertSame(extension_loaded($name) ? CheckStatus::Ok : CheckStatus::Fail, $ergebnis->status, $name);
            self::assertSame(extension_loaded($name) ? 'vorhanden' : 'fehlt', $ergebnis->actual, $name);
        }

        $fehlt = $check->extension('gibt_es_nicht');
        self::assertSame(CheckStatus::Fail, $fehlt->status);
        self::assertSame('fehlt', $fehlt->actual);
        self::assertSame('ext-gibt_es_nicht', $fehlt->key);
    }

    /**
     * Issue #242: the public upload's daily budget and free-space threshold
     * - open is ok, a full day or a full disk is a warning, sizes only.
     */
    public function testTheUploadBudgetWarnsWhenTheDayOrTheDiskIsFull(): void
    {
        $mib = 1024 * 1024;
        $check = new SystemCheck();

        $offen = $check->uploadBudget(new UploadBudgetStand(10 * $mib, 500 * $mib, 10 * $mib, 5000 * $mib, 1024 * $mib));
        self::assertSame(CheckStatus::Ok, $offen->status);
        self::assertSame('upload_budget', $offen->key);
        self::assertSame('10 MiB heute, 4,9 GiB frei', $offen->actual);

        $voll = $check->uploadBudget(new UploadBudgetStand(495 * $mib, 500 * $mib, 10 * $mib, null, 1024 * $mib));
        self::assertSame(CheckStatus::Warn, $voll->status);
        self::assertStringContainsString('Tagesbudget', $voll->detail);
        self::assertStringContainsString('nicht ermittelbar', $voll->actual);

        $knapp = $check->uploadBudget(new UploadBudgetStand(0, 500 * $mib, 10 * $mib, 100 * $mib, 1024 * $mib));
        self::assertSame(CheckStatus::Warn, $knapp->status);
        self::assertStringContainsString('pausiert', $knapp->detail);

        $keys = array_map(
            static fn($r): string => $r->key,
            new SystemCheck(uploadBudget: static fn(): UploadBudgetStand => new UploadBudgetStand(0, 1, 1, null, 1))->all(),
        );
        self::assertSame('upload_budget', end($keys));

        $kaputt = new SystemCheck(uploadBudget: static fn(): never => throw new \PDOException('no table'))->all();
        self::assertSame(CheckStatus::Warn, end($kaputt)->status, 'an unreadable budget does not take the page down');
    }

    public function testArgon2idFollowsWhatPasswordHashCanDo(): void
    {
        $result = new SystemCheck()->argon2id();
        $kann = defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true);

        self::assertSame($kann ? CheckStatus::Ok : CheckStatus::Fail, $result->status);
        self::assertSame($kann ? 'verfügbar' : 'fehlt', $result->actual);
        self::assertSame('password_argon2id', $result->key);
    }

    /**
     * The thresholds of tools/hosting-check.php: at or above ok is ok, down
     * to warn is a warning, below fails; unlimited passes, unreadable warns.
     */
    public function testMinimumValuesAreRated(): void
    {
        $mib = 1024 * 1024;

        self::assertSame(CheckStatus::Ok, SystemCheck::rateMin(256 * $mib, 256 * $mib, 128 * $mib));
        self::assertSame(CheckStatus::Warn, SystemCheck::rateMin(255 * $mib, 256 * $mib, 128 * $mib));
        self::assertSame(CheckStatus::Warn, SystemCheck::rateMin(128 * $mib, 256 * $mib, 128 * $mib));
        self::assertSame(CheckStatus::Fail, SystemCheck::rateMin(127 * $mib, 256 * $mib, 128 * $mib));
        self::assertSame(CheckStatus::Ok, SystemCheck::rateMin(-1, 256 * $mib, 128 * $mib), 'unlimited');
        self::assertSame(CheckStatus::Warn, SystemCheck::rateMin(null, 256 * $mib, 128 * $mib), 'unreadable');
    }

    public function testMemoryLimitFollowsTheEffectiveSetting(): void
    {
        $previous = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '512M');
            self::assertSame(CheckStatus::Ok, new SystemCheck()->memoryLimit()->status);
            self::assertSame('512 MiB', new SystemCheck()->memoryLimit()->actual);

            // Another value is followed too. Not lowered below the 256 MiB
            // "ok" mark: that fails to set once the whole suite uses more
            // memory than that - the "warn" rating is covered by rateMin().
            ini_set('memory_limit', '768M');
            self::assertSame('768M', ini_get('memory_limit'));
            self::assertSame(CheckStatus::Ok, new SystemCheck()->memoryLimit()->status);
            self::assertSame('768 MiB', new SystemCheck()->memoryLimit()->actual);

            ini_set('memory_limit', '-1');
            self::assertSame(CheckStatus::Ok, new SystemCheck()->memoryLimit()->status);
            self::assertSame('unbegrenzt', new SystemCheck()->memoryLimit()->actual);
        } finally {
            ini_set('memory_limit', (string) $previous);
        }
    }

    public function testMaxExecutionTimeFollowsTheEffectiveSetting(): void
    {
        $previous = ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '30');
            self::assertSame(CheckStatus::Ok, new SystemCheck()->maxExecutionTime()->status);

            ini_set('max_execution_time', '25');
            self::assertSame(CheckStatus::Warn, new SystemCheck()->maxExecutionTime()->status);

            ini_set('max_execution_time', '10');
            self::assertSame(CheckStatus::Fail, new SystemCheck()->maxExecutionTime()->status);

            ini_set('max_execution_time', '0');
            self::assertSame('unbegrenzt', new SystemCheck()->maxExecutionTime()->actual);
            self::assertSame(CheckStatus::Ok, new SystemCheck()->maxExecutionTime()->status);
        } finally {
            ini_set('max_execution_time', (string) $previous);
        }
    }

    /**
     * Both limits are php.ini-only on most hosts, so the test reads whatever
     * is set and checks the rating against it instead of forcing a value.
     */
    public function testUploadLimitsAreRatedAgainstOneChunk(): void
    {
        foreach ([new SystemCheck()->uploadMaxFilesize(), new SystemCheck()->postMaxSize()] as $result) {
            $bytes = SystemCheck::parseBytes((string) ini_get($result->key));

            self::assertSame(
                $bytes === null || ($bytes >= 0 && $bytes < SystemCheck::UPLOAD_MIN) ? ($bytes === null ? CheckStatus::Warn : CheckStatus::Fail) : CheckStatus::Ok,
                $result->status,
                $result->key,
            );
            self::assertSame('≥ 2 MiB', $result->expected);
        }
    }

    public function testBytesAreParsedLikeThePhpIniDoes(): void
    {
        self::assertSame(1024, SystemCheck::parseBytes('1K'));
        self::assertSame(128 * 1024 * 1024, SystemCheck::parseBytes('128M'));
        self::assertSame(2 * 1024 ** 3, SystemCheck::parseBytes('2G'));
        self::assertSame(2048, SystemCheck::parseBytes('2048'));
        self::assertSame(-1, SystemCheck::parseBytes('-1'));
        self::assertNull(SystemCheck::parseBytes(''));
        self::assertNull(SystemCheck::parseBytes('viel'));
    }

    public function testBytesAreFormattedForPeople(): void
    {
        self::assertSame('256 MiB', SystemCheck::formatBytes(256 * 1024 * 1024));
        self::assertSame('1,5 KiB', SystemCheck::formatBytes(1536));
        self::assertSame('unbegrenzt', SystemCheck::formatBytes(-1));
        self::assertSame('unbekannt', SystemCheck::formatBytes(null));
    }

    public function testWorstStatusWins(): void
    {
        self::assertSame(CheckStatus::Ok, CheckStatus::worst(CheckStatus::Ok, CheckStatus::Ok));
        self::assertSame(CheckStatus::Warn, CheckStatus::worst(CheckStatus::Ok, CheckStatus::Warn));
        self::assertSame(CheckStatus::Fail, CheckStatus::worst(CheckStatus::Warn, CheckStatus::Fail));
        self::assertSame(CheckStatus::Ok, CheckStatus::worst());
    }

    /**
     * The check result is rendered in the admin area and may end up in a
     * support mail, so it must stay free of anything but settings.
     */
    public function testResultsCarryNoBusinessData(): void
    {
        $result = new SystemCheck()->exceptionIgnoreArgs();

        self::assertSame('zend.exception_ignore_args', $result->key);
        self::assertArrayHasKey('status', $result->toArray());
        self::assertSame($result->status->value, $result->toArray()['status']);
    }

    public function testTheOverallStatusIsTheWorstSingleStatus(): void
    {
        $ok = new CheckResult('a', 'A', 'x', 'x', CheckStatus::Ok);
        $warn = new CheckResult('b', 'B', 'x', 'y', CheckStatus::Warn);
        $fail = new CheckResult('c', 'C', 'x', 'z', CheckStatus::Fail);

        self::assertSame(CheckStatus::Ok, SystemCheck::worstOf([$ok]));
        self::assertSame(CheckStatus::Warn, SystemCheck::worstOf([$ok, $warn, $ok]));
        self::assertSame(CheckStatus::Fail, SystemCheck::worstOf([$warn, $fail, $ok]));
    }

    public function testTheJsonCarriesTheOverallStatusAndEveryCheck(): void
    {
        $results = new SystemCheck(null, $this->tempDir())->all();

        $json = json_decode(SystemCheck::toJson($results), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(SystemCheck::worstOf($results)->value, $json['status']);
        self::assertSame(PHP_VERSION, $json['php']);
        self::assertSame($this->keys($results), array_column($json['checks'], 'key'));
        foreach ($json['checks'] as $check) {
            self::assertSame(['key', 'label', 'expected', 'actual', 'status', 'detail'], array_keys($check));
        }
    }

    /**
     * As in tools/hosting-check.php (hc_redact): nothing secret reaches the
     * output. Here that holds by construction - a CheckResult has six string
     * fields and the JSON is built from them alone - and by one detail: the
     * directory that was probed is a server path, so it stays out of the
     * result. The planted values below are what a config.php would hold.
     */
    public function testNoSecretAndNoServerPathReachesTheOutput(): void
    {
        $geheim = ['db-pass-1f3a9c', 'cron-token-77be21', 'server-key-c0ffee'];
        $dir = $this->tempDir() . '/' . $geheim[0];
        mkdir($dir);
        $setzen = static function (array $werte): void {
            foreach ($werte as $name => $wert) {
                putenv($name . '=' . $wert);
            }
        };
        $setzen(['DB_PASS' => $geheim[0], 'CRON_TOKEN' => $geheim[1], 'SERVER_KEY' => $geheim[2]]);

        try {
            $results = new SystemCheck(null, $dir)->all();
            $json = SystemCheck::toJson($results);
            $text = $json . print_r(array_map(static fn(CheckResult $r): array => $r->toArray(), $results), true);

            foreach ($geheim as $wert) {
                self::assertStringNotContainsString($wert, $text);
            }
            self::assertStringNotContainsString($dir, $text, 'the probed path is a server detail');
        } finally {
            foreach (['DB_PASS', 'CRON_TOKEN', 'SERVER_KEY'] as $name) {
                putenv($name);
            }
        }
    }

    // ---- shared/config.php over the own domain (issue #101) ----

    /**
     * @param list<string> $gefragt receives every URL the probe was asked for
     *
     * @return \Closure(string): ?int
     */
    private static function probe(?int $status, array &$gefragt = []): \Closure
    {
        return static function (string $url) use ($status, &$gefragt): ?int {
            $gefragt[] = $url;

            return $status;
        };
    }

    public function testAServedConfigIsAFailure(): void
    {
        $gefragt = [];
        $result = new SystemCheck(null, null, self::probe(200, $gefragt))->configExposure('https://belege.example');

        self::assertSame(['https://belege.example/shared/config.php'], $gefragt);
        self::assertSame('config_exposure', $result->key);
        self::assertSame(CheckStatus::Fail, $result->status);
        self::assertSame('abrufbar (HTTP 200)', $result->actual);
    }

    public function testDeniedOrMissingIsFine(): void
    {
        foreach ([401, 403, 404, 410] as $status) {
            $result = new SystemCheck(null, null, self::probe($status))->configExposure('https://belege.example/');

            self::assertSame(CheckStatus::Ok, $result->status, (string) $status);
            self::assertSame('gesperrt (HTTP ' . $status . ')', $result->actual);
        }
    }

    /**
     * A redirect that was not resolved, a server error or no answer at all
     * proves neither - the
     * check must not claim safety it could not establish.
     */
    public function testAnythingElseIsOnlyAWarning(): void
    {
        foreach ([301, 302, 500, 503] as $status) {
            self::assertSame(
                CheckStatus::Warn,
                new SystemCheck(null, null, self::probe($status))->configExposure('https://belege.example')->status,
                (string) $status,
            );
        }

        $result = new SystemCheck(null, null, self::probe(null))->configExposure('https://belege.example');
        self::assertSame(CheckStatus::Warn, $result->status);
        self::assertSame('keine Antwort', $result->actual);
    }

    /**
     * The probe needs both: something that can fetch, and the domain to
     * fetch from. Without either the line is left out, like the database
     * check without a PDO.
     */
    public function testTheExposureCheckJoinsOnlyWithAProbeAndAnOrigin(): void
    {
        $gefragt = [];
        $probe = self::probe(404, $gefragt);

        self::assertNotContains('config_exposure', $this->keys(new SystemCheck(null, null, $probe)->all()));
        self::assertNotContains('config_exposure', $this->keys(new SystemCheck()->all('https://belege.example')));
        self::assertSame([], $gefragt);

        $keys = $this->keys(new SystemCheck(null, null, $probe)->all('https://belege.example'));
        self::assertSame('config_exposure', end($keys));
        self::assertCount(1, $gefragt);
    }

    /**
     * The file-system side of issue #101: every existing directory next to
     * the docroot must carry a locking .htaccess. Read only - the page never
     * writes one.
     */
    public function testDirectoryLocksAreReadFromTheFileSystem(): void
    {
        $base = $this->tempDir();
        mkdir($base . '/shared');
        mkdir($base . '/current');
        $this->aufraeumen[] = $base . '/shared';
        $this->aufraeumen[] = $base . '/current';

        $offen = new SystemCheck()->directoryLocks($base);
        self::assertSame(CheckStatus::Warn, $offen->status);
        self::assertSame('fehlt in shared/, current/', $offen->actual);
        self::assertFileDoesNotExist($base . '/shared/.htaccess', 'the check only looks');
        self::assertStringNotContainsString($base, implode(' ', $offen->toArray()));

        file_put_contents($base . '/shared/.htaccess', DenyHtaccess::CONTENT);
        file_put_contents($base . '/current/.htaccess', "<Files x>\nRequire all denied\n</Files>\n");
        self::assertSame('fehlt in current/', new SystemCheck()->directoryLocks($base)->actual);

        file_put_contents($base . '/current/.htaccess', DenyHtaccess::CONTENT);
        $zu = new SystemCheck()->directoryLocks($base);
        self::assertSame(CheckStatus::Ok, $zu->status);
        self::assertSame('gesperrt', $zu->actual);
    }

    public function testTheLockCheckJoinsWithASharedDirectory(): void
    {
        $base = $this->tempDir();
        mkdir($base . '/shared');
        $this->aufraeumen[] = $base . '/shared';

        $keys = $this->keys(new SystemCheck(null, null, null, $base . '/shared')->all());

        self::assertContains('directory_locks', $keys);
    }

    /**
     * @param list<CheckResult> $results
     *
     * @return list<string>
     */
    private function keys(array $results): array
    {
        return array_map(static fn(CheckResult $r): string => $r->key, $results);
    }

    /**
     * @return list<string> everything in the directory except . and ..
     */
    private function contents(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/systemcheck-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->aufraeumen[] = $dir;

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->aufraeumen as $dir) {
            if (is_dir($dir)) {
                chmod($dir, 0755);
                foreach ($this->contents($dir) as $datei) {
                    $pfad = $dir . '/' . $datei;
                    is_dir($pfad) ? @rmdir($pfad) : @unlink($pfad);
                }
                @rmdir($dir);
            }
        }
        $this->aufraeumen = [];
    }

    /** @var list<string> */
    private array $aufraeumen = [];

    public function testTheVaultAnchorCheckIsOkWhenEveryGenerationVerifies(): void
    {
        $ok = new SystemCheck()->tresorAnker([1 => true, 2 => true]);
        self::assertSame('vault_anchor', $ok->key);
        self::assertSame(CheckStatus::Ok, $ok->status);

        $leer = new SystemCheck()->tresorAnker([]);
        self::assertSame(CheckStatus::Ok, $leer->status);
        self::assertSame('noch kein Tresor', $leer->actual);
    }

    public function testTheVaultAnchorCheckFailsAndNamesOnlyTheGeneration(): void
    {
        $ergebnis = new SystemCheck()->tresorAnker([1 => true, 2 => false]);

        self::assertSame(CheckStatus::Fail, $ergebnis->status);
        self::assertSame('nicht beglaubigt (Generation 2)', $ergebnis->actual);
        self::assertStringContainsString('Wiederherstellungsschlüssel', $ergebnis->detail);
    }

    public function testTheVaultAnchorCheckJoinsOnlyWithItsSource(): void
    {
        $ohne = array_map(static fn(CheckResult $r): string => $r->key, new SystemCheck()->all());
        self::assertNotContains('vault_anchor', $ohne);

        $mit = new SystemCheck(tresorAnker: static fn(): array => [1 => false])->all();
        $treffer = array_values(array_filter($mit, static fn(CheckResult $r): bool => $r->key === 'vault_anchor'));
        self::assertCount(1, $treffer);
        self::assertSame(CheckStatus::Fail, $treffer[0]->status);
    }
}
