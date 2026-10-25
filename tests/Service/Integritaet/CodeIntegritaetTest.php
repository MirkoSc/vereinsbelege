<?php

declare(strict_types=1);

namespace App\Tests\Service\Integritaet;

use App\Installer\ConfigWriter;
use App\Service\Integritaet\CodeIntegritaet;
use App\Service\Integritaet\IntegritaetPruefungNichtMoeglich;
use App\Service\Update\ReleaseSignatur;
use App\Service\Update\ReleaseSwitcher;
use PHPUnit\Framework\TestCase;

/**
 * The code integrity check as a step chain (issue #79/M13-2,
 * docs/spec/01-sicherheit.md section 8) on a small release tree in a
 * temporary directory - no database.
 */
final class CodeIntegritaetTest extends TestCase
{
    private string $basis;
    private string $release;
    private string $liste;
    private string $var;

    protected function setUp(): void
    {
        $this->basis = sys_get_temp_dir() . '/vb_integritaet_' . bin2hex(random_bytes(6));
        $this->release = $this->basis . '/current';
        $this->liste = $this->basis . '/shared/release_checksums.txt';
        $this->var = $this->basis . '/shared/var';
        mkdir($this->release . '/app/src', 0775, true);
        mkdir($this->release . '/vendor/lib', 0775, true);
        mkdir(dirname($this->liste), 0775, true);

        $this->datei('VERSION', "1.2.3\n");
        $this->datei('.htaccess', "Require all denied\n");
        $this->datei('app/src/A.php', "<?php // a\n");
        $this->datei('app/src/B.php', "<?php // b\n");
        $this->datei('vendor/lib/C.php', "<?php // c\n");
        $this->listeSchreiben('1.2.3');
    }

    protected function tearDown(): void
    {
        $laufer = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basis, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($laufer as $eintrag) {
            assert($eintrag instanceof \SplFileInfo);
            $eintrag->isDir() && !$eintrag->isLink() ? rmdir($eintrag->getPathname()) : unlink($eintrag->getPathname());
        }
        rmdir($this->basis);
    }

    public function testAnUntouchedReleaseHasNoDifference(): void
    {
        $stand = $this->durchlaufen($this->pruefung());

        self::assertSame(CodeIntegritaet::PHASE_FERTIG, $stand['phase']);
        self::assertTrue(CodeIntegritaet::unveraendert($stand));
        self::assertSame(['geaendert' => 0, 'fehlend' => 0, 'zusaetzlich' => 0, 'umgebung' => 0], $stand['anzahl']);
        self::assertSame(5, $stand['gesamt']);
        self::assertSame(5, $stand['position']);
        self::assertSame('1.2.3', $stand['version']);
        self::assertNotNull($stand['beendet']);
    }

    public function testChangedMissingAndAdditionalFilesAreListed(): void
    {
        $this->datei('app/src/A.php', "<?php eval(\$_GET['x']);\n");
        unlink($this->release . '/vendor/lib/C.php');
        $this->datei('app/src/shell.php', "<?php // dropped in\n");
        $this->datei('public/.hidden.php', "<?php\n");

        $stand = $this->durchlaufen($this->pruefung());

        self::assertFalse(CodeIntegritaet::unveraendert($stand));
        self::assertSame(['geaendert' => 1, 'fehlend' => 1, 'zusaetzlich' => 2, 'umgebung' => 0], $stand['anzahl']);
        self::assertSame(['app/src/A.php'], $stand['pfade']['geaendert']);
        self::assertSame(['vendor/lib/C.php'], $stand['pfade']['fehlend']);
        self::assertSame(['app/src/shell.php', 'public/.hidden.php'], $stand['pfade']['zusaetzlich'], 'hidden files too');
    }

    /**
     * A symlink is a finding of its own, wherever it points - the walk does
     * not follow it out of current/.
     */
    public function testASymlinkThatIsNotPartOfTheReleaseIsAdditional(): void
    {
        mkdir($this->basis . '/draussen');
        file_put_contents($this->basis . '/draussen/x.php', '<?php');
        symlink($this->basis . '/draussen', $this->release . '/app/link');

        $stand = $this->durchlaufen($this->pruefung());

        self::assertSame(['app/link'], $stand['pfade']['zusaetzlich']);
    }

