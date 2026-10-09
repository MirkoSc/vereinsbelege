<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\InboxController;
use App\App\PruefungController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\JobExecutor;
use App\Domain\SupplierRole;
use App\Domain\SystemRole;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
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
use App\Repository\SubmissionRepository;
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
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\Duplikatindex;
use App\Service\Document\Duplikatpruefung;
use App\Service\Document\Texterkennung;
use App\Service\Inbox\Posteingang;
use App\Service\Invoice\Festschreibung;
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
 * Duplicate detection end to end (issue #40/M6-6, docs/spec/02-datenmodell.md
 * "Statusmodell" - `duplikat_verdacht` -, docs/spec/03-erfassung-und-ki.md
 * "Pflicht-Tests": Duplikaterkennung): the real route table, guard,
 * controllers, services, repositories and schema. Both signals - the same
 * original files, the same supplier and invoice number -, the marks in the
 * inbox and on the review page, and both resolutions.
 */
final class DuplikatFlowTest extends DatabaseTestCase
{
    private const string IP = '198.51.100.40';

    private const string NUMMER = 'RE-2026-0815';

    private ServerCrypto $crypto;
    private RoleRepository $rollen;
    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private string $blobDir;
    private int $ausgabeKategorie;
    private int $laufnummer = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_duplikat_blobs_' . uniqid('', true);
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

