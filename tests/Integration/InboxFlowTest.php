<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\InboxController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\SystemRole;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Http\StreamResponse;
use App\Repository\AuditLogRepository;
use App\Repository\BlobRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\RoleRepository;
use App\Repository\SubmissionRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Inbox\Posteingang;
use App\Service\Migration\Migrator;
use App\Service\MasterData\CostCenterRuleViolation;
use App\Service\MasterData\CostCenterService;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeJpeg;
use App\View\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The inbox end to end (issue #27/M4-5, docs/spec/02-datenmodell.md
 * "Statusmodell", docs/spec/03-erfassung-und-ki.md section 1): the real
 * route table, guard, controller, service, repositories and schema.
 */
final class InboxFlowTest extends DatabaseTestCase
{
    private const string IP = '198.51.100.9';

    private ServerCrypto $crypto;
    private RoleRepository $rollen;
    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private string $blobDir;
    private int $laufnummer = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_posteingang_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);

        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $this->crypto);

        $this->rollen = new RoleRepository($this->pdo());
        $this->userId = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt('finanzen@example.org'),
            random_bytes(32),
            $this->crypto->encrypt('Fritz Finanzen'),
            'hash',
            mfaRequired: false,
        );
        $this->alsRolle(SystemRole::Finanzen);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach (array_reverse(self::dateien($this->blobDir)) as $eintrag) {
            $pfad = $this->blobDir . '/' . $eintrag;
            is_dir($pfad) ? rmdir($pfad) : unlink($pfad);
        }
        rmdir($this->blobDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------- rights

    /**
     * `inbox.view` in every shipped role; deciding is `document.edit` -
     * Admin and Finanzen (01 section 4).
     *
     * @return iterable<string, array{SystemRole, bool}>
     */
    public static function rollen(): iterable
    {
        yield 'Admin' => [SystemRole::Admin, true];
        yield 'Vorstand' => [SystemRole::Vorstand, false];
        yield 'Finanzen' => [SystemRole::Finanzen, true];
        yield 'Kassenprüfer' => [SystemRole::Kassenpruefer, false];
        yield 'Steuerberater' => [SystemRole::Steuerberater, false];
        yield 'Vereinsverantwortlicher' => [SystemRole::Vereinsverantwortlicher, false];
    }

    #[DataProvider('rollen')]
    public function testEveryRoleReadsButOnlyDocumentEditDecides(SystemRole $rolle, bool $entscheidet): void
    {
        $id = $this->einreichung();
        $this->alsRolle($rolle);

        $liste = $this->get('/app/posteingang', entsperrt: true);
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('href="/app/posteingang"', $liste->body, 'the navigation entry is live');

        $antwort = $this->post('/app/posteingang/' . $id . '/annehmen');
        self::assertSame($entscheidet ? 302 : 403, $antwort->status);
        self::assertSame($entscheidet ? DocumentStatus::BereitZurAuswertung : DocumentStatus::Eingegangen, $this->belegStatus($id));
    }

    public function testTheDetailOffersDecisionsOnlyToDocumentEdit(): void
    {
        $id = $this->einreichung();

        self::assertStringContainsString('/annehmen', $this->get('/app/posteingang/' . $id, entsperrt: true)->body);

        $this->alsRolle(SystemRole::Vorstand);
        $seite = $this->get('/app/posteingang/' . $id, entsperrt: true);
        self::assertSame(200, $seite->status);
        self::assertStringNotContainsString('/annehmen', $seite->body);
        self::assertStringNotContainsString('/kostenstelle', $seite->body);
    }

    public function testTheCostCenterScopeShowsOnlyOwnSubmissions(): void
    {
        $kostenstellen = new CostCenterRepository($this->pdo());
        $eigene = $kostenstellen->create('E-Jugend');
        $fremde = $kostenstellen->create('Herren');
        $sichtbar = $this->einreichung(name: 'Jana Jugend', kostenstelle: $eigene);
        $unsichtbar = $this->einreichung(name: 'Hans Herren', kostenstelle: $fremde);
        $ohne = $this->einreichung(name: 'Otto Ohne');

        $this->alsRolle(SystemRole::Vereinsverantwortlicher);
        new UserAccessRepository($this->pdo())->setCostCenters($this->userId, [$eigene]);

        $liste = $this->get('/app/posteingang', entsperrt: true)->body;
        self::assertStringContainsString('Jana Jugend', $liste);
        self::assertStringNotContainsString('Hans Herren', $liste);
        self::assertStringNotContainsString('Otto Ohne', $liste);

        self::assertSame(200, $this->get('/app/posteingang/' . $sichtbar, entsperrt: true)->status);
        self::assertSame(404, $this->get('/app/posteingang/' . $unsichtbar, entsperrt: true)->status);
        self::assertSame(404, $this->get('/app/posteingang/' . $ohne, entsperrt: true)->status);

        $blob = $this->originale($unsichtbar)[0];
        self::assertSame(404, $this->roh('/app/posteingang/' . $unsichtbar . '/datei/' . $blob, entsperrt: true)->status);
    }

    // --------------------------------------------------------------- list

    public function testWithoutTheVaultOnlyPlaintextColumnsShow(): void
    {
        $this->einreichung(name: 'Erika Geheim', freitext: 'Vertraulicher Zweck');

        $seite = $this->get('/app/posteingang');

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('R-2026-0001', $seite->body);
        self::assertStringContainsString('verschlüsselt', $seite->body);
        self::assertStringNotContainsString('Erika Geheim', $seite->body);
        self::assertStringNotContainsString('Vertraulicher Zweck', $seite->body);
        self::assertStringNotContainsString('name="suche"', $seite->body, 'no search over what cannot be read');
    }

    public function testWithTheVaultNameAndDescriptionShow(): void
    {
        $this->einreichung(name: 'Erika Musterfrau', freitext: 'Getränke Sommerfest');

        $seite = $this->get('/app/posteingang', entsperrt: true);

        self::assertStringContainsString('Erika Musterfrau', $seite->body);
        self::assertStringContainsString('Getränke Sommerfest', $seite->body);
    }

    public function testTheViewsSplitByStatus(): void
    {
        $gestern = new \DateTimeImmutable('yesterday');
        $neu = $this->einreichung(name: 'Neu Eingang');
        $faellig = $this->einreichung(name: 'Faellige Wiedervorlage', status: DocumentStatus::Wiedervorlage, resubmitOn: $gestern);
        $spaeter = $this->einreichung(name: 'Spaetere Wiedervorlage', status: DocumentStatus::Wiedervorlage, resubmitOn: new \DateTimeImmutable('+5 days'));
        $abgelehnt = $this->einreichung(name: 'Abgelehnter Beleg', status: DocumentStatus::Abgelehnt);
        $angenommen = $this->einreichung(name: 'Angenommener Beleg', status: DocumentStatus::BereitZurAuswertung);

        $erwartet = [
            'offen' => ['Neu Eingang', 'Faellige Wiedervorlage'],
            'wiedervorlage' => ['Faellige Wiedervorlage', 'Spaetere Wiedervorlage'],
            'abgelehnt' => ['Abgelehnter Beleg'],
            'angenommen' => ['Angenommener Beleg'],
            'alle' => ['Neu Eingang', 'Faellige Wiedervorlage', 'Spaetere Wiedervorlage', 'Abgelehnter Beleg', 'Angenommener Beleg'],
        ];
        $alle = $erwartet['alle'];
        foreach ($erwartet as $ansicht => $namen) {
            $seite = $this->get('/app/posteingang', ['ansicht' => $ansicht], entsperrt: true)->body;
            foreach ($alle as $name) {
                if (in_array($name, $namen, true)) {
                    self::assertStringContainsString($name, $seite, $ansicht);
                } else {
                    self::assertStringNotContainsString($name, $seite, $ansicht);
                }
            }
        }
        self::assertCount(5, array_unique([$neu, $faellig, $spaeter, $abgelehnt, $angenommen]));
    }

    public function testFiltersByCostCenterPeriodAndSearch(): void
    {
        $kostenstelle = new CostCenterRepository($this->pdo())->create('Vereinsheim');
        $this->einreichung(name: 'Anna Heim', freitext: 'Glühbirnen', kostenstelle: $kostenstelle);
        $this->einreichung(name: 'Bernd Ohne', freitext: 'Trikots waschen');
        $this->einreichung(name: 'Carla Alt', freitext: 'Bälle', erstellt: new \DateTimeImmutable('2025-03-10 12:00'));

        $nachKostenstelle = $this->get('/app/posteingang', ['kostenstelle' => (string) $kostenstelle], entsperrt: true)->body;
        self::assertStringContainsString('Anna Heim', $nachKostenstelle);
        self::assertStringNotContainsString('Bernd Ohne', $nachKostenstelle);

        $ohne = $this->get('/app/posteingang', ['kostenstelle' => 'ohne'], entsperrt: true)->body;
        self::assertStringContainsString('Bernd Ohne', $ohne);
        self::assertStringNotContainsString('Anna Heim', $ohne);

        $zeitraum = $this->get('/app/posteingang', ['von' => '2025-03-01', 'bis' => '2025-03-10'], entsperrt: true)->body;
        self::assertStringContainsString('Carla Alt', $zeitraum);
        self::assertStringNotContainsString('Anna Heim', $zeitraum);

        $suche = $this->get('/app/posteingang', ['suche' => 'trikots'], entsperrt: true)->body;
        self::assertStringContainsString('Bernd Ohne', $suche);
        self::assertStringNotContainsString('Anna Heim', $suche);
        self::assertStringNotContainsString('Carla Alt', $suche);
    }

    // ---------------------------------------------------------- decisions

    public function testAcceptingReleasesForEvaluationAndIsAudited(): void
    {
        $id = $this->einreichung();

        $antwort = $this->post('/app/posteingang/' . $id . '/annehmen');

        self::assertSame(302, $antwort->status);
        self::assertSame('/app/posteingang', $antwort->headers['Location'] ?? null);
        self::assertSame(DocumentStatus::BereitZurAuswertung, $this->belegStatus($id));
        self::assertStringContainsString('Einreichung R-2026-0001 angenommen.', $this->get('/app/posteingang')->body);

        $zeile = $this->zeile($id);
        self::assertSame((string) $this->userId, (string) $zeile['status_changed_by']);
        self::assertNotNull($zeile['status_changed_at']);
        self::assertSame([AuditAction::BelegAngenommen->value], $this->auditAktionen());
    }

    public function testRejectingNeedsAReason(): void
    {
        $id = $this->einreichung();

        $this->post('/app/posteingang/' . $id . '/ablehnen', ['grund' => '   ']);

        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($id));
        self::assertStringContainsString('Bitte einen Grund für die Ablehnung angeben.', $this->get('/app/posteingang/' . $id, entsperrt: true)->body);
        self::assertSame([], $this->auditAktionen());
    }

    public function testARejectionKeepsTheDocumentAndStoresTheReasonEncrypted(): void
    {
        $id = $this->einreichung();

        $antwort = $this->post('/app/posteingang/' . $id . '/ablehnen', ['grund' => 'Privatkauf, kein Vereinsbeleg']);

        self::assertSame(302, $antwort->status);
        self::assertSame(DocumentStatus::Abgelehnt, $this->belegStatus($id));

        $zeile = $this->zeile($id);
        self::assertStringNotContainsString('Privatkauf', (string) $zeile['status_note_enc']);
        self::assertSame('Privatkauf, kein Vereinsbeleg', FieldCipher::decrypt(
            $this->tresor->openDataKey((string) $zeile['dek_sealed']),
            (string) $zeile['status_note_enc'],
            new FieldContext('document', $id, 'status_note_enc'),
        ));

        $detail = $this->get('/app/posteingang/' . $id, entsperrt: true)->body;
        self::assertStringContainsString('Grund der Ablehnung', $detail);
        self::assertStringContainsString('Privatkauf, kein Vereinsbeleg', $detail);
        $this->assertBlobsBleiben($id);

        // The reason is club data: sealed in the audit log, never plaintext.
        self::assertSame([AuditAction::BelegAbgelehnt->value], $this->auditAktionen());
        self::assertStringNotContainsString('Privatkauf', $this->rohTabelle('audit_log'));
    }

    public function testAResubmissionNeedsADateNotInThePast(): void
    {
        $id = $this->einreichung();

        $this->post('/app/posteingang/' . $id . '/wiedervorlage', ['datum' => '']);
        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($id));

        $this->post('/app/posteingang/' . $id . '/wiedervorlage', ['datum' => new \DateTimeImmutable('yesterday')->format('Y-m-d')]);
        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($id));
        self::assertStringContainsString('nicht in der Vergangenheit', $this->get('/app/posteingang/' . $id, entsperrt: true)->body);
    }

    public function testAResubmissionComesBackOnItsDate(): void
    {
        $id = $this->einreichung(name: 'Wieder Vorlage');
        $datum = new \DateTimeImmutable('+3 days');

        $this->post('/app/posteingang/' . $id . '/wiedervorlage', ['datum' => $datum->format('Y-m-d'), 'notiz' => 'Rechnung fehlt noch']);

        self::assertSame(DocumentStatus::Wiedervorlage, $this->belegStatus($id));
        $zeile = $this->zeile($id);
        self::assertSame($datum->format('Y-m-d'), $zeile['resubmit_on']);
        self::assertStringNotContainsString('Rechnung fehlt', (string) $zeile['status_note_enc']);
        self::assertStringNotContainsString('Wieder Vorlage', $this->get('/app/posteingang', entsperrt: true)->body, 'not open before its date');
        self::assertStringContainsString('Rechnung fehlt noch', $this->get('/app/posteingang/' . $id, entsperrt: true)->body);

        // The date has come.
        $this->pdo()->prepare('UPDATE document SET resubmit_on = ? WHERE id = ?')->execute([new \DateTimeImmutable('today')->format('Y-m-d'), $id]);
        self::assertStringContainsString('Wieder Vorlage', $this->get('/app/posteingang', entsperrt: true)->body);

        // Accepting from the Wiedervorlage clears date and note.
        $this->post('/app/posteingang/' . $id . '/annehmen');
        $zeile = $this->zeile($id);
        self::assertSame(DocumentStatus::BereitZurAuswertung->value, $zeile['status']);
        self::assertNull($zeile['resubmit_on']);
        self::assertNull($zeile['status_note_enc']);
        self::assertSame([AuditAction::BelegWiedervorlage->value, AuditAction::BelegAngenommen->value], $this->auditAktionen());
    }

    public function testATransitionTheModelForbidsIsRefused(): void
    {
        $id = $this->einreichung(status: DocumentStatus::Abgelehnt);

        $this->post('/app/posteingang/' . $id . '/annehmen');
        $this->post('/app/posteingang/' . $id . '/wiedervorlage', ['datum' => new \DateTimeImmutable('+1 day')->format('Y-m-d')]);

        self::assertSame(DocumentStatus::Abgelehnt, $this->belegStatus($id));
        self::assertStringContainsString('Dieser Statuswechsel ist für den Beleg nicht möglich.', $this->get('/app/posteingang/' . $id)->body);
        self::assertSame([], $this->auditAktionen());
    }

    public function testDecisionsNeedTheCsrfToken(): void
    {
        $id = $this->einreichung();

        $antwort = $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/posteingang/' . $id . '/annehmen',
            cookies: $this->tresorCookie(),
        ));

        self::assertSame(302, $antwort->status);
        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($id));
    }

    public function testDecisionsNeedTheUnlockedVault(): void
    {
        $id = $this->einreichung();

        $this->post('/app/posteingang/' . $id . '/annehmen', entsperrt: false);

        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($id));
        $seite = $this->get('/app/posteingang/' . $id)->body;
        self::assertStringContainsString('nicht entsperrt', $seite);
        self::assertStringNotContainsString('/annehmen', $seite, 'no decisions offered without the vault');
    }

    public function testAnUnknownDocumentIsNotFound(): void
    {
        self::assertSame(404, $this->get('/app/posteingang/999', entsperrt: true)->status);
        self::assertSame(404, $this->post('/app/posteingang/999/annehmen')->status);
    }

    public function testTheCostCenterCanBeChangedAndIsAudited(): void
    {
        $kostenstellen = new CostCenterRepository($this->pdo());
        $herren = $kostenstellen->create('Herren');
        $inaktiv = $kostenstellen->create('Alte Herren');
        $kostenstellen->update($inaktiv, 'Alte Herren', false);
        $id = $this->einreichung();

        $this->post('/app/posteingang/' . $id . '/kostenstelle', ['kostenstelle' => (string) $herren]);
        self::assertSame((string) $herren, (string) $this->zeile($id)['cost_center_id']);

        $this->post('/app/posteingang/' . $id . '/kostenstelle', ['kostenstelle' => (string) $inaktiv]);
        self::assertSame((string) $herren, (string) $this->zeile($id)['cost_center_id'], 'no deactivated cost center');

        $this->post('/app/posteingang/' . $id . '/kostenstelle', ['kostenstelle' => '']);
        self::assertNull($this->zeile($id)['cost_center_id']);

        self::assertSame([AuditAction::BelegKostenstelle->value, AuditAction::BelegKostenstelle->value], $this->auditAktionen());
    }

    public function testACostCenterCarryingReceiptsCannotBeDeleted(): void
    {
        $kostenstellen = new CostCenterRepository($this->pdo());
        $id = $kostenstellen->create('Jugend');
        $this->einreichung(kostenstelle: $id);

        $this->expectException(CostCenterRuleViolation::class);
        new CostCenterService($kostenstellen)->loeschen($id);
    }

    // ------------------------------------------------------------ preview

    /**
     * @return array<string, array{BlobStorage}>
     */
    public static function backends(): array
    {
        return ['Dateisystem' => [BlobStorage::Fs], 'Datenbank' => [BlobStorage::Db]];
    }

    #[DataProvider('backends')]
    public function testPagesStreamDecryptedWithoutATempFile(BlobStorage $storage): void
    {
        $jpeg = FakeJpeg::bauen(40, 30) . random_bytes(200_000);
        $pdf = '%PDF-1.7 ' . random_bytes(100);
        $id = $this->einreichung(seiten: [[$jpeg, MagicBytes::JPEG], [$pdf, MagicBytes::PDF]], storage: $storage);
        [$bildBlob, $pdfBlob] = $this->originale($id);
        $vorher = self::dateien($this->blobDir);

        $bild = $this->roh('/app/posteingang/' . $id . '/datei/' . $bildBlob, entsperrt: true);
        self::assertInstanceOf(StreamResponse::class, $bild);
        self::assertSame(200, $bild->status);
        self::assertSame(MagicBytes::JPEG, $bild->headers['Content-Type']);
        self::assertSame('inline; filename="R-2026-0001-1.jpg"', $bild->headers['Content-Disposition'], 'only the reference, never the original name');
        self::assertSame('no-store, private', $bild->headers['Cache-Control']);
        self::assertSame('nosniff', $bild->headers['X-Content-Type-Options']);
        self::assertSame($jpeg, self::inhalt($bild));

        $dokument = $this->roh('/app/posteingang/' . $id . '/datei/' . $pdfBlob, entsperrt: true);
        self::assertInstanceOf(StreamResponse::class, $dokument);
        self::assertSame(MagicBytes::PDF, $dokument->headers['Content-Type']);
        self::assertSame($pdf, self::inhalt($dokument));

        self::assertSame($vorher, self::dateien($this->blobDir), 'nothing written next to the blobs');

        $detail = $this->get('/app/posteingang/' . $id, entsperrt: true)->body;
        self::assertStringContainsString('<img src="/app/posteingang/' . $id . '/datei/' . $bildBlob . '"', $detail);
        self::assertStringContainsString('href="/app/posteingang/' . $id . '/datei/' . $pdfBlob . '"', $detail);
        self::assertStringNotContainsString('geheim-original', $detail);
    }

    public function testOnlyTheDocumentsOwnBlobsAreServed(): void
    {
        $id = $this->einreichung();
        $andere = $this->einreichung();
        $fremd = $this->originale($andere)[0];

        self::assertSame(404, $this->roh('/app/posteingang/' . $id . '/datei/' . $fremd, entsperrt: true)->status);
        self::assertSame(404, $this->roh('/app/posteingang/' . $id . '/datei/99999', entsperrt: true)->status);
    }

    public function testPagesNeedTheUnlockedVault(): void
    {
        $id = $this->einreichung();
        $blob = $this->originale($id)[0];

        $antwort = $this->roh('/app/posteingang/' . $id . '/datei/' . $blob);

        self::assertSame(403, $antwort->status);
        self::assertInstanceOf(Response::class, $antwort);
        self::assertStringNotContainsString("\xFF\xD8", $antwort->body);
    }

    // ----------------------------------------------------------- scaffolding

    /**
     * A submission as App\Service\Submission\SubmissionService writes it -
     * payload sealed to the vault, pages as encrypted blobs.
     *
     * @param list<array{string, string}>|null $seiten bytes and MIME type
     */
    private function einreichung(
        string $name = 'Max Muster',
        string $freitext = 'Sportgeräte',
        ?int $kostenstelle = null,
        DocumentStatus $status = DocumentStatus::Eingegangen,
        ?\DateTimeImmutable $resubmitOn = null,
        ?\DateTimeImmutable $erstellt = null,
        ?array $seiten = null,
        BlobStorage $storage = BlobStorage::Fs,
    ): int {
        $erstellt ??= new \DateTimeImmutable();
        $seiten ??= [[FakeJpeg::bauen(10, 10), MagicBytes::JPEG]];

        $blobIds = [];
        foreach ($seiten as [$inhalt, $mime]) {
            $blobIds[] = $this->blobService()->storeString($inhalt, new BlobMeta($mime, 'geheim-original.bin'), $this->tresor, $storage)->id;
        }

        $submissions = new SubmissionRepository($this->pdo());
        $submissionId = $submissions->insertDraft(random_bytes(32), $erstellt);
        $key = DataKey::generate();
        $payload = json_encode([
            'name' => $name,
            'erstattung' => ['art' => 'ueberweisung', 'iban' => 'DE89370400440532013000', 'kontoinhaber' => $name],
            'freitext' => $freitext,
        ], JSON_THROW_ON_ERROR);
        $submissions->complete(
            $submissionId,
            sprintf('R-2026-%04d', ++$this->laufnummer),
            $this->tresor->sealDataKey($key),
            FieldCipher::encrypt($key, $payload, new FieldContext('submission', $submissionId, 'payload_enc')),
        );

        $id = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Einreichung,
            $submissionId,
            $blobIds,
            $this->tresor->sealDataKey(DataKey::generate()),
            $erstellt,
            $status,
            costCenterId: $kostenstelle,
        );
        if ($resubmitOn !== null) {
            $this->pdo()->prepare('UPDATE document SET resubmit_on = ? WHERE id = ?')->execute([$resubmitOn->format('Y-m-d'), $id]);
        }

        return $id;
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    private function alsRolle(SystemRole $rolle): void
    {
        $id = $this->rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $this->rollen->assignToUser($this->userId, [$id]);
    }

    private function belegStatus(int $id): DocumentStatus
    {
        return DocumentStatus::from((string) $this->zeile($id)['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function zeile(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM document WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    /**
     * @return list<int>
     */
    private function originale(int $id): array
    {
        return array_map(intval(...), json_decode((string) $this->zeile($id)['original_blob_ids'], true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * A rejected document keeps its pages (the spec: "bleibt erhalten").
     */
    private function assertBlobsBleiben(int $id): void
    {
        foreach ($this->originale($id) as $blobId) {
            self::assertNotNull(new BlobRepository($this->pdo())->find($blobId));
        }
    }

    /**
     * @return list<string>
     */
    private function auditAktionen(): array
    {
        return array_map(strval(...), $this->pdo()->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function rohTabelle(string $tabelle): string
    {
        $roh = '';
        foreach ($this->pdo()->query('SELECT * FROM ' . $tabelle)->fetchAll() as $zeile) {
            foreach ($zeile as $wert) {
                if (is_string($wert)) {
                    $roh .= $wert . "\x00";
                }
            }
        }

        return $roh;
    }

    private static function inhalt(StreamResponse $antwort): string
    {
        $inhalt = '';
        foreach ($antwort->chunks as $stueck) {
            $inhalt .= $stueck;
        }

        return $inhalt;
    }

    /**
     * Everything below $dir, recursively, parents before their children.
     *
     * @return list<string>
     */
    private static function dateien(string $dir, string $prefix = ''): array
    {
        $alle = [];
        foreach (array_diff(scandir($dir . '/' . $prefix) ?: [], ['.', '..']) as $name) {
            $alle[] = $prefix . $name;
            if (is_dir($dir . '/' . $prefix . $name)) {
                array_push($alle, ...self::dateien($dir, $prefix . $name . '/'));
            }
        }

        return $alle;
    }

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(string $pfad, array $query = [], bool $entsperrt = false): Response
    {
        $antwort = $this->roh($pfad, $query, $entsperrt);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function roh(string $pfad, array $query = [], bool $entsperrt = false): ResponseInterface
    {
        return $this->kernel()->handle(new Request(
            HttpMethod::Get,
            $pfad,
            query: $query,
            cookies: $entsperrt ? $this->tresorCookie() : [],
        ));
    }

    /**
     * @param array<string, string> $felder
     */
    private function post(string $pfad, array $felder = [], bool $entsperrt = true): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            $pfad,
            cookies: $entsperrt ? $this->tresorCookie() : [],
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
        ));
    }

    private function dispatch(Request $request): Response
    {
        $antwort = $this->kernel()->handle($request);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();

        $guard = fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null
                    ? null
                    : new SessionUser($user, $this->crypto->decrypt($user->displayNameEnc), new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $posteingang = fn(): InboxController => new InboxController(
            $view,
            new Session(),
            new SessionVault(),
            new Posteingang(new DocumentRepository($pdo), new CostCenterRepository($pdo), $this->blobService(), $this->audit),
            new CostCenterRepository($pdo),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $unerreichbar,
            $guard,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $posteingang,
            $unerreichbar,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