    /**
     * CLAUDE.md section 1: no long request - each step hashes one portion,
     * the walk for additional files is a step of its own.
     */
    public function testTheCheckRunsInShortSteps(): void
    {
        $pruefung = $this->pruefung(portionDateien: 2);

        $stand = $pruefung->starten();
        self::assertSame(0, $stand['position']);
        self::assertFalse($stand['gerade_fertig']);

        $schritte = [];
        do {
            $stand = $pruefung->schritt();
            $schritte[] = [$stand['phase'], $stand['position'], $stand['gerade_fertig']];
        } while ($stand['phase'] !== CodeIntegritaet::PHASE_FERTIG);

        self::assertSame([
            [CodeIntegritaet::PHASE_DATEIEN, 2, false],
            [CodeIntegritaet::PHASE_DATEIEN, 4, false],
            [CodeIntegritaet::PHASE_ZUSAETZLICH, 5, false],
            [CodeIntegritaet::PHASE_UMGEBUNG, 5, false],
            [CodeIntegritaet::PHASE_FERTIG, 5, true],
        ], $schritte);

        $nochmal = $pruefung->schritt();
        self::assertFalse($nochmal['gerade_fertig'], 'only the finishing step says so - one audit row');
        self::assertSame($stand['anzahl'], $nochmal['anzahl']);
    }

    /**
     * The state survives the request: a new instance (the next request)
     * continues where the last one stopped.
     */
    public function testAnotherRequestContinuesTheChain(): void
    {
        $this->pruefung(portionDateien: 3)->starten();
        $this->pruefung(portionDateien: 3)->schritt();
        $this->datei('app/src/B.php', 'anders');

        $stand = $this->durchlaufenAb($this->pruefung(portionDateien: 3));

        self::assertSame(['app/src/B.php'], $stand['pfade']['geaendert'], 'B.php comes after the first portion');
    }

    public function testWithoutListThereIsNoCheck(): void
    {
        unlink($this->liste);
        $pruefung = $this->pruefung();

        self::assertStringContainsString('keine Prüfsummenliste', (string) $pruefung->hindernis());
        $this->expectException(IntegritaetPruefungNichtMoeglich::class);
        $pruefung->starten();
    }

    /**
     * A release from before this milestone: checksums.txt names the assets
     * only.
     */
    public function testAListWithoutFileChecksumsIsNoBasisForACheck(): void
    {
        file_put_contents($this->liste, str_repeat('a', 64) . "  vereinsbelege-v1.2.3.zip\n");

        self::assertStringContainsString('noch keine Prüfsummen je Datei', (string) $this->pruefung()->hindernis());
    }

    /**
     * The updater stores the list at the download step: after a rollback or
     * an update that stopped before the switch it belongs to another
     * version. The page warns, but the check stays possible.
     */
    public function testAListOfAnotherVersionWarnsButDoesNotBlock(): void
    {
        $this->listeSchreiben('1.3.0');
        $pruefung = $this->pruefung();

        self::assertNull($pruefung->hindernis());
        $warnung = (string) $pruefung->warnung();
        self::assertStringContainsString('Version 1.3.0', $warnung);
        self::assertStringContainsString('current/VERSION nennt 1.2.3', $warnung);
        self::assertSame(CodeIntegritaet::PHASE_FERTIG, $this->durchlaufen($pruefung)['phase']);
    }

    /**
     * A tampered VERSION file must not switch the check off - it is a
     * changed file like any other.
     */
    public function testATamperedVersionFileIsAFindingNotAnObstacle(): void
    {
        $this->datei('VERSION', "9.9.9\n");
        $this->datei('app/src/shell.php', '<?php');

        $stand = $this->durchlaufen($this->pruefung());

        self::assertSame(['VERSION'], $stand['pfade']['geaendert']);
        self::assertSame(['app/src/shell.php'], $stand['pfade']['zusaetzlich']);
    }

    public function testAnUntouchedReleaseHasNoWarning(): void
    {
        self::assertNull($this->pruefung()->warnung());
    }

