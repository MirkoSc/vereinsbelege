<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Domain\SystemRole;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Repository\AuditLogRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\Testdatensatz;
use App\Tests\Support\ZuordnungFabrik;
use PHPUnit\Framework\Attributes\Group;

/**
 * Performance test (issue #82/M13-5): with several years of club data -
 * 3 000 receipts, 5 000 bookings (Testdatensatz) - the reports stay under
 * 3 s and the inbox under 1 s per request.
 *
 * Measured is one whole request through the production wiring: each
 * request loads app/src/bootstrap.php afresh (as PHP does per request on
 * the host) with a config pointing at the test database, and runs it
 * through the Kernel - route table, login guard, vault from the cookie,
 * services, SQL, decryption, view. "Kalt" is the first request after a
 * change (empty session cache, AuswertungsCache), "warm" the same request
 * again.
 *
 * Not part of the normal suite (phpunit.xml excludes the group): building
 * the data set takes a while. Run it with
 *
 *     vendor/bin/phpunit --group performance
 *
 * CI runs it as a job of its own and writes the table to the job summary;
 * PERF_REPORT_FILE names a file the table (Markdown) is appended to.
 */
#[Group('performance')]
final class LeistungsTest extends DatabaseTestCase
{
    /** Seconds - the targets of the issue. */
    private const float ZIEL_AUSWERTUNG = 3.0;

    private const float ZIEL_POSTEINGANG = 1.0;

