<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\ArtifactKind;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\JobExecutor;
use App\Domain\Zugriffsbereich;
use App\Repository\BlobRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\VaultRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\Texterkennung;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Suche\ERechnungQuelle;
use App\Service\Suche\KiQuelle;
use App\Service\Suche\SitzungsSuchindex;
use App\Service\Suche\Suchanfrage;
use App\Service\Suche\SuchindexSpeicher;
use App\Service\Suche\Suchtreffer;
use App\Service\Suche\TextlayerQuelle;
use App\Service\Suche\Volltextsuche;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\KiAuslesungFabrik;

/**
 * The session index of the full-text search (issue #94, docs/spec/
 * 03-erfassung-und-ki.md section 10, "Pflicht-Tests"): built in steps from
 * every text source, encrypted at rest under a key only the unlocked vault
 * opens, kept within the viewer's scope, renewed for new text and thrown
 * away whenever it cannot be trusted.
 */
final class VolltextsucheTest extends DatabaseTestCase
{
    private const int BENUTZER = 7;

    private Vault $tresor;
    private string $verzeichnis;
    private string $blobDir;
    private int $jobId = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->verzeichnis = sys_get_temp_dir() . '/vb_volltext_' . bin2hex(random_bytes(6));
        $this->blobDir = sys_get_temp_dir() . '/vb_volltext_blobs_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        self::entferne($this->verzeichnis);
        self::entferne($this->blobDir);
        parent::tearDown();
    }

    public function testEverySourceIsIndexedAndFound(): void
    {
        $layer = $this->dokument();
        $this->textlayer($layer, ['Rechnung Nr. 4711', 'Sporthaus Müller GmbH – Trikots E-Jugend']);
        $eRechnung = $this->dokument();
        $this->eRechnung($eRechnung, ['supplier' => ['name' => 'Getränke Becker'], 'invoice_number' => 'XR-77']);
        $ki = $this->dokument();
        $this->kiExtraktion($ki, ['purpose_short' => 'Flutlicht Reparatur', 'total_gross' => '1234.50']);
        $leer = $this->dokument();

        $suche = $this->suche();
        $bereich = Zugriffsbereich::unbeschraenkt();
        $stand = $suche->stand(self::BENUTZER, $bereich, $this->tresor);
        self::assertFalse($stand->fertig);
        self::assertSame(3, $stand->offen, 'documents without recognised text are not counted');
        self::assertNull($suche->suche(self::BENUTZER, $bereich, $this->tresor, Suchanfrage::aus('trikots')), 'no index yet');

        $stand = $suche->schritt(self::BENUTZER, $bereich, $this->tresor);
        self::assertTrue($stand->aktuell());

        self::assertSame([$layer], $this->gefunden($suche, 'MÜLLER trikots'));
        self::assertSame([$layer], $this->gefunden($suche, '"rechnung nr. 4711"'));
        self::assertSame([$eRechnung], $this->gefunden($suche, 'getränke xr-77'));
        self::assertSame([$ki], $this->gefunden($suche, '"flutlicht reparatur" 1234,50'));
        self::assertSame([], $this->gefunden($suche, 'müller becker'), 'every term must occur in the same document');
        self::assertNotContains($leer, $this->gefunden($suche, 're'));
    }

    public function testALargeArchiveIsBuiltInShortSteps(): void
    {
        $ids = [];
        for ($i = 0; $i < Volltextsuche::MAX_TREFFER + 5; $i++) {
            $ids[] = $id = $this->dokument();
            $this->textlayer($id, ['Beleg Vereinsheim Nummer ' . $i]);
        }
        $suche = $this->suche();
        $bereich = Zugriffsbereich::unbeschraenkt();

        $stand = $suche->schritt(self::BENUTZER, $bereich, $this->tresor);
        self::assertFalse($stand->fertig);
        self::assertSame(Volltextsuche::MAX_TREFFER + 5 - Volltextsuche::SCHRITT_DOKUMENTE, $stand->offen);

        $stand = $suche->schritt(self::BENUTZER, $bereich, $this->tresor);
        self::assertTrue($stand->aktuell());
        self::assertSame(2, $this->teile());

        $treffer = $suche->suche(self::BENUTZER, $bereich, $this->tresor, Suchanfrage::aus('vereinsheim'));
        self::assertNotNull($treffer);
        self::assertCount(Volltextsuche::MAX_TREFFER, $treffer->treffer);
        self::assertTrue($treffer->mehr);
        rsort($ids);
        self::assertSame(array_slice($ids, 0, Volltextsuche::MAX_TREFFER), array_map(static fn(Suchtreffer $t): int => $t->item->document->id, $treffer->treffer), 'newest first');
    }

    public function testNothingReadableIsStoredAndOnlyTheVaultOpensTheIndex(): void
    {
        $id = $this->dokument();
        $this->textlayer($id, ['Geheimwort Zebrastreifen Sporthaus']);
        $suche = $this->suche();
        $suche->schritt(self::BENUTZER, Zugriffsbereich::unbeschraenkt(), $this->tresor);

        foreach ($this->dateien() as $datei) {
            $inhalt = (string) file_get_contents($datei);
            self::assertStringNotContainsStringIgnoringCase('zebrastreifen', $inhalt);
            // Version byte, nonce and tag around a payload padded to 4 KiB blocks.
            self::assertSame(0, (strlen($inhalt) - 1 - 24 - 16) % 4096, 'parts are padded');
        }
        $sitzung = serialize($_SESSION);
        self::assertStringNotContainsStringIgnoringCase('zebrastreifen', $sitzung);
        self::assertStringNotContainsString('geheimwort', $sitzung);

        // The key in the session is sealed to the vault: another vault (or
        // none) cannot open it.
        $gespeichert = new SitzungsSuchindex()->lies();
        self::assertNotNull($gespeichert);
        $this->expectException(CryptoException::class);
        Vault::create()->openDataKey($gespeichert['schluessel']);
    }

    public function testNewTextIsTakenInAndTheNewestEntryCounts(): void
    {
        $id = $this->dokument();
        $this->textlayer($id, ['Trikots für die E-Jugend']);
        $suche = $this->suche();
        $bereich = Zugriffsbereich::unbeschraenkt();
        $suche->schritt(self::BENUTZER, $bereich, $this->tresor);
        self::assertSame([$id], $this->gefunden($suche, 'trikots'));

        // A new run of the job: new rows, newest run counts.
        $this->textlayer($id, ['Bälle für die F-Jugend']);
        $neu = $this->dokument();
        $this->kiExtraktion($neu, ['purpose_short' => 'Bälle Training']);
        self::assertSame(2, $suche->stand(self::BENUTZER, $bereich, $this->tresor)->offen);

        $stand = $suche->nachziehen(self::BENUTZER, $bereich, $this->tresor);
        self::assertTrue($stand->aktuell(), 'a few new documents are taken in right away');
        self::assertSame([], $this->gefunden($suche, 'trikots'));
        self::assertSame([$neu, $id], $this->gefunden($suche, 'bälle'));
    }

    public function testOnlyTheScopeIsIndexedAndShown(): void
    {
        $kostenstellen = new CostCenterRepository($this->pdo());
        $herren = $kostenstellen->create('Herren');
        $jugend = $kostenstellen->create('Jugend');
        $eigene = $this->dokument(kostenstelle: $jugend);
        $this->textlayer($eigene, ['Trikots Jugend']);
        $fremde = $this->dokument(kostenstelle: $herren);
        $this->textlayer($fremde, ['Trikots Herren']);
        $ohne = $this->dokument();
        $this->textlayer($ohne, ['Trikots ohne Zuordnung']);

        $suche = $this->suche();
        $bereich = new Zugriffsbereich([$jugend]);
        self::assertSame(1, $suche->stand(self::BENUTZER, $bereich, $this->tresor)->offen);
        $suche->schritt(self::BENUTZER, $bereich, $this->tresor);
        self::assertSame([$eigene], $this->gefunden($suche, 'trikots', $bereich));
        foreach ($this->dateien() as $datei) {
            self::assertLessThan(4096 * 2, filesize($datei), 'one small part with one document');
        }

        // The document leaves the scope after the build: the index no longer
        // matches the scope and is not trusted, the rebuild leaves it out.
        $this->pdo()->prepare('UPDATE document SET cost_center_id = ? WHERE id = ?')->execute([$herren, $eigene]);
        self::assertNull($suche->suche(self::BENUTZER, $bereich, $this->tresor, Suchanfrage::aus('trikots')));
        self::assertTrue($suche->nachziehen(self::BENUTZER, $bereich, $this->tresor)->aktuell());
        self::assertSame([], $this->gefunden($suche, 'trikots', $bereich));
        $this->pdo()->prepare('UPDATE document SET cost_center_id = ? WHERE id = ?')->execute([$jugend, $eigene]);
        $suche->nachziehen(self::BENUTZER, $bereich, $this->tresor);

        // A changed scope starts over.
        $alt = (string) new SitzungsSuchindex()->lies()['id'];
        $weiter = new Zugriffsbereich([$jugend, $herren]);
        self::assertFalse($suche->stand(self::BENUTZER, $weiter, $this->tresor)->fertig);
        self::assertDirectoryDoesNotExist($this->verzeichnis . '/' . $alt);
        $suche->schritt(self::BENUTZER, $weiter, $this->tresor);
        self::assertSame([$fremde, $eigene], $this->gefunden($suche, 'trikots', $weiter));
    }

    public function testAnOldDocumentEnteringTheScopeRebuildsTheIndex(): void
    {
        $kostenstellen = new CostCenterRepository($this->pdo());
        $jugend = $kostenstellen->create('Jugend');
        $eigene = $this->dokument(kostenstelle: $jugend);
        $this->textlayer($eigene, ['Trikots Jugend']);
        $spaeter = $this->dokument();
        $this->textlayer($spaeter, ['Trikots nachträglich zugeordnet']);

        $suche = $this->suche();
        $bereich = new Zugriffsbereich([$jugend]);
        $suche->schritt(self::BENUTZER, $bereich, $this->tresor);
        self::assertSame([$eigene], $this->gefunden($suche, 'trikots', $bereich));

        // Assigned to the cost center after the build - no new artifact.
        $this->pdo()->prepare('UPDATE document SET cost_center_id = ? WHERE id = ?')->execute([$jugend, $spaeter]);
        $stand = $suche->nachziehen(self::BENUTZER, $bereich, $this->tresor);

        self::assertTrue($stand->aktuell());
        self::assertSame([$spaeter, $eigene], $this->gefunden($suche, 'trikots', $bereich));
    }

    public function testEachDocumentIsIndexedOncePerChange(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $id = $this->dokument();
            $this->textlayer($id, ['Textebene ' . $i]);
        }
        // A second wave of artifacts for the same documents, before the build.
        foreach ($ids as $id) {
            $this->kiExtraktion($id, ['purpose_short' => 'Zweck']);
        }

        $suche = $this->suche();
        $bereich = Zugriffsbereich::unbeschraenkt();
        self::assertSame(3, $suche->stand(self::BENUTZER, $bereich, $this->tresor)->offen);
        self::assertTrue($suche->schritt(self::BENUTZER, $bereich, $this->tresor)->aktuell());
        self::assertSame(1, $this->teile());
        self::assertSame(array_reverse($ids), $this->gefunden($suche, 'zweck textebene'));
    }

    public function testAnotherAccountOrVaultStartsOver(): void
    {
        $id = $this->dokument();
        $this->textlayer($id, ['Trikots']);
        $suche = $this->suche();
        $bereich = Zugriffsbereich::unbeschraenkt();
        $suche->schritt(self::BENUTZER, $bereich, $this->tresor);
        $alt = (string) new SitzungsSuchindex()->lies()['id'];

        self::assertFalse($suche->stand(self::BENUTZER + 1, $bereich, $this->tresor)->fertig);
        self::assertNull(new SitzungsSuchindex()->lies());
        self::assertDirectoryDoesNotExist($this->verzeichnis . '/' . $alt);

        $suche->schritt(self::BENUTZER, $bereich, $this->tresor);
        self::assertNull($suche->suche(self::BENUTZER, $bereich, Vault::create(), Suchanfrage::aus('trikots')));
        self::assertNull(new SitzungsSuchindex()->lies(), 'a vault that does not open the key throws the index away');
    }

    public function testAMissingOrAlteredPartIsNotTrusted(): void
    {
        $id = $this->dokument();
        $this->textlayer($id, ['Trikots']);
        $suche = $this->suche();
        $bereich = Zugriffsbereich::unbeschraenkt();

        $suche->schritt(self::BENUTZER, $bereich, $this->tresor);
        $datei = $this->dateien()[0];
        $inhalt = (string) file_get_contents($datei);
        $inhalt[40] = chr(ord($inhalt[40]) ^ 1);
        file_put_contents($datei, $inhalt);
        self::assertNull($suche->suche(self::BENUTZER, $bereich, $this->tresor, Suchanfrage::aus('trikots')));
        self::assertNull(new SitzungsSuchindex()->lies());

        $suche->schritt(self::BENUTZER, $bereich, $this->tresor);
        unlink($this->dateien()[0]);
        self::assertNull($suche->suche(self::BENUTZER, $bereich, $this->tresor, Suchanfrage::aus('trikots')));
        self::assertFalse($suche->stand(self::BENUTZER, $bereich, $this->tresor)->fertig);
    }

    public function testAnUnreadableArtifactDoesNotStopTheBuild(): void
    {
        $kaputt = $this->dokument();
        $this->textlayer($kaputt, ['Trikots kaputt']);
        $this->pdo()->prepare('UPDATE document_artifact SET data_enc = ? WHERE document_id = ?')->execute([random_bytes(80), $kaputt]);
        $ki = $this->dokument();
        $this->kiExtraktion($ki, ['purpose_short' => 'Trikots heil']);
        $this->textlayer($ki, ['Trikots Textebene']);

        $suche = $this->suche();
        self::assertTrue($suche->schritt(self::BENUTZER, Zugriffsbereich::unbeschraenkt(), $this->tresor)->aktuell());

        self::assertSame([$ki], $this->gefunden($suche, 'trikots'));
        self::assertSame([$ki], $this->gefunden($suche, 'heil textebene'), 'the other sources of a document still count');
    }

    public function testDiscardingRemovesTheFilesAndTheSessionEntry(): void
    {
        $id = $this->dokument();
        $this->textlayer($id, ['Trikots']);
        $suche = $this->suche();
        $suche->schritt(self::BENUTZER, Zugriffsbereich::unbeschraenkt(), $this->tresor);
        self::assertNotSame([], $this->dateien());

        $suche->verwerfen();

        self::assertSame([], $this->dateien());
        self::assertArrayNotHasKey('suchindex', $_SESSION);
    }

    public function testAnEmptySearchFindsNothing(): void
    {
        $id = $this->dokument(status: DocumentStatus::Abgelehnt);
        $this->textlayer($id, ['Trikots']);
        $suche = $this->suche();
        $bereich = Zugriffsbereich::unbeschraenkt();
        $suche->schritt(self::BENUTZER, $bereich, $this->tresor);

        self::assertSame([], $suche->suche(self::BENUTZER, $bereich, $this->tresor, Suchanfrage::aus(' x '))?->treffer);
        self::assertSame([$id], $this->gefunden($suche, 'trikots'), 'every status is searchable, a rejected one too');
    }

    // ------------------------------------------------------------ helpers

    private function suche(): Volltextsuche
    {
        $pdo = $this->pdo();
        $blobs = new BlobRepository($pdo);
        $blobService = new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend($this->blobDir));
        $texterkennung = new Texterkennung(new DocumentRepository($pdo), new DocumentArtifactRepository($pdo), $blobService);

        return new Volltextsuche(
            new DocumentRepository($pdo),
            new DocumentArtifactRepository($pdo),
            new SuchindexSpeicher($this->verzeichnis),
            new SitzungsSuchindex(),
            [
                new TextlayerQuelle($texterkennung),
                new ERechnungQuelle($texterkennung),
                new KiQuelle(KiAuslesungFabrik::bauen($pdo, $blobService, new ServerCrypto(random_bytes(32)))),
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function gefunden(Volltextsuche $suche, string $eingabe, ?Zugriffsbereich $bereich = null): array
    {
        $ergebnis = $suche->suche(self::BENUTZER, $bereich ?? Zugriffsbereich::unbeschraenkt(), $this->tresor, Suchanfrage::aus($eingabe));
        self::assertNotNull($ergebnis);

        return array_map(static fn(Suchtreffer $t): int => $t->item->document->id, $ergebnis->treffer);
    }

    private function dokument(?int $kostenstelle = null, DocumentStatus $status = DocumentStatus::InPruefung): int
    {
        return new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
            $status,
            costCenterId: $kostenstelle,
        );
    }

    /**
     * @param list<string> $seiten
     */
    private function textlayer(int $documentId, array $seiten): void
    {
        $this->artefakt($documentId, ArtifactKind::Text, ['befund' => 'gelesen', 'brauchbar' => true, 'seiten' => $seiten]);
    }

    /**
     * @param array<string, mixed> $extraktion
     */
    private function eRechnung(int $documentId, array $extraktion): void
    {
        $this->artefakt($documentId, ArtifactKind::ERechnung, ['befund' => 'gelesen', 'syntax' => 'cii', 'profil' => 'EN16931', 'extraktion' => $extraktion]);
    }

    /**
     * @param array<string, mixed> $extraktion
     */
    private function kiExtraktion(int $documentId, array $extraktion): void
    {
        $this->artefakt($documentId, ArtifactKind::Extraction, ['versuch' => [], 'meldung' => 'Ausgelesen.', 'extraktion' => $extraktion, 'hinweise' => []]);
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function artefakt(int $documentId, ArtifactKind $kind, array $daten): void
    {
        $repository = new DocumentArtifactRepository($this->pdo());
        $schluessel = DataKey::generate();
        $id = $repository->insert(
            $documentId,
            $kind,
            0,
            null,
            $this->tresor->sealDataKey($schluessel),
            null,
            JobExecutor::Session,
            ++$this->jobId,
            new \DateTimeImmutable(),
        );
        $repository->setzeDaten($id, FieldCipher::encrypt(
            $schluessel,
            json_encode($daten, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            new FieldContext('document_artifact', $id, 'data_enc'),
        ));
    }

    private function teile(): int
    {
        return count($this->dateien());
    }

    /**
     * @return list<string>
     */
    private function dateien(): array
    {
        return glob($this->verzeichnis . '/*/*.bin') ?: [];
    }

    private static function entferne(string $verzeichnis): void
    {
        if (!is_dir($verzeichnis)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($verzeichnis, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $eintrag) {
            $eintrag->isDir() ? rmdir($eintrag->getPathname()) : unlink($eintrag->getPathname());
        }
        rmdir($verzeichnis);
    }
}