    /**
     * A rollback between two steps swaps current/ while the list stays: the
     * result would mix two releases.
     */
    public function testAVersionChangeDuringTheCheckStopsIt(): void
    {
        $pruefung = $this->pruefung(portionDateien: 1);
        $pruefung->starten();
        $pruefung->schritt();
        $this->datei('VERSION', "1.1.0\n");

        $this->expectException(IntegritaetPruefungNichtMoeglich::class);
        $pruefung->schritt();
    }

    /**
     * Release files are never links - one in the place of a listed file is
     * reported, not followed (it may point outside current/).
     */
    public function testASymlinkInPlaceOfAListedFileIsChanged(): void
    {
        mkdir($this->basis . '/draussen');
        copy($this->release . '/app/src/A.php', $this->basis . '/draussen/A.php');
        unlink($this->release . '/app/src/A.php');
        symlink($this->basis . '/draussen/A.php', $this->release . '/app/src/A.php');

        $stand = $this->durchlaufen($this->pruefung());

        self::assertSame(['app/src/A.php'], $stand['pfade']['geaendert'], 'same content behind the link, still a finding');
        self::assertSame([], $stand['pfade']['zusaetzlich']);
    }

    /**
     * A file name that is not UTF-8 (an old FTP client) must not stop the
     * chain - the state is JSON.
     */
    public function testANonUtf8FileNameDoesNotStopTheCheck(): void
    {
        $this->datei("app/src/b\xE4d.php", '<?php');

        $stand = $this->durchlaufen($this->pruefung());

        self::assertSame(["app/src/b\u{FFFD}d.php"], $stand['pfade']['zusaetzlich']);
        self::assertSame(1, $this->pruefung()->stand()['anzahl']['zusaetzlich'] ?? null);
    }

    /**
     * The audit row is written under the lock before the result is stored:
     * if it fails, the check stays unfinished and the next step writes it.
     */
    public function testAFailedCompletionIsRetriedByTheNextStep(): void
    {
        $pruefung = $this->pruefung();
        $pruefung->starten();
        $pruefung->schritt();
        $pruefung->schritt();
        $aufrufe = 0;

        try {
            $pruefung->schritt(static function () use (&$aufrufe): void {
                $aufrufe++;
                throw new \RuntimeException('Datenbank weg');
            });
            self::fail('the failure reaches the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('Datenbank weg', $e->getMessage());
        }
        self::assertSame(CodeIntegritaet::PHASE_UMGEBUNG, $pruefung->stand()['phase'] ?? null, 'not stored as finished');

        $stand = $pruefung->schritt(static function (array $stand) use (&$aufrufe): void {
            $aufrufe++;
            self::assertSame(CodeIntegritaet::PHASE_FERTIG, $stand['phase']);
        });
        self::assertTrue($stand['gerade_fertig']);
        self::assertSame(2, $aufrufe);

        $pruefung->schritt(static function () use (&$aufrufe): void {
            $aufrufe++;
        });
        self::assertSame(2, $aufrufe, 'never again once finished');
    }

    public function testAListChangedDuringTheCheckStopsIt(): void
    {
        $pruefung = $this->pruefung(portionDateien: 1);
        $pruefung->starten();
        $pruefung->schritt();
        file_put_contents($this->liste, "\n", FILE_APPEND);

        $this->expectException(IntegritaetPruefungNichtMoeglich::class);
        $this->expectExceptionMessage('neu starten');
        $pruefung->schritt();
    }

    public function testAStepWithoutAStartedCheckFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->pruefung()->schritt();
    }

    /**
     * The counts stay complete, the stored paths are capped - a release
     * whose every file differs keeps a small state file.
     */
    public function testThePathListsAreCapped(): void
    {
        for ($i = 0; $i < CodeIntegritaet::MAX_PFADE + 5; $i++) {
            $this->datei(sprintf('public/neu%03d.txt', $i), 'x');
        }

        $stand = $this->durchlaufen($this->pruefung());

        self::assertSame(CodeIntegritaet::MAX_PFADE + 5, $stand['anzahl']['zusaetzlich']);
        self::assertCount(CodeIntegritaet::MAX_PFADE, $stand['pfade']['zusaetzlich']);
    }

