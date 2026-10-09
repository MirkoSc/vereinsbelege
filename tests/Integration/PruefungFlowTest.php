<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\PruefungController;
use App\Config\Paths;
use App\Domain\ArtifactKind;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InboxAction;
use App\Domain\InvoiceStructure;
use App\Domain\JobExecutor;
use App\Domain\Permission;
use App\Domain\PermissionScope;
use App\Domain\SupplierRole;
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
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentDuplicateRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\RoleRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\Duplikatpruefung;
use App\Service\Document\Texterkennung;
use App\Service\Inbox\InboxRuleViolation;
use App\Service\Inbox\Posteingang;
use App\Service\Invoice\Festschreibung;
use App\Service\Invoice\InvoiceRuleViolation;
use App\Service\Invoice\Pruefung;
use App\Service\Job\JobLaufStatus;
use App\Service\Job\JobRunner;
use App\Service\MasterData\SupplierService;
use App\Service\MasterData\SupplierZusammenfuehrung;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeJpeg;
use App\View\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The review page end to end (issue #37/M6-3, docs/spec/
 * 03-erfassung-und-ki.md section 6 "Prüfansicht", docs/spec/
 * 02-datenmodell.md "Fachdaten"): the real route table, guard, controller,
 * service, repositories and schema - plus locking a checked receipt and
 * lifting the lock (issue #38/M6-4, docs/spec/01-sicherheit.md section 7) -
 * and the form filled from an e-invoice without AI (issue #46/M7-4).
 */
final class PruefungFlowTest extends DatabaseTestCase
{
    private const string IP = '198.51.100.23';

    private const string IBAN = 'DE89370400440532013000';

    private const string NUMMER = 'RE-2026-0815';

    private ServerCrypto $crypto;
    private RoleRepository $rollen;
    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private string $blobDir;
    private int $ausgabeKategorie;
    private int $einnahmeKategorie;
    private int $laufnummer = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_pruefung_blobs_' . uniqid('', true);
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

        $this->ausgabeKategorie = $this->kategorie('Platzpflege & Grünanlagen');
        $this->einnahmeKategorie = $this->kategorie('Spenden');

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

    // -------------------------------------------------------------- queue

    public function testTheQueueListsWhatCanBeReviewedOldestFirst(): void
    {
        $neu = $this->beleg(DocumentStatus::Eingegangen);
        $alt = $this->beleg(erstellt: new \DateTimeImmutable('-3 days'));
        $ki = $this->beleg(DocumentStatus::KiFehler, erstellt: new \DateTimeImmutable('-2 days'));
        $jung = $this->beleg(erstellt: new \DateTimeImmutable('-1 day'));
        $geprueft = $this->beleg(DocumentStatus::Geprueft);
        $abgelehnt = $this->beleg(DocumentStatus::Abgelehnt);

        $seite = $this->get('/app/belege/pruefen', entsperrt: true);

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('href="/app/belege/pruefen"', $seite->body, 'the navigation entry is live');
        $positionen = array_map(fn(int $id): int|false => strpos($seite->body, '/app/belege/pruefen/' . $id . '"'), [$alt, $ki, $jung]);
        self::assertNotContains(false, $positionen);
        self::assertSame($positionen, array_values(array_unique($positionen)));
        $sortiert = $positionen;
        sort($sortiert);
        self::assertSame($sortiert, $positionen, 'oldest first');
        foreach ([$neu, $abgelehnt] as $nicht) {
            self::assertStringNotContainsString('/app/belege/pruefen/' . $nicht . '"', $seite->body);
        }
        // A checked receipt is not in the queue, but waits in its own section
        // below it (issue #38/M6-4).
        [$warteschlange, $zumFestschreiben] = explode('Geprüft – bereit zum Festschreiben', $seite->body, 2) + [1 => ''];
        self::assertStringNotContainsString('/app/belege/pruefen/' . $geprueft . '"', $warteschlange);
        self::assertStringContainsString('/app/belege/pruefen/' . $geprueft . '"', $zumFestschreiben);
        self::assertStringContainsString('Mit dem ältesten beginnen', $seite->body);
    }

    public function testAnEmptyQueueSaysSo(): void
    {
        $this->beleg(DocumentStatus::Eingegangen);

        self::assertStringContainsString('Nichts zu prüfen', $this->get('/app/belege/pruefen', entsperrt: true)->body);
    }

    // --------------------------------------------------------------- form

    public function testTheFormShowsThePagesAndStartsAsAnExpense(): void
    {
        $kostenstelle = new CostCenterRepository($this->pdo())->create('E-Jugend');
        $id = $this->beleg(kostenstelle: $kostenstelle);
        $blob = $this->originale($id)[0];

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true);

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('<img src="/app/belege/pruefen/' . $id . '/datei/' . $blob . '"', $seite->body);
        self::assertStringContainsString('/js/pruefansicht.js', $seite->body);
        self::assertMatchesRegularExpression('/name="richtung" value="ausgabe" checked/', $seite->body);
        self::assertMatchesRegularExpression('/<option value="' . $kostenstelle . '" selected>E-Jugend/', $seite->body);
        self::assertStringContainsString('Beleg 1 von 1', $seite->body);

        // Enter submits the form with its FIRST submit button (HTML's
        // implicit submission) - that has to be "Geprüft, nächster".
        $formular = substr($seite->body, (int) strpos($seite->body, 'class="pruefen-formular"'));
        preg_match_all('/<button type="submit"[^>]*>/', $formular, $knoepfe);
        self::assertStringContainsString('value="geprueft"', $knoepfe[0][0]);

        $datei = $this->roh('/app/belege/pruefen/' . $id . '/datei/' . $blob, entsperrt: true);
        self::assertInstanceOf(StreamResponse::class, $datei);
        self::assertSame(MagicBytes::JPEG, $datei->headers['Content-Type']);
        self::assertSame('no-store, private', $datei->headers['Cache-Control']);
    }

    public function testThePageImagesOfAPdfAreShownAndServed(): void
    {
        $id = $this->beleg(seiten: [['%PDF-1.4 test', MagicBytes::PDF]]);
        $pdf = $this->originale($id)[0];
        $artefakte = new DocumentArtifactRepository($this->pdo());
        $alt = $this->blobService()->storeString(FakeJpeg::bauen(8, 8), new BlobMeta(MagicBytes::JPEG, 'seite.jpg'), $this->tresor, BlobStorage::Fs)->id;
        $artefakte->insert($id, ArtifactKind::PageImage, 1, $alt, $this->tresor->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 1, new \DateTimeImmutable());
        $neu = $this->blobService()->storeString(FakeJpeg::bauen(8, 8), new BlobMeta(MagicBytes::JPEG, 'seite.jpg'), $this->tresor, BlobStorage::Fs)->id;
        $artefakte->insert($id, ArtifactKind::PageImage, 1, $neu, $this->tresor->sealDataKey(DataKey::generate()), null, JobExecutor::Browser, 2, new \DateTimeImmutable());

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true)->body;

        self::assertStringContainsString('<img src="/app/belege/pruefen/' . $id . '/datei/' . $neu . '"', $seite, 'the newest run');
        self::assertStringNotContainsString('/datei/' . $alt . '"', $seite, 'not a superseded run');
        self::assertStringContainsString('href="/app/belege/pruefen/' . $id . '/datei/' . $pdf . '"', $seite, 'the PDF itself as a link');
        self::assertInstanceOf(StreamResponse::class, $this->roh('/app/belege/pruefen/' . $id . '/datei/' . $neu, entsperrt: true));

        // Another document's page is not reachable through this one.
        $fremd = $this->beleg();
        self::assertSame(404, $this->roh('/app/belege/pruefen/' . $fremd . '/datei/' . $neu, entsperrt: true)->status);
    }

    public function testWithoutTheVaultNothingShowsAndNothingIsWritten(): void
    {
        $id = $this->beleg();

        $seite = $this->get('/app/belege/pruefen/' . $id);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $seite->body);
        self::assertStringNotContainsString('<form method="post" action="/app/belege/pruefen/', $seite->body);

        $antwort = $this->post('/app/belege/pruefen/' . $id, $this->felder(), entsperrt: false);
        self::assertSame(302, $antwort->status);
        self::assertSame(0, $this->anzahlBelege());
        self::assertSame(DocumentStatus::BereitZurAuswertung, $this->belegStatus($id));
        self::assertSame(403, $this->roh('/app/belege/pruefen/' . $id . '/datei/' . $this->originale($id)[0])->status);
    }

    public function testCsrfIsChecked(): void
    {
        $id = $this->beleg();

        $antwort = $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/belege/pruefen/' . $id,
            cookies: $this->tresorCookie(),
            post: [...$this->felder(), '_csrf' => 'falsch'],
        ));

        self::assertSame(302, $antwort->status);
        self::assertSame(0, $this->anzahlBelege());
    }

    public function testUnknownIdsAnswer404(): void
    {
        self::assertSame(404, $this->get('/app/belege/pruefen/999', entsperrt: true)->status);
        self::assertSame(404, $this->post('/app/belege/pruefen/999', $this->felder())->status);
    }

    // --------------------------------------------------------------- save

    public function testSavingWritesTheReceiptEncryptedAndStartsTheReview(): void
    {
        $kostenstelle = new CostCenterRepository($this->pdo())->create('Herren');
        $id = $this->beleg();

        $antwort = $this->post('/app/belege/pruefen/' . $id, $this->felder([
            'kostenstelle' => (string) $kostenstelle,
            'aktion' => 'speichern',
        ]));

        self::assertSame(302, $antwort->status);
        self::assertSame('/app/belege/pruefen/' . $id, $antwort->headers['Location']);
        self::assertSame(DocumentStatus::InPruefung, $this->belegStatus($id));
        self::assertSame($kostenstelle, (int) $this->dokument($id)['cost_center_id'], 'the document follows the receipt');

        $zeile = $this->rechnung($id);
        self::assertSame('2026-03-14', $zeile['invoice_date']);
        self::assertSame('ausgabe', $zeile['direction']);
        self::assertSame('rechnung', $zeile['doc_type']);
        self::assertSame($this->ausgabeKategorie, (int) $zeile['category_id']);
        self::assertSame($kostenstelle, (int) $zeile['cost_center_id']);
        self::assertNull($zeile['checked_at']);
        self::assertSame(
            $this->tresor->blindIndex()->forValue('invoice.number', self::NUMMER),
            $zeile['number_bi'],
            'the blind index of the number, recomputed',
        );

        $beleg = $this->pruefung()->beleg(new DocumentRepository($this->pdo())->find($id) ?? self::fail(), $this->tresor);
        self::assertNotNull($beleg);
        self::assertSame(self::NUMMER, $beleg->data->invoiceNumber);
        self::assertSame(123456, $beleg->data->gross);
        self::assertSame(103745, $beleg->data->net);
        self::assertSame([['rate' => '19', 'amount' => 19711]], $beleg->data->taxes);
        self::assertSame('EUR', $beleg->data->currency);
        self::assertSame('Rasendünger', $beleg->data->purposeShort);

        // Nothing of the receipt in plaintext - neither in its table nor in
        // the audit log.
        foreach (['invoice', 'audit_log', 'document'] as $tabelle) {
            $roh = $this->rohTabelle($tabelle);
            foreach ([self::NUMMER, '123456', '1.234,56', '103745', 'Rasendünger', 'Frühjahr'] as $klartext) {
                self::assertStringNotContainsString($klartext, $roh, $tabelle . ' contains ' . $klartext);
            }
        }

        $zeilen = $this->auditZeilen($id);
        self::assertSame([AuditAction::BelegBearbeitet->value], array_map(static fn(AuditEntry $e): string => $e->action, $zeilen));
        self::assertSame(
            ['felder' => ['doc_type', 'direction', 'invoice_date', 'category_id', 'cost_center_id', 'invoice_number', 'gross', 'net', 'taxes', 'currency', 'purpose_short', 'notes'], 'von' => 'bereit_zur_auswertung'],
            $this->audit->details($zeilen[0], $this->tresor),
        );

        // The form comes back as it was saved.
        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true)->body;
        self::assertStringContainsString('value="1.234,56"', $seite);
        self::assertStringContainsString('value="' . self::NUMMER . '"', $seite);
        self::assertMatchesRegularExpression('/name="steuer_satz_1"\s+value="19"/', $seite);
    }

    public function testSavingAgainKeepsTheKeyAndLogsOnlyTheChangedFieldNames(): void
    {
        $id = $this->beleg();
        $this->post('/app/belege/pruefen/' . $id, $this->felder(['aktion' => 'speichern']));
        $vorher = $this->rechnung($id);

        $this->post('/app/belege/pruefen/' . $id, $this->felder(['brutto' => '99,90', 'netto' => '', 'steuer_satz_1' => '', 'steuer_betrag_1' => '', 'aktion' => 'speichern']));
        // Unchanged: no audit row.
        $this->post('/app/belege/pruefen/' . $id, $this->felder(['brutto' => '99,90', 'netto' => '', 'steuer_satz_1' => '', 'steuer_betrag_1' => '', 'aktion' => 'speichern']));

        $nachher = $this->rechnung($id);
        self::assertSame($vorher['id'], $nachher['id']);
        self::assertSame($vorher['dek_sealed'], $nachher['dek_sealed'], 'the row keeps its data key');
        self::assertSame(1, $this->anzahlBelege());

        $zeilen = $this->auditZeilen($id);
        self::assertCount(2, $zeilen);
        self::assertSame(['felder' => ['gross', 'net', 'taxes']], $this->audit->details($zeilen[0], $this->tresor));
    }

    public function testCheckedNextMovesOnThroughTheQueueAndWrapsAround(): void
    {
        $a = $this->beleg(erstellt: new \DateTimeImmutable('-3 days'));
        $b = $this->beleg(erstellt: new \DateTimeImmutable('-2 days'));
        $c = $this->beleg(erstellt: new \DateTimeImmutable('-1 day'));

        $antwort = $this->post('/app/belege/pruefen/' . $a, $this->felder());
        self::assertSame('/app/belege/pruefen/' . $b, $antwort->headers['Location']);
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($a));
        $zeile = $this->rechnung($a);
        self::assertSame($this->userId, (int) $zeile['checked_by']);
        self::assertNotNull($zeile['checked_at']);
        self::assertSame(
            [AuditAction::BelegGeprueft->value, AuditAction::BelegBearbeitet->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $this->auditZeilen($a)),
        );

        // The last one of the queue continues at the start.
        self::assertSame('/app/belege/pruefen/' . $b, $this->post('/app/belege/pruefen/' . $c, $this->felder())->headers['Location']);

        $letzte = $this->post('/app/belege/pruefen/' . $b, $this->felder());
        self::assertSame('/app/belege/pruefen', $letzte->headers['Location']);
        self::assertStringContainsString('Beleg #' . $b . ' geprüft.', $this->get('/app/belege/pruefen', entsperrt: true)->body);
    }

    public function testACheckedReceiptIsReadOnly(): void
    {
        $id = $this->beleg();
        $this->post('/app/belege/pruefen/' . $id, $this->felder());

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true);
        self::assertStringContainsString('Dieser Beleg ist geprüft.', $seite->body);
        self::assertStringContainsString('<fieldset class="pruefen-felder" disabled>', $seite->body);
        self::assertStringNotContainsString('value="geprueft">Geprüft, nächster', $seite->body);

        $antwort = $this->post('/app/belege/pruefen/' . $id, $this->felder(['brutto' => '1,00']));
        self::assertSame(422, $antwort->status);
        self::assertSame(123456, $this->pruefung()->beleg(new DocumentRepository($this->pdo())->find($id) ?? self::fail(), $this->tresor)?->data->gross);
    }

    public function testADocumentNotYetAcceptedCannotBeCaptured(): void
    {
        $id = $this->beleg(DocumentStatus::Eingegangen);

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true);
        self::assertStringContainsString('noch nicht zur Prüfung freigegeben', $seite->body);
        self::assertStringContainsString('href="/app/posteingang/' . $id . '"', $seite->body);

        self::assertSame(422, $this->post('/app/belege/pruefen/' . $id, $this->felder())->status);
        self::assertSame(0, $this->anzahlBelege());
        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($id));
    }

    public function testAFailedAiDocumentIsCapturedByHand(): void
    {
        $id = $this->beleg(DocumentStatus::KiFehler);

        $this->post('/app/belege/pruefen/' . $id, $this->felder(['aktion' => 'speichern']));

        self::assertSame(DocumentStatus::InPruefung, $this->belegStatus($id));
    }

    public function testASumThatDoesNotAddUpWarnsButSaves(): void
    {
        $id = $this->beleg();

        $this->post('/app/belege/pruefen/' . $id, $this->felder(['netto' => '1.000,00', 'aktion' => 'speichern']));

        self::assertSame(DocumentStatus::InPruefung, $this->belegStatus($id));
        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true)->body;
        self::assertStringContainsString('hinweis-warnung', $seite);
        self::assertStringContainsString('Netto und Steuern ergeben nicht den Bruttobetrag', $seite);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function ungueltig(): iterable
    {
        yield 'no date' => [['datum' => ''], 'datum'];
        yield 'impossible date' => [['datum' => '2026-02-30'], 'datum'];
        yield 'no gross' => [['brutto' => ''], 'brutto'];
        yield 'gross with three decimals' => [['brutto' => '12,345'], 'brutto'];
        yield 'gross as text' => [['brutto' => 'zwölf'], 'brutto'];
        yield 'net as text' => [['netto' => 'x'], 'netto'];
        yield 'tax line without amount' => [['steuer_betrag_1' => ''], 'steuer_betrag_1'];
        yield 'tax line without rate' => [['steuer_satz_1' => ''], 'steuer_satz_1'];
        yield 'tax rate above 100' => [['steuer_satz_1' => '190'], 'steuer_satz_1'];
        yield 'bad currency' => [['waehrung' => 'EURO'], 'waehrung'];
        yield 'no direction' => [['richtung' => ''], 'richtung'];
        yield 'unknown type' => [['belegart' => 'mahnung'], 'belegart'];
        yield 'service period reversed' => [['leistung_von' => '2026-03-31', 'leistung_bis' => '2026-03-01'], 'leistung_bis'];
        yield 'income category on an expense' => [['kategorie' => 'EINNAHME'], 'kategorie'];
        yield 'unknown category' => [['kategorie' => '99999'], 'kategorie'];
        yield 'unknown cost center' => [['kostenstelle' => '99999'], 'kostenstelle'];
        yield 'unknown supplier' => [['lieferant' => '99999'], 'lieferant'];
        yield 'number too long' => [['nummer' => str_repeat('1', Pruefung::NUMMER_MAX + 1)], 'nummer'];
    }

    /**
     * @param array<string, string> $felder
     */
    #[DataProvider('ungueltig')]
    public function testInvalidInputIsRefusedWithTheFieldMarked(array $felder, string $feld): void
    {
        $id = $this->beleg();
        if (($felder['kategorie'] ?? '') === 'EINNAHME') {
            $felder['kategorie'] = (string) $this->einnahmeKategorie;
        }

        $antwort = $this->post('/app/belege/pruefen/' . $id, $this->felder($felder));

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('role="alert" id="pruefen-fehler"', $antwort->body);
        self::assertMatchesRegularExpression('/name="' . $feld . '"[^>]*aria-invalid="true"|' . ($feld === 'richtung' ? 'pruefen-richtung"[^>]*aria-invalid="true"' : 'NEVER') . '/', $antwort->body);
        self::assertSame(0, $this->anzahlBelege());
        self::assertSame(DocumentStatus::BereitZurAuswertung, $this->belegStatus($id));
    }

    public function testADeactivatedCategoryIsRefusedUnlessItIsTheStoredOne(): void
    {
        $id = $this->beleg();
        $this->post('/app/belege/pruefen/' . $id, $this->felder(['aktion' => 'speichern']));
        $this->pdo()->prepare('UPDATE category SET active = 0 WHERE id = ?')->execute([$this->ausgabeKategorie]);

        // Saving unchanged keeps the stored (now inactive) category.
        self::assertSame(302, $this->post('/app/belege/pruefen/' . $id, $this->felder(['aktion' => 'speichern']))->status);

        $anderer = $this->beleg();
        self::assertSame(422, $this->post('/app/belege/pruefen/' . $anderer, $this->felder())->status);
    }

    public function testTheSupplierMustFitTheDirection(): void
    {
        $lieferant = $this->lieferant('Getränke Müller', SupplierRole::Lieferant);
        $zahler = $this->lieferant('Sponsor AG', SupplierRole::Zahler);
        $beide = $this->lieferant('Stadt Musterhausen', SupplierRole::Beide);
        $id = $this->beleg();

        self::assertSame(422, $this->post('/app/belege/pruefen/' . $id, $this->felder(['lieferant' => (string) $zahler]))->status);
        self::assertSame(422, $this->post('/app/belege/pruefen/' . $id, $this->felder([
            'richtung' => 'einnahme', 'kategorie' => (string) $this->einnahmeKategorie, 'lieferant' => (string) $lieferant,
        ]))->status);

        self::assertSame(302, $this->post('/app/belege/pruefen/' . $id, $this->felder(['lieferant' => (string) $beide, 'aktion' => 'speichern']))->status);
        self::assertSame($beide, (int) $this->rechnung($id)['supplier_id']);
        self::assertSame(302, $this->post('/app/belege/pruefen/' . $id, $this->felder([
            'richtung' => 'einnahme', 'kategorie' => (string) $this->einnahmeKategorie, 'lieferant' => (string) $zahler,
        ]))->status);
        self::assertSame($zahler, (int) $this->rechnung($id)['supplier_id']);
        self::assertSame('einnahme', $this->rechnung($id)['direction']);
    }

    public function testTheListOfPartnersIsReadableAndGroupedByRole(): void
    {
        $this->lieferant('Getränke Müller', SupplierRole::Lieferant);
        $this->lieferant('Sponsor AG', SupplierRole::Zahler);
        $id = $this->beleg();

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true)->body;

        self::assertMatchesRegularExpression('/<optgroup label="Lieferanten" data-rolle="lieferant">\s*<option value="\d+">Getränke Müller/', $seite);
        self::assertMatchesRegularExpression('/<optgroup label="Zahler" data-rolle="zahler">\s*<option value="\d+">Sponsor AG/', $seite);
        self::assertMatchesRegularExpression('/<optgroup label="Einnahmen" data-richtung="einnahme">/', $seite);
    }

    // ---------------------------------------------------- inline supplier

    public function testANewSupplierIsCreatedFromTheForm(): void
    {
        $id = $this->beleg();

        $antwort = $this->post('/app/belege/pruefen/' . $id, $this->felder([
            'lieferant_neu_name' => 'Rasen & Co. GmbH',
            'lieferant_neu_iban' => self::IBAN,
            'aktion' => 'speichern',
        ]));

        self::assertSame(302, $antwort->status);
        $supplierId = (int) $this->rechnung($id)['supplier_id'];
        $lieferant = $this->supplierService()->finde($this->tresor, $supplierId);
        self::assertNotNull($lieferant);
        self::assertSame('Rasen & Co. GmbH', $lieferant->data->name);
        self::assertSame([self::IBAN], $lieferant->data->ibans);
        self::assertSame(SupplierRole::Lieferant, $lieferant->role);

        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'supplier', entityId: $supplierId), null, 10);
        self::assertSame([AuditAction::LieferantAngelegt->value], array_map(static fn(AuditEntry $e): string => $e->action, $zeilen));
        self::assertStringContainsString('neue Partner ist angelegt', $this->get('/app/belege/pruefen/' . $id, entsperrt: true)->body);
    }

    public function testIncomeCreatesAPayer(): void
    {
        $id = $this->beleg();

        $this->post('/app/belege/pruefen/' . $id, $this->felder([
            'richtung' => 'einnahme',
            'kategorie' => (string) $this->einnahmeKategorie,
            'lieferant_neu_name' => 'Sponsor AG',
        ]));

        $supplierId = (int) $this->rechnung($id)['supplier_id'];
        self::assertSame(SupplierRole::Zahler, new SupplierRepository($this->pdo())->find($supplierId)?->role);
    }

    public function testChoosingAndCreatingAtOnceIsRefused(): void
    {
        $vorhanden = $this->lieferant('Getränke Müller', SupplierRole::Lieferant);
        $id = $this->beleg();

        $antwort = $this->post('/app/belege/pruefen/' . $id, $this->felder(['lieferant' => (string) $vorhanden, 'lieferant_neu_name' => 'Noch einer']));

        self::assertSame(422, $antwort->status);
        self::assertCount(1, new SupplierRepository($this->pdo())->all());
    }

    public function testADuplicateIbanPointsAtTheExistingSupplierAndWritesNothing(): void
    {
        $vorhanden = $this->lieferant('Getränke Müller', SupplierRole::Lieferant, self::IBAN);
        $id = $this->beleg();

        $antwort = $this->post('/app/belege/pruefen/' . $id, $this->felder(['lieferant_neu_name' => 'Müller Getränke', 'lieferant_neu_iban' => self::IBAN]));

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('href="/app/lieferanten/' . $vorhanden . '"', $antwort->body);
        self::assertSame(0, $this->anzahlBelege());
        self::assertCount(1, new SupplierRepository($this->pdo())->all());
    }

    public function testCreatingASupplierNeedsSupplierManage(): void
    {
        $rolle = $this->rollen->create('Nur prüfen', false, [
            Permission::InboxView->value => PermissionScope::Alle,
            Permission::DocumentEdit->value => PermissionScope::Alle,
        ]);
        $this->rollen->assignToUser($this->userId, [$rolle]);
        $id = $this->beleg();

        self::assertStringNotContainsString('name="lieferant_neu_name"', $this->get('/app/belege/pruefen/' . $id, entsperrt: true)->body);

        $antwort = $this->post('/app/belege/pruefen/' . $id, $this->felder(['lieferant_neu_name' => 'Rasen & Co. GmbH']));
        self::assertSame(422, $antwort->status);
        self::assertSame([], new SupplierRepository($this->pdo())->all());
        self::assertSame(0, $this->anzahlBelege());
    }

    // ------------------------------------------------------ rights, scope

    /**
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
    public function testOnlyDocumentEditCaptures(SystemRole $rolle, bool $darf): void
    {
        $id = $this->beleg();
        $this->alsRolle($rolle);

        self::assertSame($darf ? 200 : 403, $this->get('/app/belege/pruefen/' . $id, entsperrt: true)->status);
        self::assertSame($darf ? 302 : 403, $this->post('/app/belege/pruefen/' . $id, $this->felder())->status);
        self::assertSame($darf ? 1 : 0, $this->anzahlBelege());
    }

    public function testThePeriodScopeOfDocumentEditHidesOlderDocuments(): void
    {
        $alt = $this->beleg(erstellt: new \DateTimeImmutable('2025-06-01 10:00'));
        $neu = $this->beleg();
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2026-01-01'), null);

        $liste = $this->get('/app/belege/pruefen', entsperrt: true)->body;
        self::assertStringContainsString('/app/belege/pruefen/' . $neu . '"', $liste);
        self::assertStringNotContainsString('/app/belege/pruefen/' . $alt . '"', $liste);

        self::assertSame(404, $this->get('/app/belege/pruefen/' . $alt, entsperrt: true)->status);
        self::assertSame(404, $this->post('/app/belege/pruefen/' . $alt, $this->felder())->status);
        self::assertSame(404, $this->roh('/app/belege/pruefen/' . $alt . '/datei/' . $this->originale($alt)[0], entsperrt: true)->status);
        self::assertSame(0, $this->anzahlBelege());
    }

    // ------------------------------------------------ Festschreibung (M6-4)

    public function testACheckedReceiptIsLockedAndTheLogSaysSo(): void
    {
        $id = $this->gepruefterBeleg();

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true)->body;
        self::assertStringContainsString('action="/app/belege/pruefen/' . $id . '/festschreiben"', $seite);
        self::assertStringNotContainsString('festschreibung-aufheben', $seite);

        $antwort = $this->post('/app/belege/pruefen/' . $id . '/festschreiben', []);

        self::assertSame(302, $antwort->status);
        self::assertSame('/app/belege/pruefen/' . $id, $antwort->headers['Location']);
        self::assertSame(DocumentStatus::Festgeschrieben, $this->belegStatus($id));
        $zeile = $this->rechnung($id);
        self::assertSame($this->userId, (int) $zeile['locked_by']);
        self::assertNotNull($zeile['locked_at']);
        $this->assertSperreStimmt($id);

        $zeilen = $this->auditZeilen($id);
        self::assertSame(AuditAction::BelegFestgeschrieben->value, $zeilen[0]->action);
        self::assertSame($this->userId, $zeilen[0]->userId);
        self::assertNull($this->audit->details($zeilen[0], $this->tresor), 'nothing of the receipt in the entry');

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true)->body;
        self::assertStringContainsString('Beleg #' . $id . ' festgeschrieben.', $seite);
        self::assertStringContainsString('Dieser Beleg ist festgeschrieben (seit ' . date('d.m.Y') . ')', $seite);
        self::assertStringContainsString('<fieldset class="pruefen-felder" disabled>', $seite);
        self::assertStringContainsString('action="/app/belege/pruefen/' . $id . '/festschreibung-aufheben"', $seite);
        self::assertStringNotContainsString('/festschreiben"', $seite);
    }

    public function testOnlyACheckedReceiptCanBeLocked(): void
    {
        $ohneErfassung = $this->beleg();
        $inPruefung = $this->beleg();
        $this->post('/app/belege/pruefen/' . $inPruefung, $this->felder(['aktion' => 'speichern']));

        foreach ([$ohneErfassung => DocumentStatus::BereitZurAuswertung, $inPruefung => DocumentStatus::InPruefung] as $id => $status) {
            $antwort = $this->post('/app/belege/pruefen/' . $id . '/festschreiben', []);

            self::assertSame(422, $antwort->status);
            self::assertStringContainsString('Nur ein geprüfter Beleg', $antwort->body);
            self::assertSame($status, $this->belegStatus($id));
        }
        self::assertNull($this->rechnung($inPruefung)['locked_at']);
        self::assertNotContains(AuditAction::BelegFestgeschrieben->value, $this->auditAktionen($inPruefung));
    }

    public function testLockingTwiceIsRefusedAndLogsOnce(): void
    {
        $id = $this->festgeschriebenerBeleg();
        $vorher = $this->rechnung($id);

        $antwort = $this->post('/app/belege/pruefen/' . $id . '/festschreiben', []);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('bereits festgeschrieben', $antwort->body);
        self::assertSame($vorher['locked_at'], $this->rechnung($id)['locked_at']);
        self::assertSame(1, array_count_values($this->auditAktionen($id))[AuditAction::BelegFestgeschrieben->value]);
    }

    public function testAStaleDocumentIsNotLocked(): void
    {
        $id = $this->gepruefterBeleg();
        $documents = new DocumentRepository($this->pdo());
        $veraltet = $documents->find($id) ?? self::fail();

        // Meanwhile somebody else locks it and lifts the lock again.
        $this->festschreibung()->festschreiben($veraltet, $this->userId, self::IP, new \DateTimeImmutable());
        $this->festschreibung()->aufheben($documents->find($id) ?? self::fail(), 'Test', $this->userId, self::IP, new \DateTimeImmutable());

        // $veraltet still says "geprueft"; the document is in review again.
        try {
            $this->festschreibung()->festschreiben($veraltet, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('a stale document must not be locked');
        } catch (InvoiceRuleViolation $e) {
            self::assertStringContainsString('inzwischen anders bearbeitet', $e->getMessage());
        }
        self::assertSame(DocumentStatus::InPruefung, $this->belegStatus($id));
        $this->assertSperreStimmt($id);
    }

    #[DataProvider('rollen')]
    public function testOnlyDocumentEditLocksAndLiftsTheLock(SystemRole $rolle, bool $darf): void
    {
        $gesperrt = $this->festgeschriebenerBeleg();
        $geprueft = $this->gepruefterBeleg();
        $this->alsRolle($rolle);

        self::assertSame($darf ? 302 : 403, $this->post('/app/belege/pruefen/' . $geprueft . '/festschreiben', [])->status);
        self::assertSame($darf ? DocumentStatus::Festgeschrieben : DocumentStatus::Geprueft, $this->belegStatus($geprueft));

        self::assertSame($darf ? 302 : 403, $this->post('/app/belege/pruefen/' . $gesperrt . '/festschreibung-aufheben', ['grund' => 'Test'])->status);
        self::assertSame($darf ? DocumentStatus::InPruefung : DocumentStatus::Festgeschrieben, $this->belegStatus($gesperrt));
    }

    public function testLockingNeedsTheVaultTheScopeAndCsrf(): void
    {
        $id = $this->gepruefterBeleg();

        // Without the unlocked vault nothing is written.
        $antwort = $this->post('/app/belege/pruefen/' . $id . '/festschreiben', [], entsperrt: false);
        self::assertSame(302, $antwort->status);
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($id));

        $antwort = $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/belege/pruefen/' . $id . '/festschreiben',
            cookies: $this->tresorCookie(),
            post: ['_csrf' => 'falsch'],
        ));
        self::assertSame(302, $antwort->status);
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($id));

        // Outside the period scope the id answers 404, like one that does not exist.
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2099-01-01'), null);
        self::assertSame(404, $this->post('/app/belege/pruefen/' . $id . '/festschreiben', [])->status);
        self::assertSame(404, $this->post('/app/belege/pruefen/999/festschreiben', [])->status);
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($id));
    }

    public function testALockedReceiptCannotBeChangedAnywhere(): void
    {
        $kostenstelle = new CostCenterRepository($this->pdo())->create('Herren');
        $id = $this->festgeschriebenerBeleg();
        $dokument = new DocumentRepository($this->pdo())->find($id) ?? self::fail();
        $vorher = $this->rechnung($id);

        // The review page: refused, nothing written.
        foreach (['speichern', 'geprueft'] as $aktion) {
            $antwort = $this->post('/app/belege/pruefen/' . $id, $this->felder(['brutto' => '1,00', 'kostenstelle' => (string) $kostenstelle, 'aktion' => $aktion]));
            self::assertSame(422, $antwort->status);
            self::assertStringContainsString('festgeschrieben und lässt sich nicht ändern', $antwort->body);
        }
        self::assertSame(123456, $this->pruefung()->beleg($dokument, $this->tresor)?->data->gross);

        // The inbox: no cost center, no decision.
        try {
            $this->posteingang()->kostenstelle($dokument, $kostenstelle, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('the cost center of a locked receipt must not change');
        } catch (InboxRuleViolation) {
        }
        foreach (InboxAction::cases() as $aktion) {
            try {
                $this->posteingang()->entscheiden($aktion, $dokument, $this->tresor, 'Grund', new \DateTimeImmutable('+1 day'), $this->userId, self::IP, new \DateTimeImmutable());
                self::fail($aktion->name . ' must not touch a locked receipt');
            } catch (InboxRuleViolation) {
            }
        }

        self::assertSame($vorher, $this->rechnung($id));
        self::assertNull($this->dokument($id)['cost_center_id']);
        self::assertSame(DocumentStatus::Festgeschrieben, $this->belegStatus($id));
        $this->assertSperreStimmt($id);
    }

    /**
     * The services refuse loudly; the SQL is the second wall for whoever
     * writes next (M6-5, M6-6, M7): a locked row simply does not match.
     */
    public function testTheRepositoriesDoNotTouchALockedReceipt(): void
    {
        $kostenstelle = new CostCenterRepository($this->pdo())->create('Herren');
        $id = $this->festgeschriebenerBeleg();
        $invoices = new InvoiceRepository($this->pdo());
        $documents = new DocumentRepository($this->pdo());
        $record = $invoices->findByDocument($id) ?? self::fail();
        $vorherRechnung = $this->rechnung($id);
        $vorherDokument = $this->dokument($id);
        $spaeter = new \DateTimeImmutable('+1 day');

        $invoices->update($record->id, InvoiceStructure::aus($record), 'anderes Chiffrat', null, null, $spaeter);
        $invoices->setzeGeprueft($record->id, null, $spaeter);
        $invoices->setzeFestgeschrieben($record->id, null, $spaeter);
        $documents->setzeKostenstelle($id, $kostenstelle);
        self::assertFalse($documents->setzePdfBlob($id, $this->originale($id)[0]));

        self::assertSame($vorherRechnung, $this->rechnung($id));
        self::assertSame($vorherDokument, $this->dokument($id));
    }

    public function testLiftingTheLockNeedsAReasonAndSendsTheReceiptBackToReview(): void
    {
        $id = $this->festgeschriebenerBeleg();
        $pfad = '/app/belege/pruefen/' . $id . '/festschreibung-aufheben';

        foreach (['', '   ', str_repeat('x', Festschreibung::GRUND_MAX + 1)] as $grund) {
            $antwort = $this->post($pfad, ['grund' => $grund]);
            self::assertSame(422, $antwort->status);
            self::assertStringContainsString('name="grund"', $antwort->body, 'the page comes back with the form');
            self::assertSame(DocumentStatus::Festgeschrieben, $this->belegStatus($id));
            $this->assertSperreStimmt($id);
        }
        self::assertSame(1, array_count_values($this->auditAktionen($id))[AuditAction::BelegFestgeschrieben->value]);
        self::assertNotContains(AuditAction::BelegFestschreibungAufgehoben->value, $this->auditAktionen($id));

        $antwort = $this->post($pfad, ['grund' => '  Betrag falsch abgetippt ']);

        self::assertSame(302, $antwort->status);
        self::assertSame(DocumentStatus::InPruefung, $this->belegStatus($id));
        $zeile = $this->rechnung($id);
        foreach (['locked_by', 'locked_at', 'checked_by', 'checked_at'] as $spalte) {
            self::assertNull($zeile[$spalte], $spalte);
        }
        $this->assertSperreStimmt($id);

        $zeilen = $this->auditZeilen($id);
        self::assertSame(AuditAction::BelegFestschreibungAufgehoben->value, $zeilen[0]->action);
        self::assertSame(['grund' => 'Betrag falsch abgetippt'], $this->audit->details($zeilen[0], $this->tresor));
        self::assertStringNotContainsString('Betrag falsch', $this->rohTabelle('audit_log'), 'the reason is sealed to the vault');
        self::assertTrue($this->audit->pruefeAbschnitt(0)->intakt());
        self::assertStringContainsString(
            'Die Festschreibung von Beleg #' . $id . ' ist aufgehoben',
            $this->get('/app/belege/pruefen/' . $id, entsperrt: true)->body,
        );
    }

    public function testACorrectedReceiptIsCheckedAndLockedAgain(): void
    {
        $id = $this->festgeschriebenerBeleg();
        $this->post('/app/belege/pruefen/' . $id . '/festschreibung-aufheben', ['grund' => 'Betrag falsch']);

        // Back in the review: in the queue, editable, as before.
        self::assertStringContainsString('/app/belege/pruefen/' . $id . '"', $this->get('/app/belege/pruefen', entsperrt: true)->body);
        self::assertSame(302, $this->post('/app/belege/pruefen/' . $id, $this->felder(['brutto' => '99,90', 'netto' => '', 'steuer_satz_1' => '', 'steuer_betrag_1' => '']))->status);
        self::assertSame(9990, $this->pruefung()->beleg(new DocumentRepository($this->pdo())->find($id) ?? self::fail(), $this->tresor)?->data->gross);
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($id));

        self::assertSame(302, $this->post('/app/belege/pruefen/' . $id . '/festschreiben', [])->status);
        self::assertSame(DocumentStatus::Festgeschrieben, $this->belegStatus($id));
        $this->assertSperreStimmt($id);
        self::assertSame(
            [AuditAction::BelegFestgeschrieben->value, AuditAction::BelegGeprueft->value, AuditAction::BelegBearbeitet->value, AuditAction::BelegFestschreibungAufgehoben->value, AuditAction::BelegFestgeschrieben->value],
            array_slice($this->auditAktionen($id), 0, 5),
        );
    }

    public function testOnlyALockedReceiptCanBeUnlocked(): void
    {
        $id = $this->gepruefterBeleg();

        $antwort = $this->post('/app/belege/pruefen/' . $id . '/festschreibung-aufheben', ['grund' => 'Test']);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('nicht festgeschrieben', $antwort->body);
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($id));
    }

    public function testTheListOffersTheCheckedReceiptsForLocking(): void
    {
        $leer = $this->get('/app/belege/pruefen', entsperrt: true)->body;
        self::assertStringContainsString('Keine geprüften Belege', $leer);

        $id = $this->gepruefterBeleg();
        $liste = $this->get('/app/belege/pruefen', entsperrt: true)->body;
        self::assertStringContainsString('Geprüft – bereit zum Festschreiben', $liste);
        self::assertStringContainsString('href="/app/belege/pruefen/' . $id . '"', $liste);

        $this->post('/app/belege/pruefen/' . $id . '/festschreiben', []);
        self::assertStringContainsString('Keine geprüften Belege', $this->get('/app/belege/pruefen', entsperrt: true)->body);
    }

    // ------------------------------------- merged suppliers (M6-5, #39)

    /**
     * Merging (issue #39) leaves a locked receipt untouched - it keeps the
     * merged supplier - but the review page shows the one it was merged
     * into, and once the lock is lifted, saving moves the receipt there.
     */
    public function testALockedReceiptOfAMergedSupplierShowsAndLaterTakesTheTarget(): void
    {
        $doppelt = $this->lieferant('Getränke Müller', SupplierRole::Lieferant);
        $ziel = $this->lieferant('Getränke Müller GmbH', SupplierRole::Lieferant);
        $offen = $this->beleg();
        $this->post('/app/belege/pruefen/' . $offen, $this->felder(['lieferant' => (string) $doppelt, 'aktion' => 'speichern']));
        $fest = $this->beleg();
        $this->post('/app/belege/pruefen/' . $fest, $this->felder(['lieferant' => (string) $doppelt]));
        self::assertSame(302, $this->post('/app/belege/pruefen/' . $fest . '/festschreiben', [])->status);
        $vorher = $this->rechnung($fest);

        $pdo = $this->pdo();
        $ergebnis = new SupplierZusammenfuehrung($pdo, new SupplierRepository($pdo), new InvoiceRepository($pdo), $this->supplierService(), $this->audit)
            ->ausfuehren($this->tresor, $doppelt, $ziel, $this->userId, self::IP, new \DateTimeImmutable());

        self::assertSame(1, $ergebnis->umgehaengt);
        self::assertSame(1, $ergebnis->festgeschrieben);
        self::assertSame($ziel, (int) $this->rechnung($offen)['supplier_id']);
        self::assertSame($vorher, $this->rechnung($fest), 'the locked receipt is not touched at all');
        $this->assertSperreStimmt($fest);

        $seite = $this->get('/app/belege/pruefen/' . $fest, entsperrt: true)->body;
        self::assertStringContainsString('<option value="' . $ziel . '" selected>Getränke Müller GmbH</option>', $seite);
        self::assertStringNotContainsString('>Getränke Müller</option>', $seite, 'the merged supplier is no choice any more');

        $this->post('/app/belege/pruefen/' . $fest . '/festschreibung-aufheben', ['grund' => 'Korrektur']);
        $this->post('/app/belege/pruefen/' . $fest, $this->felder(['lieferant' => (string) $ziel]));
        self::assertSame($ziel, (int) $this->rechnung($fest)['supplier_id']);
    }

    // ------------------------------------------ e-invoice (M7-4, #46)

    public function testAnEInvoiceFillsTheFormWithoutSavingAnything(): void
    {
        $id = $this->beleg(seiten: [[self::eRechnung('xrechnung-cii.xml'), MagicBytes::XML]]);
        $this->eRechnungLesen();

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true);

        self::assertSame(200, $seite->status);
        self::assertMatchesRegularExpression('/Die Angaben stammen aus der E-Rechnung \(CII \(ZUGFeRD\/Factur-X\/XRechnung\)\)\s+und wurden ohne KI übernommen\./', $seite->body);
        foreach ([
            'datum' => '2026-09-15', 'nummer' => 'RE-2026-0042', 'brutto' => '172,50', 'netto' => '150,00', 'waehrung' => 'EUR',
            'steuer_satz_1' => '19', 'steuer_betrag_1' => '19,00', 'steuer_satz_2' => '7', 'steuer_betrag_2' => '3,50',
            'faellig' => '2026-10-15', 'leistung_von' => '2026-09-01', 'leistung_bis' => '2026-09-30',
            'lieferant_neu_name' => 'Muster Sportartikel GmbH', 'lieferant_neu_iban' => 'DE02120300000000202051',
        ] as $feld => $wert) {
            self::assertMatchesRegularExpression('/name="' . $feld . '"[^>]*value="' . preg_quote($wert, '/') . '"/', $seite->body, $feld);
        }
        self::assertMatchesRegularExpression('/name="richtung" value="ausgabe" checked/', $seite->body);
        self::assertSame(0, $this->anzahlBelege(), 'a suggestion until a person saves it');
        self::assertSame(DocumentStatus::BereitZurAuswertung, $this->belegStatus($id));

        // The XML itself is a download, never an image or shown inline.
        $blob = $this->originale($id)[0];
        self::assertStringNotContainsString('<img src="/app/belege/pruefen/' . $id . '/datei/' . $blob . '"', $seite->body);
        self::assertStringContainsString('href="/app/belege/pruefen/' . $id . '/datei/' . $blob . '"', $seite->body);
        $datei = $this->roh('/app/belege/pruefen/' . $id . '/datei/' . $blob, entsperrt: true);
        self::assertInstanceOf(StreamResponse::class, $datei);
        self::assertStringStartsWith('attachment; filename="', $datei->headers['Content-Disposition']);
        self::assertSame('application/xml', $datei->headers['Content-Type']);
        self::assertSame('nosniff', $datei->headers['X-Content-Type-Options']);
    }

    public function testTheTakenOverFormSavesLikeAnyOther(): void
    {
        $id = $this->beleg(seiten: [[self::eRechnung('xrechnung-ubl.xml'), MagicBytes::XML]]);
        $this->eRechnungLesen();
        $lieferant = $this->lieferant('Hallenservice Beispiel', SupplierRole::Lieferant, 'DE02500105170137075030');

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true);
        self::assertMatchesRegularExpression('/<option value="' . $lieferant . '" selected>/', $seite->body, 'found by its IBAN');
        self::assertDoesNotMatchRegularExpression('/name="lieferant_neu_name"[^>]*value="[^"]+"/', $seite->body);

        $antwort = $this->post('/app/belege/pruefen/' . $id, $this->felder([
            'datum' => '2026-09-20', 'nummer' => '2026/1234', 'brutto' => '297,50', 'netto' => '250,00',
            'steuer_satz_1' => '19', 'steuer_betrag_1' => '47,50', 'lieferant' => (string) $lieferant,
            'kategorie' => (string) $this->ausgabeKategorie, 'aktion' => 'geprueft',
        ]));
        self::assertSame(302, $antwort->status);
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($id));

        // Once saved, the receipt is what the form shows - no more hint.
        $danach = $this->get('/app/belege/pruefen/' . $id, entsperrt: true);
        self::assertStringNotContainsString('aus der E-Rechnung', $danach->body);
    }

    /** Step 1 of the resolution: the VAT id - but only a supplier of the direction's role. */
    public function testTheSupplierIsFoundByItsIdsOfTheRightRole(): void
    {
        $zahler = $this->supplierService()->anlegen($this->tresor, ['name' => 'Muster als Zahler', 'rolle' => SupplierRole::Zahler->value, 'vat_id' => 'DE 123 456 789'], new \DateTimeImmutable())->id;
        $id = $this->beleg(seiten: [[self::eRechnung('xrechnung-cii.xml'), MagicBytes::XML]]);
        $this->eRechnungLesen();

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true);
        self::assertDoesNotMatchRegularExpression('/<option value="' . $zahler . '" selected>/', $seite->body);
        self::assertMatchesRegularExpression('/name="lieferant_neu_name"[^>]*value="Muster Sportartikel GmbH"/', $seite->body);

        new SupplierRepository($this->pdo())->update($zahler, SupplierRole::Beide, null, new SupplierRepository($this->pdo())->find($zahler)?->dataEnc ?? '', new \DateTimeImmutable());
        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true);
        self::assertMatchesRegularExpression('/<option value="' . $zahler . '" selected>/', $seite->body);
    }

    public function testAnEInvoiceThatCouldNotBeReadAsksForManualCapture(): void
    {
        $id = $this->beleg(seiten: [[self::eRechnung('zugferd-1.xml'), MagicBytes::XML]]);
        $this->eRechnungLesen();

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true);

        self::assertMatchesRegularExpression('/Der Beleg enthält eine E-Rechnung, die nicht übernommen werden konnte \(Format nicht unterstützt\)\./', $seite->body);
        self::assertMatchesRegularExpression('/name="nummer"[^>]*value=""/', $seite->body);
    }

    public function testTheWarningsOfTheInvoiceAreShown(): void
    {
        $xml = str_replace('<ram:GrandTotalAmount>172.50</ram:GrandTotalAmount>', '<ram:GrandTotalAmount>180.00</ram:GrandTotalAmount>', self::eRechnung('xrechnung-cii.xml'));
        $id = $this->beleg(seiten: [[$xml, MagicBytes::XML]]);
        $this->eRechnungLesen();

        $seite = $this->get('/app/belege/pruefen/' . $id, entsperrt: true);

        self::assertStringContainsString('<li>Netto und Steuern ergeben laut E-Rechnung nicht den Bruttobetrag.</li>', $seite->body);
        self::assertMatchesRegularExpression('/name="brutto"[^>]*value="180,00"/', $seite->body);
    }

    /** vorbelegen() takes only what fits a field - the AI's answer (M7-5) goes the same way. */
    public function testOnlyValuesThatFitTheirFieldAreTaken(): void
    {
        $pruefung = $this->pruefung();
        $document = new DocumentRepository($this->pdo())->find($this->beleg());
        self::assertNotNull($document);
        $standard = $pruefung->felder($document, null);

        $ergebnis = $pruefung->vorbelegen($standard, [
            'document_type' => 'erfunden', 'direction' => 'einnahme', 'invoice_date' => '2026-02-31', 'invoice_number' => ['kein', 'text'],
            'total_gross' => '1,234', 'currency' => 'euro', 'due_date' => '15.10.2026',
            'taxes' => [['rate' => '19', 'amount' => '1.90'], 'kaputt', ['rate' => '7', 'amount' => '0.70'], ['rate' => '5', 'amount' => '1'], ['rate' => '0', 'amount' => '0']],
            'supplier' => ['name' => 'Neu GmbH', 'iban' => 'DE00 0000'],
            'warnings' => ['Hinweis', 3],
        ], $this->tresor);

        $felder = $ergebnis['felder'];
        self::assertSame('rechnung', $felder['belegart'], 'unknown type: default kept');
        self::assertSame('einnahme', $felder['richtung']);
        self::assertSame('', $felder['datum']);
        self::assertSame('', $felder['nummer']);
        self::assertSame('', $felder['brutto'], 'ambiguous amount refused');
        self::assertSame('EUR', $felder['waehrung']);
        self::assertSame('', $felder['faellig']);
        self::assertSame(['19', '1,90', '7', '0,70', '5', '1,00'], [$felder['steuer_satz_1'], $felder['steuer_betrag_1'], $felder['steuer_satz_2'], $felder['steuer_betrag_2'], $felder['steuer_satz_3'], $felder['steuer_betrag_3']]);
        self::assertSame('Neu GmbH', $felder['lieferant_neu_name']);
        self::assertSame('', $felder['lieferant_neu_iban'], 'an invalid IBAN is not offered');
        self::assertSame(['Hinweis', 'Der Beleg nennt 4 Steuersätze, das Formular fasst 3 – bitte prüfen.'], $ergebnis['hinweise']);
    }

    // -------------------------------------------------- service, schema

    /**
     * Two people on the same document: the one who read it before the other
     * saved loses instead of overwriting.
     */
    public function testAStaleDocumentIsNotOverwritten(): void
    {
        $id = $this->beleg();
        $documents = new DocumentRepository($this->pdo());
        $veraltet = $documents->find($id) ?? self::fail();

        $this->pruefung()->speichern($veraltet, $this->tresor, $this->felder(), true, true, $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($id));

        try {
            $this->pruefung()->speichern($veraltet, $this->tresor, $this->felder(['brutto' => '1,00']), false, true, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('a stale document must not be saved');
        } catch (InvoiceRuleViolation $e) {
            self::assertStringContainsString('inzwischen anders bearbeitet', $e->getMessage());
        }
        self::assertSame(123456, $this->pruefung()->beleg($documents->find($id) ?? self::fail(), $this->tresor)?->data->gross);
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($id));
    }

    public function testMasterDataInUseCountsTheReceipts(): void
    {
        $kostenstellen = new CostCenterRepository($this->pdo());
        $kostenstelle = $kostenstellen->create('Vereinsheim');
        $lieferant = $this->lieferant('Getränke Müller', SupplierRole::Lieferant);
        $id = $this->beleg();
        $this->post('/app/belege/pruefen/' . $id, $this->felder(['lieferant' => (string) $lieferant, 'kostenstelle' => (string) $kostenstelle]));

        self::assertSame(1, new CategoryRepository($this->pdo())->usageCount($this->ausgabeKategorie));
        self::assertSame(1, new SupplierRepository($this->pdo())->usageCount($lieferant));
        self::assertSame(2, $kostenstellen->documentCount($kostenstelle), 'the document and its receipt');

        // The foreign keys alone refuse, too.
        $this->pdo()->prepare('UPDATE document SET cost_center_id = NULL WHERE id = ?')->execute([$id]);
        foreach ([['category', $this->ausgabeKategorie], ['supplier', $lieferant], ['cost_center', $kostenstelle]] as [$tabelle, $zeile]) {
            try {
                $this->pdo()->prepare('DELETE FROM ' . $tabelle . ' WHERE id = ?')->execute([$zeile]);
                self::fail($tabelle . ' in use was deleted');
            } catch (\PDOException $e) {
                self::assertSame('23000', $e->getCode(), $tabelle);
            }
        }
    }

    /**
     * Structure in plaintext, everything else sealed: the column list is
     * the promise of docs/spec/02-datenmodell.md "Fachdaten".
     */
    public function testTheInvoiceTableHoldsOnlyStructureInPlaintext(): void
    {
        $spalten = array_map(strval(...), $this->pdo()->query('SHOW COLUMNS FROM invoice')->fetchAll(\PDO::FETCH_COLUMN));

        self::assertSame([
            'id', 'document_id', 'doc_type', 'direction', 'supplier_id', 'invoice_date', 'due_date', 'service_from',
            'service_to', 'category_id', 'sphere', 'cost_center_id', 'checked_by', 'checked_at', 'locked_by', 'locked_at', 'dek_sealed', 'data_enc',
            'number_bi', 'created_by', 'created_at', 'updated_by', 'updated_at',
        ], $spalten);
    }

    // ------------------------------------------------------------ helpers

    /**
     * The valid form of an expense of 1.234,56 € with 19 % tax,
     * "Geprüft, nächster" unless $abweichend says otherwise.
     *
     * @param array<string, string> $abweichend
     *
     * @return array<string, string>
     */
    private function felder(array $abweichend = []): array
    {
        return [
            ...array_fill_keys(Pruefung::feldnamen(), ''),
            'richtung' => 'ausgabe',
            'belegart' => 'rechnung',
            'datum' => '2026-03-14',
            'nummer' => self::NUMMER,
            'brutto' => '1.234,56',
            'waehrung' => 'EUR',
            'netto' => '1.037,45',
            'steuer_satz_1' => '19',
            'steuer_betrag_1' => '197,11',
            'kategorie' => (string) $this->ausgabeKategorie,
            'zweck' => 'Rasendünger',
            'notiz' => 'Frühjahr',
            'aktion' => 'geprueft',
            ...$abweichend,
        ];
    }

    /**
     * @param list<array{string, string}>|null $seiten content and type
     */
    private function beleg(
        DocumentStatus $status = DocumentStatus::BereitZurAuswertung,
        ?int $kostenstelle = null,
        ?\DateTimeImmutable $erstellt = null,
        ?array $seiten = null,
    ): int {
        $seiten ??= [[FakeJpeg::bauen(10, 10), MagicBytes::JPEG]];
        $blobIds = [];
        foreach ($seiten as [$inhalt, $mime]) {
            $blobIds[] = $this->blobService()->storeString($inhalt, new BlobMeta($mime, 'original.bin'), $this->tresor, BlobStorage::Fs)->id;
        }
        ++$this->laufnummer;

        return new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            $blobIds,
            $this->tresor->sealDataKey(DataKey::generate()),
            ($erstellt ?? new \DateTimeImmutable())->modify('+' . $this->laufnummer . ' seconds'),
            $status,
            costCenterId: $kostenstelle,
            createdBy: $this->userId,
        );
    }

    /** Runs `extract_text` for every queued document, as a signed-in session would. */
    private function eRechnungLesen(): void
    {
        $runner = new JobRunner(new JobRepository($this->pdo()), [
            new Texterkennung(new DocumentRepository($this->pdo()), new DocumentArtifactRepository($this->pdo()), $this->blobService()),
        ]);
        $jobs = new JobRepository($this->pdo());
        foreach ($this->pdo()->query('SELECT id FROM document')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $jobs->enqueue(Texterkennung::JOB_TYP, JobExecutor::Session, 'document', (int) $id);
        }
        $berechtigungen = new UserAccessRepository($this->pdo())->berechtigungen($this->userId);
        for ($i = 0; $i < 50 && $runner->schritt($berechtigungen, $this->tresor, new \DateTimeImmutable())->status === JobLaufStatus::Gearbeitet; $i++) {
        }
    }

    private static function eRechnung(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/erechnung/' . $name);
    }

    private function lieferant(string $name, SupplierRole $rolle, string $iban = ''): int
    {
        return $this->supplierService()->anlegen($this->tresor, ['name' => $name, 'rolle' => $rolle->value, 'ibans' => $iban], new \DateTimeImmutable())->id;
    }

    private function kategorie(string $name): int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM category WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    private function supplierService(): SupplierService
    {
        return new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
    }

    private function pruefung(): Pruefung
    {
        $pdo = $this->pdo();
        $documents = new DocumentRepository($pdo);
        $kostenstellen = new CostCenterRepository($pdo);

        return new Pruefung(
            $pdo,
            $documents,
            new InvoiceRepository($pdo),
            new CategoryRepository($pdo),
            $kostenstellen,
            new SupplierRepository($pdo),
            $this->supplierService(),
            new DocumentArtifactRepository($pdo),
            new Texterkennung($documents, new DocumentArtifactRepository($pdo), $this->blobService()),
            new Posteingang($documents, $kostenstellen, $this->blobService(), $this->audit),
            $this->audit,
        );
    }

    private function festschreibung(): Festschreibung
    {
        $pdo = $this->pdo();

        return new Festschreibung($pdo, new DocumentRepository($pdo), new InvoiceRepository($pdo), $this->audit);
    }

    private function posteingang(): Posteingang
    {
        $pdo = $this->pdo();

        return new Posteingang(new DocumentRepository($pdo), new CostCenterRepository($pdo), $this->blobService(), $this->audit);
    }

    /**
     * A receipt captured and marked as checked ("Geprüft, nächster").
     */
    private function gepruefterBeleg(): int
    {
        $id = $this->beleg();
        $this->post('/app/belege/pruefen/' . $id, $this->felder());
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($id));

        return $id;
    }

    private function festgeschriebenerBeleg(): int
    {
        $id = $this->gepruefterBeleg();
        self::assertSame(302, $this->post('/app/belege/pruefen/' . $id . '/festschreiben', [])->status);
        self::assertSame(DocumentStatus::Festgeschrieben, $this->belegStatus($id));

        return $id;
    }

    /**
     * The lock is written twice - the status and `invoice.locked_at` - and
     * the two must always agree.
     */
    private function assertSperreStimmt(int $documentId): void
    {
        self::assertSame(
            $this->belegStatus($documentId) === DocumentStatus::Festgeschrieben,
            $this->rechnung($documentId)['locked_at'] !== null,
            'status festgeschrieben <=> invoice.locked_at set',
        );
    }

    /**
     * @return list<string> the actions of the document's audit rows, newest first
     */
    private function auditAktionen(int $documentId): array
    {
        return array_map(static fn(AuditEntry $e): string => $e->action, $this->auditZeilen($documentId));
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
        return DocumentStatus::from((string) $this->dokument($id)['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dokument(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM document WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    /**
     * @return array<string, mixed>
     */
    private function rechnung(int $documentId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM invoice WHERE document_id = ?');
        $stmt->execute([$documentId]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    private function anzahlBelege(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM invoice')->fetchColumn();
    }

    /**
     * @return list<int>
     */
    private function originale(int $id): array
    {
        return array_map(intval(...), json_decode((string) $this->dokument($id)['original_blob_ids'], true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<AuditEntry> the document's audit rows, newest first
     */
    private function auditZeilen(int $documentId): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'document', entityId: $documentId), null, 20);
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

    private function get(string $pfad, bool $entsperrt = false): Response
    {
        $antwort = $this->roh($pfad, $entsperrt);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private function roh(string $pfad, bool $entsperrt = false): ResponseInterface
    {
        return $this->kernel()->handle(new Request(HttpMethod::Get, $pfad, cookies: $entsperrt ? $this->tresorCookie() : []));
    }

    /**
     * @param array<string, string> $felder
     */
    private function post(string $pfad, array $felder, bool $entsperrt = true): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            $pfad,
            cookies: $entsperrt ? $this->tresorCookie() : [],
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
            ip: self::IP,
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
        $pruefung = fn(): PruefungController => new PruefungController(
            $view,
            new Session(),
            new SessionVault(),
            $this->pruefung(),
            $this->festschreibung(),
            new CategoryRepository($pdo),
            new CostCenterRepository($pdo),
            new Duplikatpruefung($pdo, new DocumentRepository($pdo), new DocumentDuplicateRepository($pdo), new InvoiceRepository($pdo), $this->audit),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the review page 27th.
        $controller = array_fill(0, 34, $unerreichbar);
        $controller[1] = $guard;
        $controller[26] = $pruefung;

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')($router, $view, ...$controller);

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
