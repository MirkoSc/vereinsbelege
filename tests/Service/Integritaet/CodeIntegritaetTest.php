<?php

declare(strict_types=1);

namespace App\Tests\Service\Integritaet;

use App\Service\Integritaet\CodeIntegritaet;
use App\Service\Integritaet\IntegritaetPruefungNichtMoeglich;
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
        self::assertSame(['geaendert' => 0, 'fehlend' => 0, 'zusaetzlich' => 0], $stand['anzahl']);
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
        self::assertSame(['geaendert' => 1, 'fehlend' => 1, 'zusaetzlich' => 2], $stand['anzahl']);
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
        self::assertSame(CodeIntegritaet::PHASE_ZUSAETZLICH, $pruefung->stand()['phase'] ?? null, 'not stored as finished');

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