        $stmt = $this->pdo()->prepare('SELECT id FROM category WHERE name = ?');
        $stmt->execute(['Platzpflege & Grünanlagen']);
        $this->ausgabeKategorie = (int) $stmt->fetchColumn();

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        // FsBlobBackend: <blobDir>/<two characters>/<name>.
        foreach (glob($this->blobDir . '/*/*') ?: [] as $datei) {
            unlink($datei);
        }
        foreach (glob($this->blobDir . '/*') ?: [] as $eintrag) {
            is_dir($eintrag) ? rmdir($eintrag) : unlink($eintrag);
        }
        rmdir($this->blobDir);
        parent::tearDown();
    }

    // ------------------------------------------------------- same content

    public function testTheSameFilesSubmittedTwiceAreMarkedInTheInbox(): void
    {
        $erste = $this->einreichung(['Kassenbon Sportshop']);
        $zweite = $this->einreichung(['Kassenbon Sportshop']);
        $andere = $this->einreichung(['Rechnung Platzwart']);

        // Before a session has computed the content index, nothing is known.
        self::assertStringNotContainsString('Duplikat?', $this->get('/app/posteingang', entsperrt: true)->body);

        $this->indexieren();

        $liste = $this->get('/app/posteingang', entsperrt: true)->body;
        self::assertSame(2, substr_count($liste, '>Duplikat?</span>'));
        self::assertMatchesRegularExpression('#R-2026-0001</a>.*?</td>.*?Duplikat\?#s', $liste);

        $seite = $this->get('/app/posteingang/' . $zweite, entsperrt: true)->body;
        self::assertStringContainsString('Möglicherweise doppelt eingereicht', $seite);
        self::assertStringContainsString('href="/app/posteingang/' . $erste . '">R-2026-0001</a>', $seite);
        self::assertStringContainsString('gleicher Inhalt', $seite);
        self::assertStringContainsString('action="/app/posteingang/' . $zweite . '/duplikat-verwerfen"', $seite);
        self::assertStringContainsString('action="/app/posteingang/' . $zweite . '/duplikat-behalten"', $seite);

        self::assertStringContainsString('href="/app/posteingang/' . $zweite . '">R-2026-0002</a>', $this->get('/app/posteingang/' . $erste, entsperrt: true)->body);
        self::assertStringNotContainsString('id="duplikat-hinweis"', $this->get('/app/posteingang/' . $andere, entsperrt: true)->body);
    }

    /**
     * The mark needs no vault - blind indexes compare in SQL. Resolving
     * does: discarding writes an encrypted reason.
     */
    public function testWithoutTheVaultTheMarkShowsButNothingCanBeResolved(): void
    {
        $this->einreichung(['Kassenbon']);
        $zweite = $this->einreichung(['Kassenbon']);
        $this->indexieren();

        self::assertSame(2, substr_count($this->get('/app/posteingang')->body, '>Duplikat?</span>'));
        $seite = $this->get('/app/posteingang/' . $zweite)->body;
        self::assertStringContainsString('Möglicherweise doppelt eingereicht', $seite);
        self::assertStringNotContainsString('duplikat-verwerfen', $seite);

        $antwort = $this->post('/app/posteingang/' . $zweite . '/duplikat-verwerfen', [], entsperrt: false);
        self::assertSame(302, $antwort->status);
        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($zweite));
        $antwort = $this->post('/app/posteingang/' . $zweite . '/duplikat-behalten', [], entsperrt: false);
        self::assertSame(302, $antwort->status);
        self::assertSame(0, $this->behaltenePaarAnzahl());
    }

    public function testARejectedCounterpartDoesNotCount(): void
    {
        $erste = $this->einreichung(['Kassenbon']);
        $zweite = $this->einreichung(['Kassenbon']);
        $this->indexieren();

        $this->post('/app/posteingang/' . $erste . '/ablehnen', ['grund' => 'Falscher Verein']);
        self::assertSame(DocumentStatus::Abgelehnt, $this->belegStatus($erste));

        self::assertStringNotContainsString('Duplikat?', $this->get('/app/posteingang?ansicht=alle', entsperrt: true)->body);
        self::assertStringNotContainsString('id="duplikat-hinweis"', $this->get('/app/posteingang/' . $zweite, entsperrt: true)->body);
    }

    // ------------------------------------------- same supplier and number

    public function testTheSameSupplierAndNumberAreMarkedOnTheReviewPage(): void
    {
        $lieferant = $this->lieferant('Rasen & Co. GmbH');
        $anderer = $this->lieferant('Platz-Profi KG');
        $erste = $this->beleg(['Foto eins']);
        $zweite = $this->beleg(['Foto zwei']);
        $dritte = $this->beleg(['Foto drei']);
        $this->erfassen($erste, $lieferant, self::NUMMER);
        // Spaces do not make two numbers different (Pruefung::nummerFuerIndex()), case neither.
        $this->erfassen($zweite, $lieferant, 're-2026 - 0815');
        $this->erfassen($dritte, $anderer, self::NUMMER);

        $liste = $this->get('/app/belege/pruefen', entsperrt: true)->body;
        self::assertSame(2, substr_count($liste, '>Duplikat?</span>'));

        $seite = $this->get('/app/belege/pruefen/' . $zweite, entsperrt: true)->body;
        self::assertStringContainsString('Möglicherweise doppelt eingereicht', $seite);
        self::assertStringContainsString('href="/app/posteingang/' . $erste . '"', $seite);
        self::assertStringContainsString('gleicher Lieferant und gleiche Rechnungsnummer', $seite);
        self::assertStringContainsString('action="/app/belege/pruefen/' . $zweite . '/duplikat-verwerfen"', $seite);
        self::assertStringNotContainsString('id="duplikat-hinweis"', $this->get('/app/belege/pruefen/' . $dritte, entsperrt: true)->body);
    }

    public function testBothSignalsAtOnceAreOnePairWithBothReasons(): void
    {
        $lieferant = $this->lieferant('Rasen & Co. GmbH');
        $erste = $this->beleg(['dasselbe Foto']);
        $zweite = $this->beleg(['dasselbe Foto']);
        $this->indexieren();
        $this->erfassen($erste, $lieferant, self::NUMMER);
        $this->erfassen($zweite, $lieferant, self::NUMMER);

        $seite = $this->get('/app/belege/pruefen/' . $zweite, entsperrt: true)->body;
        self::assertSame(1, substr_count($seite, 'href="/app/posteingang/' . $erste . '"'));
        self::assertStringContainsString('gleicher Inhalt und gleicher Lieferant und gleiche Rechnungsnummer', $seite);
    }

    /**
     * "Geprüft, nächster" leaves the page with the notice behind - the
     * message on the next page carries it.
     */
    public function testSavingADuplicateSaysSoInTheMessage(): void
    {
        $lieferant = $this->lieferant('Rasen & Co. GmbH');
        $erste = $this->beleg(['eins']);
        $zweite = $this->beleg(['zwei']);
        $this->erfassen($erste, $lieferant, self::NUMMER);

        $antwort = $this->post('/app/belege/pruefen/' . $zweite, $this->felder($lieferant, self::NUMMER));
        self::assertSame(302, $antwort->status);
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($zweite));

        $folgeseite = $this->get((string) $antwort->headers['Location'], entsperrt: true)->body;
        self::assertStringContainsString('Möglicherweise doppelt eingereicht (gleicher Lieferant und gleiche Rechnungsnummer)', $folgeseite);
    }

    /**
     * A locked receipt keeps the supplier that was merged away (issue #39) -
     * the pair is still found through `merged_into`.
     */
    public function testAMergedSupplierCountsAsTheOneItWasMergedInto(): void
    {
        $doppelt = $this->lieferant('Getränke Müller');
        $ziel = $this->lieferant('Getränke Müller GmbH');
        $fest = $this->beleg(['alter Beleg']);
        $this->erfassen($fest, $doppelt, self::NUMMER, geprueft: true);
        self::assertSame(302, $this->post('/app/belege/pruefen/' . $fest . '/festschreiben', [])->status);
        $pdo = $this->pdo();
        new SupplierZusammenfuehrung($pdo, new SupplierRepository($pdo), new InvoiceRepository($pdo), $this->supplierService(), $this->audit)
            ->ausfuehren($this->tresor, $doppelt, $ziel, $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame($doppelt, (int) $this->rechnung($fest)['supplier_id'], 'the locked receipt keeps it');

        $neu = $this->beleg(['neuer Beleg']);
        $this->erfassen($neu, $ziel, self::NUMMER);

        $seite = $this->get('/app/belege/pruefen/' . $neu, entsperrt: true)->body;
        self::assertStringContainsString('href="/app/posteingang/' . $fest . '"', $seite);
        self::assertStringContainsString('Festgeschrieben', $seite);
    }

    // ------------------------------------------------------------ discard

    public function testDiscardingInTheInboxRejectsWithTheReasonAndClearsTheOtherSide(): void
    {
        $erste = $this->einreichung(['Kassenbon']);
        $zweite = $this->einreichung(['Kassenbon']);
        $this->indexieren();

        $antwort = $this->post('/app/posteingang/' . $zweite . '/duplikat-verwerfen', []);

        self::assertSame(302, $antwort->status);
        self::assertSame('/app/posteingang', $antwort->headers['Location']);
        self::assertStringContainsString('Einreichung R-2026-0002 als Duplikat verworfen.', $this->get('/app/posteingang', entsperrt: true)->body);
        self::assertSame(DocumentStatus::Abgelehnt, $this->belegStatus($zweite));
        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($erste));
        self::assertSame('Als Duplikat verworfen: gleicher Inhalt wie R-2026-0001.', $this->notiz($zweite));

        $seite = $this->get('/app/posteingang/' . $zweite, entsperrt: true)->body;
        self::assertStringContainsString('Grund der Ablehnung', $seite);
        self::assertStringContainsString('Als Duplikat verworfen: gleicher Inhalt wie R-2026-0001.', $seite);
        self::assertStringNotContainsString('id="duplikat-hinweis"', $seite, 'a rejected document carries no suspicion');
        self::assertStringNotContainsString('id="duplikat-hinweis"', $this->get('/app/posteingang/' . $erste, entsperrt: true)->body);

        // The other side cannot be discarded as well - nothing of the pair would be left.
        $this->post('/app/posteingang/' . $erste . '/duplikat-verwerfen', []);
        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($erste));

        $eintrag = $this->auditZeilen($zweite)[0];
        self::assertSame(AuditAction::BelegDuplikatVerworfen->value, $eintrag->action);
        self::assertSame($this->userId, $eintrag->userId);
        self::assertSame(['von' => 'eingegangen', 'andere' => [$erste], 'gruende' => ['inhalt']], $this->audit->details($eintrag, $this->tresor));
        self::assertStringNotContainsString('R-2026-0001', $this->rohTabelle('audit_log'));
    }

    /**
     * @return iterable<string, array{DocumentStatus}>
     */
    public static function offeneStatus(): iterable
    {
        foreach ([
            DocumentStatus::Eingegangen,
            DocumentStatus::Wiedervorlage,
            DocumentStatus::BereitZurAuswertung,
            DocumentStatus::Ausgewertet,
            DocumentStatus::KiFehler,
            DocumentStatus::InPruefung,
        ] as $status) {
            yield $status->value => [$status];
        }
    }

    #[DataProvider('offeneStatus')]
    public function testEveryOpenDocumentCanBeDiscardedFromTheReviewPage(DocumentStatus $status): void
    {
        $this->beleg(['Kassenbon']);
        $zweite = $this->beleg(['Kassenbon'], $status);
        $this->indexieren();

        $antwort = $this->post('/app/belege/pruefen/' . $zweite . '/duplikat-verwerfen', []);

        self::assertSame(302, $antwort->status);
        self::assertSame(DocumentStatus::Abgelehnt, $this->belegStatus($zweite));
        self::assertSame(['von' => $status->value], array_intersect_key($this->audit->details($this->auditZeilen($zweite)[0], $this->tresor) ?? [], ['von' => 0]));
    }

    public function testACheckedReceiptDiscardedIsNoLongerChecked(): void
    {
        $lieferant = $this->lieferant('Rasen & Co. GmbH');
        $erste = $this->beleg(['eins']);
        $zweite = $this->beleg(['zwei']);
        $this->erfassen($erste, $lieferant, self::NUMMER);
        $this->erfassen($zweite, $lieferant, self::NUMMER, geprueft: true);
        self::assertSame(DocumentStatus::Geprueft, $this->belegStatus($zweite));
        self::assertNotNull($this->rechnung($zweite)['checked_at']);
        $warteschlange = $this->get('/app/belege/pruefen', entsperrt: true)->body;
        self::assertMatchesRegularExpression('#Geprüft – bereit zum Festschreiben.*Duplikat\?#s', $warteschlange);

        $antwort = $this->post('/app/belege/pruefen/' . $zweite . '/duplikat-verwerfen', []);

        self::assertSame(302, $antwort->status);
        self::assertSame('/app/belege/pruefen/' . $erste, $antwort->headers['Location'], 'on to the next one in the queue');
        self::assertSame(DocumentStatus::Abgelehnt, $this->belegStatus($zweite));
        $rechnung = $this->rechnung($zweite);
        self::assertNull($rechnung['checked_at']);
        self::assertNull($rechnung['checked_by']);
        // No submission, so no reference number: the id stands in.
        self::assertSame('Als Duplikat verworfen: gleicher Lieferant und gleiche Rechnungsnummer wie Beleg #' . $erste . '.', $this->notiz($zweite));
    }

    public function testDiscardingWithoutSuspicionIsRefused(): void
    {
        $einzeln = $this->einreichung(['Kassenbon']);
        $this->indexieren();

        $antwort = $this->post('/app/posteingang/' . $einzeln . '/duplikat-verwerfen', []);

        self::assertSame(302, $antwort->status);
        self::assertSame('/app/posteingang/' . $einzeln, $antwort->headers['Location']);
        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($einzeln));
        self::assertStringContainsString('kein Duplikat-Verdacht', $this->get('/app/posteingang/' . $einzeln, entsperrt: true)->body);
        self::assertSame([], $this->auditZeilen($einzeln));
    }

    // --------------------------------------------------------------- keep

    public function testKeepingResolvesThePairAndAThirdCopyFlagsAgain(): void
    {
        $erste = $this->einreichung(['Monatsrechnung']);
        $zweite = $this->einreichung(['Monatsrechnung']);
        $this->indexieren();

        $antwort = $this->post('/app/posteingang/' . $zweite . '/duplikat-behalten', []);

        self::assertSame(302, $antwort->status);
        self::assertSame('/app/posteingang/' . $zweite, $antwort->headers['Location']);
        $seite = $this->get('/app/posteingang/' . $zweite, entsperrt: true)->body;
        self::assertStringContainsString('der Duplikat-Verdacht ist aufgelöst', $seite);
        self::assertStringNotContainsString('id="duplikat-hinweis"', $seite);
        self::assertStringNotContainsString('Duplikat?', $this->get('/app/posteingang', entsperrt: true)->body);
        self::assertSame(DocumentStatus::Eingegangen, $this->belegStatus($zweite));
        self::assertSame(
            [['document_low_id' => $erste, 'document_high_id' => $zweite, 'kept_by' => $this->userId]],
            array_map(
                static fn(array $z): array => array_map(intval(...), array_intersect_key($z, array_flip(['document_low_id', 'document_high_id', 'kept_by']))),
                $this->pdo()->query('SELECT * FROM document_duplicate_kept')->fetchAll(),
            ),
        );
        $eintrag = $this->auditZeilen($zweite)[0];
        self::assertSame(AuditAction::BelegDuplikatBehalten->value, $eintrag->action);
        self::assertSame(['andere' => [$erste], 'gruende' => ['inhalt']], $this->audit->details($eintrag, $this->tresor));

        // Keeping the same pair from the other side again changes nothing.
        $this->post('/app/posteingang/' . $erste . '/duplikat-behalten', []);
        self::assertSame(1, $this->behaltenePaarAnzahl());

        $dritte = $this->einreichung(['Monatsrechnung']);
        $this->indexieren();
        self::assertSame(3, substr_count($this->get('/app/posteingang', entsperrt: true)->body, '>Duplikat?</span>'));
        $seite = $this->get('/app/posteingang/' . $dritte, entsperrt: true)->body;
        self::assertStringContainsString('href="/app/posteingang/' . $erste . '"', $seite);
        self::assertStringContainsString('href="/app/posteingang/' . $zweite . '"', $seite);
    }

    // ---------------------------------------------------------- the lock

    /**
     * A locked receipt (docs/spec/01-sicherheit.md section 7) carries no
     * suspicion of its own and is never written - it is still the other
     * side of the new document's pair.
     */
    public function testALockedReceiptIsTheOtherSideButNeverChanged(): void
    {
        $lieferant = $this->lieferant('Rasen & Co. GmbH');
        $fest = $this->beleg(['alt']);
        $this->erfassen($fest, $lieferant, self::NUMMER, geprueft: true);
        self::assertSame(302, $this->post('/app/belege/pruefen/' . $fest . '/festschreiben', [])->status);
        $neu = $this->beleg(['neu']);
        $this->erfassen($neu, $lieferant, self::NUMMER);
        $vorher = [$this->dokument($fest), $this->rechnung($fest)];

        self::assertStringNotContainsString('id="duplikat-hinweis"', $this->get('/app/belege/pruefen/' . $fest, entsperrt: true)->body);
        self::assertStringContainsString('href="/app/posteingang/' . $fest . '"', $this->get('/app/belege/pruefen/' . $neu, entsperrt: true)->body);

        foreach (['/app/belege/pruefen/', '/app/posteingang/'] as $basis) {
            foreach (['duplikat-verwerfen', 'duplikat-behalten'] as $aktion) {
                self::assertSame(302, $this->post($basis . $fest . '/' . $aktion, [])->status);
            }
        }
        self::assertSame($vorher, [$this->dokument($fest), $this->rechnung($fest)]);
        self::assertSame(0, $this->behaltenePaarAnzahl());
        self::assertStringContainsString('festgeschrieben und lässt sich nicht ändern', $this->get('/app/belege/pruefen/' . $fest, entsperrt: true)->body);
    }

    // ------------------------------------------------- rights, scope, CSRF

    public function testResolvingNeedsCsrfTheRightAndTheScope(): void
    {
        $erste = $this->beleg(['Kassenbon'], erstellt: new \DateTimeImmutable('2025-06-01 10:00'));
        $zweite = $this->beleg(['Kassenbon']);
        $this->indexieren();

        foreach (['/app/posteingang/', '/app/belege/pruefen/'] as $basis) {
            $antwort = $this->dispatch(new Request(
                HttpMethod::Post,
                $basis . $zweite . '/duplikat-verwerfen',
                cookies: $this->tresorCookie(),
                post: ['_csrf' => 'falsch'],
                ip: self::IP,
            ));
            self::assertSame(302, $antwort->status);
        }
        self::assertSame(DocumentStatus::BereitZurAuswertung, $this->belegStatus($zweite));

        // Outside the period scope the older one answers 404 - and the newer
        // one only counts it, without a link.
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2026-01-01'), null);
        self::assertSame(404, $this->post('/app/posteingang/' . $erste . '/duplikat-verwerfen', [])->status);
        self::assertSame(404, $this->post('/app/belege/pruefen/' . $erste . '/duplikat-behalten', [])->status);
        $seite = $this->get('/app/belege/pruefen/' . $zweite, entsperrt: true)->body;
        self::assertStringContainsString('einem Beleg außerhalb Ihres Bereichs', $seite);
        self::assertStringNotContainsString('href="/app/posteingang/' . $erste . '"', $seite);
        self::assertSame(DocumentStatus::BereitZurAuswertung, $this->belegStatus($erste));

        // Without `document.edit`: the mark shows, the actions do not exist.
        new UserAccessRepository($this->pdo())->setScope($this->userId, null, null);
        $this->alsRolle(SystemRole::Vorstand);
        self::assertStringContainsString('Möglicherweise doppelt', $this->get('/app/posteingang/' . $zweite, entsperrt: true)->body);
        self::assertStringNotContainsString('duplikat-verwerfen', $this->get('/app/posteingang/' . $zweite, entsperrt: true)->body);
        self::assertSame(403, $this->post('/app/posteingang/' . $zweite . '/duplikat-verwerfen', [])->status);
        self::assertSame(403, $this->post('/app/belege/pruefen/' . $zweite . '/duplikat-behalten', [])->status);
        self::assertSame(DocumentStatus::BereitZurAuswertung, $this->belegStatus($zweite));
        self::assertSame(0, $this->behaltenePaarAnzahl());
    }

    public function testTheKeptPairsHoldIdsOnly(): void
    {
        $this->einreichung(['Kassenbon']);
        $zweite = $this->einreichung(['Kassenbon']);
        $this->indexieren();
        $this->post('/app/posteingang/' . $zweite . '/duplikat-behalten', []);

        self::assertSame(
            ['document_low_id', 'document_high_id', 'kept_by', 'kept_at'],
            array_column($this->pdo()->query('SHOW COLUMNS FROM document_duplicate_kept')->fetchAll(), 'Field'),
        );
    }

    // ------------------------------------------------------------ helpers

    /**
     * A public submission as App\Service\Submission\SubmissionService writes
     * it - reference number, pages as encrypted blobs - and its content index
     * job.
     *
     * @param list<string> $seiten contents of the original files
     */
    private function einreichung(array $seiten, DocumentStatus $status = DocumentStatus::Eingegangen): int
    {
        $submissions = new SubmissionRepository($this->pdo());
        $erstellt = new \DateTimeImmutable('+' . (++$this->laufnummer) . ' seconds');
        $submissionId = $submissions->insertDraft(random_bytes(32), $erstellt);
        $key = DataKey::generate();
        $submissions->complete(
            $submissionId,
            sprintf('R-2026-%04d', $this->laufnummer),
            $this->tresor->sealDataKey($key),
            FieldCipher::encrypt($key, '{"name":"Max Muster","freitext":"Bon"}', new FieldContext('submission', $submissionId, 'payload_enc')),
        );

        return $this->dokumentAnlegen(DocumentSource::Einreichung, $submissionId, $seiten, $status, $erstellt);
    }

    /**
     * An internally captured document without a submission (no reference
     * number), accepted for review unless $status says otherwise.
     *
     * @param list<string> $seiten
     */
    private function beleg(array $seiten, DocumentStatus $status = DocumentStatus::BereitZurAuswertung, ?\DateTimeImmutable $erstellt = null): int
    {
        $erstellt = ($erstellt ?? new \DateTimeImmutable())->modify('+' . (++$this->laufnummer) . ' seconds');

        return $this->dokumentAnlegen(DocumentSource::Intern, null, $seiten, $status, $erstellt);
    }

    /**
     * @param list<string> $seiten
     */
    private function dokumentAnlegen(DocumentSource $source, ?int $submissionId, array $seiten, DocumentStatus $status, \DateTimeImmutable $erstellt): int
    {
        $blobIds = [];
        foreach ($seiten as $inhalt) {
            $blobIds[] = $this->blobService()->storeString(FakeJpeg::bauen(4, 4) . $inhalt, new BlobMeta(MagicBytes::JPEG, 'original.jpg'), $this->tresor, BlobStorage::Fs)->id;
        }
        $id = new DocumentRepository($this->pdo())->insert(
            $source,
            $submissionId,
            $blobIds,
            $this->tresor->sealDataKey(DataKey::generate()),
            $erstellt,
            $status,
            createdBy: $source === DocumentSource::Intern ? $this->userId : null,
        );
        new JobRepository($this->pdo())->enqueue(Duplikatindex::JOB_TYP, JobExecutor::Session, 'document', $id);

        return $id;
    }

    /**
     * What the browser of a signed-in session does on every page
     * (public/js/jobs.js): run the content index jobs until none is left.
     */
    private function indexieren(): void
    {
        $runner = new JobRunner(new JobRepository($this->pdo()), [new Duplikatindex(new DocumentRepository($this->pdo()), $this->blobService())]);
        $berechtigungen = new UserAccessRepository($this->pdo())->berechtigungen($this->userId);
        for ($i = 0; $i < 100 && $runner->schritt($berechtigungen, $this->tresor, new \DateTimeImmutable())->status === JobLaufStatus::Gearbeitet; $i++) {
        }
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM job WHERE status <> 'fertig'")->fetchColumn());
    }

    /**
     * Captures the receipt on the review page - "Speichern" (in_pruefung)
     * or "Geprüft, nächster".
     */
    private function erfassen(int $id, int $lieferant, string $nummer, bool $geprueft = false): void
    {
        $felder = $this->felder($lieferant, $nummer);
        $felder['aktion'] = $geprueft ? 'geprueft' : 'speichern';
        self::assertSame(302, $this->post('/app/belege/pruefen/' . $id, $felder)->status);
        self::assertSame($geprueft ? DocumentStatus::Geprueft : DocumentStatus::InPruefung, $this->belegStatus($id));
    }

    /**
     * @return array<string, string>
     */
    private function felder(int $lieferant, string $nummer): array
    {
        return [
            ...array_fill_keys(Pruefung::feldnamen(), ''),
            'richtung' => 'ausgabe',
            'belegart' => 'rechnung',
            'datum' => '2026-03-14',
            'nummer' => $nummer,
            'lieferant' => (string) $lieferant,
            'brutto' => '119,00',
            'waehrung' => 'EUR',
            'netto' => '100,00',
            'steuer_satz_1' => '19',
            'steuer_betrag_1' => '19,00',
            'kategorie' => (string) $this->ausgabeKategorie,
            'aktion' => 'geprueft',
        ];
    }

    private function lieferant(string $name): int
    {
        return $this->supplierService()->anlegen($this->tresor, ['name' => $name, 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id;
    }

    private function supplierService(): SupplierService
    {
        return new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
    }

    private function belegStatus(int $id): DocumentStatus
    {
        return DocumentStatus::from((string) $this->dokument($id)['status']);
    }

    private function notiz(int $id): string
    {
        $zeile = $this->dokument($id);

        return FieldCipher::decrypt(
            $this->tresor->openDataKey((string) $zeile['dek_sealed']),
            (string) $zeile['status_note_enc'],
            new FieldContext('document', $id, 'status_note_enc'),
        );
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

    private function behaltenePaarAnzahl(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM document_duplicate_kept')->fetchColumn();
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

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    private function get(string $pfad, bool $entsperrt = false): Response
    {
        [$pfad, $query] = array_pad(explode('?', $pfad, 2), 2, '');
        parse_str($query, $parameter);

        return $this->dispatch(new Request(HttpMethod::Get, $pfad, query: $parameter, cookies: $entsperrt ? $this->tresorCookie() : []));
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
        $documents = new DocumentRepository($pdo);
        $kostenstellen = new CostCenterRepository($pdo);
        $duplikate = new Duplikatpruefung($pdo, $documents, new DocumentDuplicateRepository($pdo), new InvoiceRepository($pdo), $this->audit);

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
            new Posteingang($documents, $kostenstellen, $this->blobService(), $this->audit),
            $kostenstellen,
            $duplikate,
        );
        $pruefung = fn(): PruefungController => new PruefungController(
            $view,
            new Session(),
            new SessionVault(),
            new Pruefung(
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
            ),
            new Festschreibung($pdo, $documents, new InvoiceRepository($pdo), $this->audit),
            new CategoryRepository($pdo),
            $kostenstellen,
            $duplikate,
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the guard
        // second, the inbox 21st, the review page 27th.
        $controller = array_fill(0, 34, $unerreichbar);
        $controller[1] = $guard;
        $controller[20] = $posteingang;
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