    /** Repetitions per page and state; the median counts. */
    private const int WIEDERHOLUNGEN = 3;

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private string $configDatei = '';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo(), $crypto->tresorAnker())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $crypto);
        $this->userId = new UserRepository($this->pdo())->insert($crypto->encrypt('kasse@example.invalid'), random_bytes(32), $crypto->encrypt('Kassenwart'), 'hash', mfaRequired: false);
        $rollen = new RoleRepository($this->pdo());
        $rollen->assignToUser($this->userId, [$rollen->findSystem(SystemRole::Finanzen)?->id ?? self::fail('no role')]);

        $this->configDatei = (string) tempnam(sys_get_temp_dir(), 'vb-perf-config-');
        file_put_contents($this->configDatei, '<?php return ' . var_export(self::configData(), true) . ';');
        putenv('APP_CONFIG_FILE=' . $this->configDatei);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        putenv('APP_CONFIG_FILE');
        if ($this->configDatei !== '' && is_file($this->configDatei)) {
            unlink($this->configDatei);
        }
        parent::tearDown();
    }

    public function testReportsAndInboxStayWithinTheirTargetsOnSeveralYearsOfData(): void
    {
        $aktuell = (int) date('Y');
        $jahre = [$aktuell - 4, $aktuell - 3, $aktuell - 2, $aktuell - 1];

        $start = hrtime(true);
        $umfang = new Testdatensatz($this->pdo(), $this->tresor, ZuordnungFabrik::bauen($this->pdo(), $this->audit), $this->userId, $jahre)->erzeugen();
        $erzeugt = (hrtime(true) - $start) / 1e9;

        // Logged in only now: however long the generation took, the
        // session must not have run into its idle timeout.
        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];

        self::assertSame(Testdatensatz::BELEGE, $umfang['belege']);
        self::assertSame(Testdatensatz::BUCHUNGEN, $umfang['buchungen']);

        $jahr = (string) ($aktuell - 1);
        // The expense category the generator gives most receipts.
        $kategorie = $umfang['groessteKategorie'];
        $groesste = htmlspecialchars($kategorie['name']);
        self::assertNotSame('', $groesste);
        $seiten = [
            // The expected text is decrypted data where the page shows any
            // (account, supplier, submitter names): proof it read through
            // the vault.
            ['Dashboard', '/app', ['jahr' => $jahr], self::ZIEL_AUSWERTUNG, 'Girokonto'],
            ['Geldfluss (Buchungen)', '/app/auswertung/geldfluss', ['art' => 'jahr', 'jahr' => $jahr], self::ZIEL_AUSWERTUNG, $groesste],
            ['Geldfluss (Belege)', '/app/auswertung/geldfluss', ['art' => 'jahr', 'jahr' => $jahr, 'grundlage' => 'belege'], self::ZIEL_AUSWERTUNG, $groesste],
            ['Geldfluss 3 Jahre (frei)', '/app/auswertung/geldfluss', ['art' => 'frei', 'von' => ($aktuell - 3) . '-01-01', 'bis' => $jahr . '-12-31'], self::ZIEL_AUSWERTUNG, $groesste],
            ['Geldfluss Drilldown (größte Kategorie)', '/app/auswertung/geldfluss/posten', ['art' => 'jahr', 'jahr' => $jahr, 'nach' => 'kategorie', 'schluessel' => (string) $kategorie['id']], self::ZIEL_AUSWERTUNG, 'Testfirma'],
            ['Kassenprüfung', '/app/auswertung/kassenpruefung', ['art' => 'jahr', 'jahr' => $jahr], self::ZIEL_AUSWERTUNG, 'Girokonto'],
            ['Kassenprüfung CSV (Buchungen)', '/app/auswertung/kassenpruefung/buchungen.csv', ['art' => 'jahr', 'jahr' => $jahr], self::ZIEL_AUSWERTUNG, 'Testperson'],
            ['Posteingang (offen)', '/app/posteingang', [], self::ZIEL_POSTEINGANG, 'Testperson'],
            ['Posteingang (alle)', '/app/posteingang', ['ansicht' => 'alle'], self::ZIEL_POSTEINGANG, 'Testperson'],
        ];

        $ergebnisse = [];
        $verfehlt = [];
        foreach ($seiten as [$name, $pfad, $query, $ziel, $erwartet]) {
            $kalt = [];
            $warm = [];
            $speicher = 0;
            for ($i = 0; $i < self::WIEDERHOLUNGEN; ++$i) {
                unset($_SESSION['auswertung_cache']);
                foreach ([&$kalt, &$warm] as &$liste) {
                    [$dauer, $spitze] = $this->messen($pfad, $query, $erwartet);
                    $liste[] = $dauer;
                    $speicher = max($speicher, $spitze);
                }
                unset($liste);
            }
            $ergebnis = ['name' => $name, 'ziel' => $ziel, 'kalt' => self::median($kalt), 'warm' => self::median($warm), 'max' => max(...$kalt, ...$warm), 'speicher' => $speicher];
            $ergebnisse[] = $ergebnis;
            if ($ergebnis['kalt'] >= $ziel || $ergebnis['warm'] >= $ziel) {
                $verfehlt[] = sprintf('%s: kalt %.3f s, warm %.3f s (Ziel < %.1f s)', $name, $ergebnis['kalt'], $ergebnis['warm'], $ziel);
            }
        }

        $this->berichten($ergebnisse, $umfang, $erzeugt);
        self::assertSame([], $verfehlt, 'Ziel verfehlt');
    }

    /**
     * One request through a freshly loaded bootstrap: seconds and the peak
     * memory it added (bytes). The body is checked, so a quick error page
     * cannot pass for a fast report.
     *
     * @param array<string, string> $query
     *
     * @return array{float, int}
     */
    private function messen(string $pfad, array $query, string $erwartet): array
    {
        gc_collect_cycles();
        $grund = memory_get_usage();
        memory_reset_peak_usage();
        $start = hrtime(true);
        /** @var Kernel $kernel */
        $kernel = require dirname(__DIR__, 2) . '/app/src/bootstrap.php';
        $antwort = $kernel->handle(new Request(HttpMethod::Get, $pfad, query: $query, cookies: $this->tresorCookie()));
        $dauer = (hrtime(true) - $start) / 1e9;
        $spitze = memory_get_peak_usage() - $grund;

        // Every measured page answers in one piece (no StreamResponse).
        self::assertInstanceOf(Response::class, $antwort, $pfad);
        self::assertSame(200, $antwort->status, $pfad);
        self::assertStringContainsString($erwartet, $antwort->body, $pfad);
        self::assertStringNotContainsString('nur mit entsperrtem Tresor', $antwort->body, $pfad);

        return [$dauer, $spitze];
    }

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    /**
     * @param list<float> $werte
     */
    private static function median(array $werte): float
    {
        sort($werte);

        return $werte[intdiv(count($werte), 2)];
    }

    /**
     * @param list<array{name: string, ziel: float, kalt: float, warm: float, max: float, speicher: int}> $ergebnisse
     * @param array{belege: int, buchungen: int, zuordnungen: int, lieferanten: int, kostenstellen: int, jahre: list<int>, groessteKategorie: array{id: int, name: string}} $umfang
     */
    private function berichten(array $ergebnisse, array $umfang, float $erzeugt): void
    {
        $zeilen = [
            sprintf(
                '**Datensatz:** %d Belege, %d Buchungen, %d Zuordnungen, %d Lieferanten, %d Kostenstellen, Jahre %s (erzeugt in %.0f s)',
                $umfang['belege'],
                $umfang['buchungen'],
                $umfang['zuordnungen'],
                $umfang['lieferanten'],
                $umfang['kostenstellen'],
                implode('–', [$umfang['jahre'][0], $umfang['jahre'][count($umfang['jahre']) - 1]]),
                $erzeugt,
            ),
            sprintf('**Umgebung:** PHP %s, %s, %s', PHP_VERSION, php_uname('s') . ' ' . php_uname('m'), (string) $this->pdo()->query('SELECT VERSION()')->fetchColumn()),
            '',
            '| Seite | kalt (Median) | warm (Median) | langsamster Lauf | Speicher (Spitze) | Ziel |',
            '|---|---:|---:|---:|---:|---:|',
        ];
        foreach ($ergebnisse as $e) {
            $zeilen[] = sprintf('| %s | %.3f s | %.3f s | %.3f s | %.1f MiB | < %.1f s |', $e['name'], $e['kalt'], $e['warm'], $e['max'], $e['speicher'] / 1048576, $e['ziel']);
        }
        $text = implode("\n", $zeilen) . "\n";

        fwrite(STDERR, "\n" . $text);
        $datei = getenv('PERF_REPORT_FILE');
        if (is_string($datei) && $datei !== '') {
            file_put_contents($datei, $text, FILE_APPEND);
        }
    }
}