    /**
     * The release holds no business data, and the state file holds paths
     * and counts only - never a file's content.
     */
    public function testTheStateHoldsNoFileContent(): void
    {
        $this->datei('app/src/A.php', 'GEHEIMER-INHALT');

        $this->durchlaufen($this->pruefung());

        $json = (string) file_get_contents($this->var . '/integritaet.json');
        self::assertStringNotContainsString('GEHEIMER-INHALT', $json);
        self::assertStringContainsString('app/src/A.php', $json);
    }

    // ---- issue #241: list in the release, signature, web/ and config.php ----

    /**
     * Since #241 the list lies in the release directory and moves with it;
     * it is preferred over shared/ and is no additional file itself.
     */
    public function testTheListInTheReleaseIsPreferredAndNoFinding(): void
    {
        rename($this->liste, $this->release . '/checksums.txt');
        file_put_contents($this->release . '/checksums.txt.sig', "irrelevant\n");
        // A stale list in shared/ (from the updater before #241) is ignored.
        file_put_contents($this->liste, str_repeat('3', 64) . "  vereinsbelege-v0.9.0.zip\n"
            . str_repeat('3', 64) . "  release/VERSION\n");

        $pruefung = $this->pruefung();
        self::assertNull($pruefung->warnung(), 'the list of the release itself, not the stale one');
        $stand = $this->durchlaufen($pruefung);

        self::assertTrue(CodeIntegritaet::unveraendert($stand), (string) json_encode($stand['pfade']));
        self::assertSame(5, $stand['gesamt']);
    }

    public function testAListElsewhereThanTheRootIsAdditional(): void
    {
        rename($this->liste, $this->release . '/checksums.txt');
        $this->datei('app/checksums.txt', 'x');

        $stand = $this->durchlaufen($this->pruefung());

        self::assertSame(['app/checksums.txt'], $stand['pfade']['zusaetzlich']);
    }

    public function testAValidSignatureLetsTheCheckRun(): void
    {
        $paar = ReleaseSignatur::erzeugeSchluesselpaar();
        $this->docroot();
        $this->signierteListe($paar['secret']);

        $pruefung = $this->pruefungMit(publicKey: $paar['public']);

        self::assertNull($pruefung->hindernis());
        self::assertNull($pruefung->echtheitsfehler());
        self::assertNull($pruefung->signaturHinweis(), 'protected - nothing to point out');
        self::assertTrue(CodeIntegritaet::unveraendert($this->durchlaufen($pruefung)));
    }

    /**
     * N-19: once a key is fixed, whoever rewrites the list to match a
     * changed file is caught - the list is no longer trusted at all.
     */
    public function testARewrittenSignedListStopsTheCheckWithAnAlarm(): void
    {
        $paar = ReleaseSignatur::erzeugeSchluesselpaar();
        $this->signierteListe($paar['secret']);
        $this->datei('app/src/A.php', '<?php // hintertuer');
        $liste = $this->release . '/checksums.txt';
        file_put_contents($liste, str_replace(
            hash('sha256', "<?php // a\n"),
            hash('sha256', '<?php // hintertuer'),
            (string) file_get_contents($liste),
        ));

        $pruefung = $this->pruefungMit(publicKey: $paar['public']);

        self::assertStringContainsString('Signatur der Prüfsummenliste ist ungültig', (string) $pruefung->echtheitsfehler());
        self::assertSame($pruefung->echtheitsfehler(), $pruefung->hindernis());
        $this->expectException(IntegritaetPruefungNichtMoeglich::class);
        $pruefung->starten();
    }

    public function testAMissingSignatureOrListIsAnAlarmOnceAKeyIsFixed(): void
    {
        $paar = ReleaseSignatur::erzeugeSchluesselpaar();
        $this->signierteListe($paar['secret']);
        unlink($this->release . '/checksums.txt.sig');
        $pruefung = $this->pruefungMit(publicKey: $paar['public']);
        self::assertStringContainsString('nicht signiert', (string) $pruefung->hindernis());

        // The shared/ fallback has no signature either.
        rename($this->release . '/checksums.txt', $this->liste);
        self::assertStringContainsString('nicht signiert', (string) $pruefung->hindernis());

        // Deleting the list must not quietly switch the check off.
        unlink($this->liste);
        self::assertStringContainsString('fehlt', (string) $pruefung->echtheitsfehler());
        self::assertSame($pruefung->echtheitsfehler(), $pruefung->hindernis());

        // The development environment (no current/VERSION) stays calm.
        unlink($this->release . '/VERSION');
        self::assertNull($pruefung->echtheitsfehler());
        self::assertStringContainsString('keine Prüfsummenliste', (string) $pruefung->hindernis());
    }

