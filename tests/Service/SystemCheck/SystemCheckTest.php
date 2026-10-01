<?php

declare(strict_types=1);

namespace App\Tests\Service\SystemCheck;

use App\Service\SystemCheck\CheckResult;
use App\Service\SystemCheck\CheckStatus;
use App\Service\SystemCheck\SystemCheck;
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

            ini_set('memory_limit', '200M');
            self::assertSame(CheckStatus::Warn, new SystemCheck()->memoryLimit()->status);

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
}