    public function testWithoutAKeyTheCheckRunsAndSaysTheListIsUnprotected(): void
    {
        $pruefung = $this->pruefungMit(publicKey: '');

        self::assertNull($pruefung->hindernis());
        self::assertNull($pruefung->echtheitsfehler());
        self::assertStringContainsString('nicht durch eine Signatur geschützt', (string) $pruefung->signaturHinweis());
    }

    public function testAnUntouchedDocrootAndConfigHaveNoFinding(): void
    {
        $this->docroot();

        $stand = $this->durchlaufen($this->pruefungMit());

        self::assertTrue(CodeIntegritaet::unveraendert($stand), (string) json_encode($stand['pfade']));
    }

    /**
     * N-03: the docroot runs with every request but lies outside current/.
     */
    public function testDocrootDifferencesAreFindings(): void
    {
        $web = $this->docroot();
        file_put_contents($web . '/index.php', ReleaseSwitcher::SHIM . "\n@eval(\$_POST['x']);\n");
        unlink($web . '/.htaccess');
        file_put_contents($web . '/.user.ini', "zend.exception_ignore_args = Off\n");
        file_put_contents($web . '/shell.php', '<?php');
        file_put_contents($web . '/.versteckt', 'x');
        mkdir($web . '/upload');

        $stand = $this->durchlaufen($this->pruefungMit());

        self::assertSame([
            'web/index.php – weicht vom Shim des Releases ab',
            'web/.htaccess – fehlt',
            'web/.user.ini – weicht von der Vorlage des Releases ab (von Hand geändert?)',
            'web/.versteckt – nicht Teil der Installation',
            'web/shell.php – nicht Teil der Installation',
            'web/upload/ – nicht Teil der Installation',
        ], $stand['pfade']['umgebung']);
        self::assertSame(6, $stand['anzahl']['umgebung']);
        self::assertSame(0, $stand['anzahl']['geaendert'], 'kept apart from the release files');
        self::assertFalse(CodeIntegritaet::unveraendert($stand));
    }

    public function testASymlinkedShimAndAMissingDocrootAreFindings(): void
    {
        $web = $this->docroot();
        unlink($web . '/index.php');
        symlink($this->release . '/VERSION', $web . '/index.php');

        $stand = $this->durchlaufen($this->pruefungMit());
        self::assertContains('web/index.php – Verweis (Symlink) statt Datei', $stand['pfade']['umgebung']);

        $ohne = $this->durchlaufen($this->pruefungMit(webDir: $this->basis . '/gibt-es-nicht'));
        self::assertSame(['web/ – Verzeichnis nicht gefunden'], $ohne['pfade']['umgebung']);
    }

    /**
     * config.php is individual - its content is never compared, only its
     * permissions and that it holds nothing but values.
     */
    public function testConfigPermissionsAndStructureAreChecked(): void
    {
        $this->docroot();
        $config = $this->basis . '/shared/config.php';

        chmod($config, 0640);
        self::assertTrue(CodeIntegritaet::unveraendert($this->durchlaufen($this->pruefungMit())), 'group read is fine');

        chmod($config, 0644);
        self::assertSame(
            ['shared/config.php – Rechte 0644, erwartet 0600 (nur der Besitzer darf lesen und schreiben)'],
            $this->durchlaufen($this->pruefungMit())['pfade']['umgebung'],
        );

        chmod($config, 0600);
        file_put_contents($config, "<?php\nif (isset(\$_GET['x'])) { system(\$_GET['x']); }\n"
            . (string) preg_replace('/^<\?php\s*/', '', (string) file_get_contents($config)));
        self::assertSame(
            ['shared/config.php – enthält mehr als die Einstellungen (Code oder Ausgabe außerhalb des Werte-Arrays)'],
            $this->durchlaufen($this->pruefungMit())['pfade']['umgebung'],
        );

        unlink($config);
        self::assertSame(['shared/config.php – fehlt'], $this->durchlaufen($this->pruefungMit())['pfade']['umgebung']);
    }

    /**
     * What ConfigWriter writes passes; everything that is more than values
     * does not.
     */
    public function testOnlyValuesPassTheStructureCheck(): void
    {
        $config = $this->basis . '/shared/config-test.php';
        ConfigWriter::write($config, ['host' => "db'host", 'port' => 3306, 'name' => 'n', 'user' => 'u', 'password' => "p\0w\"d"]);
        $echt = (string) file_get_contents($config);

        self::assertTrue(CodeIntegritaet::nurWerte($echt));
        self::assertTrue(CodeIntegritaet::nurWerte($echt . "?>\n"));
        self::assertTrue(CodeIntegritaet::nurWerte("<?php return ['a' => [1, -2.5, true, NULL, 'x' . \"\\0\"]];"));

        foreach ([
            'code before' => str_replace('return', 'system($_GET[1]); return', $echt),
            'code after' => $echt . "eval(\$x);\n",
            'interpolation' => "<?php return ['a' => \"{\$x}\"];",
            'backticks' => '<?php return [`id`];',
            'constant' => '<?php return [PHP_EOL];',
            'call' => "<?php return [getenv('X')];",
            'output before' => "\xEF\xBB\xBF" . $echt,
            'output after' => $echt . "?>\nhallo",
            'no return' => "<?php\n\$x = 1;",
            'include' => "<?php return include 'x.php';",
            // a string or array callable is made of literals and brackets
            'string callable' => "<?php return ['db' => 'system'('id')];",
            'concatenated callable' => "<?php return ['sys' . 'tem'('id')];",
            'array callable' => "<?php return [['A', 'b'][0]('x')];",
            'callable after array' => "<?php return array ('a' => 1)('x');",
            'index after string' => "<?php return ['abc'[0]];",
        ] as $fall => $php) {
            self::assertFalse(CodeIntegritaet::nurWerte($php), $fall);
        }
    }

    /**
     * Let's Encrypt validation puts short-lived tokens into
     * web/.well-known/acme-challenge/ - no finding; anything else in there
     * (a script, a .htaccess) is one.
     */
    public function testAcmeChallengeTokensAreNoFindingButScriptsThereAre(): void
    {
        $web = $this->docroot();
        mkdir($web . '/.well-known/acme-challenge', 0775, true);
        file_put_contents($web . '/.well-known/acme-challenge/Abc-123_xyz', 'token');

        self::assertTrue(CodeIntegritaet::unveraendert($this->durchlaufen($this->pruefungMit())));

        file_put_contents($web . '/.well-known/acme-challenge/x.php', '<?php');
        self::assertSame(
            ['web/.well-known/ – nicht Teil der Installation'],
            $this->durchlaufen($this->pruefungMit())['pfade']['umgebung'],
        );

        unlink($web . '/.well-known/acme-challenge/x.php');
        file_put_contents($web . '/.well-known/security.txt', 'x');
        self::assertSame(
            ['web/.well-known/ – nicht Teil der Installation'],
            $this->durchlaufen($this->pruefungMit())['pfade']['umgebung'],
        );
    }

    /**
     * A check started by the release before #241 knows no `umgebung` kind;
     * continued after the update it still finishes.
     */
    public function testAStateFromBeforeTheUpdateStillFinishes(): void
    {
        $this->docroot();
        $pruefung = $this->pruefungMit();
        $pruefung->starten();
        $pruefung->schritt();
        $stand = (array) json_decode((string) file_get_contents($this->var . '/integritaet.json'), true);
        unset($stand['anzahl']['umgebung'], $stand['pfade']['umgebung']);
        file_put_contents($this->var . '/integritaet.json', json_encode($stand, JSON_THROW_ON_ERROR));

        $ende = $this->durchlaufenAb($pruefung);

        self::assertSame(0, $ende['anzahl']['umgebung']);
        self::assertTrue(CodeIntegritaet::unveraendert($ende));
    }

    private function pruefungMit(?string $webDir = null, string $publicKey = ''): CodeIntegritaet
    {
        return new CodeIntegritaet(
            $this->release,
            $this->liste,
            $this->var,
            webDir: $webDir ?? $this->basis . '/web',
            configDatei: $this->basis . '/shared/config.php',
            publicKey: $publicKey,
        );
    }

    /**
     * web/ as setup.php leaves it, the templates in the release, and a
     * config.php as the installer writes it. Template files are part of the
     * release, so the list is written again.
     */
    private function docroot(): string
    {
        $web = $this->basis . '/web';
        mkdir($web);
        $this->datei('docker/web/index.php', ReleaseSwitcher::SHIM);
        $this->datei('docker/web/.htaccess', "Header set X-Test 1\n");
        $this->datei('docker/web/.user.ini', "zend.exception_ignore_args = On\n");
        file_put_contents($web . '/index.php', ReleaseSwitcher::SHIM);
        copy($this->release . '/docker/web/.htaccess', $web . '/.htaccess');
        copy($this->release . '/docker/web/.user.ini', $web . '/.user.ini');
        ConfigWriter::write(
            $this->basis . '/shared/config.php',
            ['host' => 'db', 'port' => 3306, 'name' => 'n', 'user' => 'u', 'password' => 'p'],
        );
        chmod($this->basis . '/shared/config.php', 0600);

        $zeilen = [str_repeat('2', 64) . '  vereinsbelege-v1.2.3.zip'];
        foreach ([
            '.htaccess', 'VERSION', 'app/src/A.php', 'app/src/B.php', 'vendor/lib/C.php',
            'docker/web/index.php', 'docker/web/.htaccess', 'docker/web/.user.ini',
        ] as $pfad) {
            $zeilen[] = hash_file('sha256', $this->release . '/' . $pfad) . '  release/' . $pfad;
        }
        file_put_contents($this->liste, implode("\n", $zeilen) . "\n");

        return $web;
    }

    /**
     * The list moved into the release with its signature, as setup.php and
     * the updater leave it since #241.
     */
    private function signierteListe(string $secret): void
    {
        rename($this->liste, $this->release . '/checksums.txt');
        file_put_contents(
            $this->release . '/checksums.txt.sig',
            ReleaseSignatur::signiere((string) file_get_contents($this->release . '/checksums.txt'), $secret) . "\n",
        );
    }

    private function pruefung(int $portionDateien = CodeIntegritaet::PORTION_DATEIEN): CodeIntegritaet
    {
        return new CodeIntegritaet($this->release, $this->liste, $this->var, portionDateien: $portionDateien);
    }

    /**
     * @return array<string, mixed>
     */
    private function durchlaufen(CodeIntegritaet $pruefung): array
    {
        $pruefung->starten();

        return $this->durchlaufenAb($pruefung);
    }

    /**
     * @return array<string, mixed>
     */
    private function durchlaufenAb(CodeIntegritaet $pruefung): array
    {
        $schritte = 0;
        do {
            $stand = $pruefung->schritt();
            self::assertLessThan(100, ++$schritte);
        } while ($stand['phase'] !== CodeIntegritaet::PHASE_FERTIG);

        return $stand;
    }

    private function datei(string $pfad, string $inhalt): void
    {
        $voll = $this->release . '/' . $pfad;
        if (!is_dir(dirname($voll))) {
            mkdir(dirname($voll), 0775, true);
        }
        file_put_contents($voll, $inhalt);
    }

    /**
     * The list as the release workflow writes it: assets first, then every
     * file of the release tree as it is now.
     */
    private function listeSchreiben(string $version): void
    {
        $zeilen = [
            str_repeat('1', 64) . '  setup.php',
            str_repeat('2', 64) . '  vereinsbelege-v' . $version . '.zip',
        ];
        foreach (['.htaccess', 'VERSION', 'app/src/A.php', 'app/src/B.php', 'vendor/lib/C.php'] as $pfad) {
            $zeilen[] = hash_file('sha256', $this->release . '/' . $pfad) . '  release/' . $pfad;
        }
        file_put_contents($this->liste, implode("\n", $zeilen) . "\n");
    }
}
