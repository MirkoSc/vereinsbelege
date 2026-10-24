<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\SerienController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\SeriesStatus;
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
use App\Repository\AssignmentRuleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\BlobRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\RecurringSeriesRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Bank\Buchungsregeln;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\Texterkennung;
use App\Service\Inbox\Posteingang;
use App\Service\Invoice\Festschreibung;
use App\Service\Invoice\Pruefung;
use App\Service\Invoice\Serien;
use App\Service\Invoice\Serienerkennung;
use App\Service\Job\JobLaufStatus;
use App\Service\Job\JobRunner;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\KiAuslesungFabrik;
use App\Tests\Support\ZuordnungFabrik;
use App\View\View;

/**
 * Recurring invoices (issue #57/M8-1, docs/spec/03-erfassung-und-ki.md
 * section 9): receipts marked "Geprüft" on the real review service queue
 * `detect_recurring`, the real job runner turns three of them into a
 * suggestion, the pages confirm, change and dismiss it, a confirmed series
 * takes the next receipt. Amounts and names stay encrypted; locked
 * receipts are not touched. Synthetic data only.
 */
final class SerienFlowTest extends DatabaseTestCase
{
    private Vault $tresor;
    private ServerCrypto $crypto;
    private AuditLog $audit;
    private int $userId;
    private int $miete;
    private string $blobDir;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_serien_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $this->crypto);

        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $rollen = new RoleRepository($this->pdo());
        $finanzen = $rollen->findSystem(SystemRole::Finanzen)?->id;
        assert($finanzen !== null);
        $rollen->assignToUser($this->userId, [$finanzen]);

        $this->miete = $this->lieferant('Hallenvermietung Beispiel GmbH');

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach (glob($this->blobDir . '/*/*') ?: [] as $datei) {
            unlink($datei);
        }
        foreach (glob($this->blobDir . '/*') ?: [] as $eintrag) {
            is_dir($eintrag) ? rmdir($eintrag) : unlink($eintrag);
        }
        rmdir($this->blobDir);
        parent::tearDown();
    }

    // ------------------------------------------------------- detection

    public function testThreeCheckedMonthlyReceiptsBecomeASuggestion(): void
    {
        $this->geprueft('2026-01-02', '450,00');
        $this->geprueft('2026-02-01', '450,00');
        self::assertSame(2, $this->anzahlJobs(), '"Geprüft" queues the detection');
        $this->allesAbarbeiten();
        self::assertSame([], $this->serien(), 'two receipts are no series');

        $this->geprueft('2026-03-02', '450,00');
        $this->allesAbarbeiten();

        $serien = $this->serien();
        self::assertCount(1, $serien);
        self::assertSame(SeriesStatus::Vorgeschlagen, $serien[0]->status);
        self::assertSame('monat', $serien[0]->period->value);
        self::assertSame('2026-04-02', $serien[0]->nextExpected?->format('Y-m-d'));
        self::assertSame(7, $serien[0]->toleranceDays);
        self::assertSame($this->miete, $serien[0]->supplierId);
        self::assertCount(3, $this->mitglieder($serien[0]->id));

        // Encrypted: neither the amount nor the name in the table, the job or the log.
        $roh = $this->roh('recurring_series') . $this->roh('recurring_series_invoice') . $this->roh('job') . $this->roh('audit_log');
        foreach (['45000', '450,00', 'Hallenvermietung'] as $klartext) {
            self::assertStringNotContainsString($klartext, $roh);
        }
        self::assertSame([], $this->aktionen('serie.'), 'a suggestion decides nothing');
    }

    public function testASecondRunUpdatesTheSuggestionInsteadOfAddingOne(): void
    {
        $this->monatlich(3);
        $this->allesAbarbeiten();
        $this->geprueft('2026-04-01', '460,00');
        $this->allesAbarbeiten();

        $serien = $this->serien();
        self::assertCount(1, $serien);
        self::assertCount(4, $this->mitglieder($serien[0]->id));
        self::assertSame('2026-05-01', $serien[0]->nextExpected?->format('Y-m-d'));
        self::assertStringContainsString('460,00', $this->get('/app/serien/' . $serien[0]->id)->body, 'the newest amount');
    }

    public function testLockedReceiptsCountAndStayUntouched(): void
    {
        $dokumente = $this->monatlich(3);
        $festschreibung = new Festschreibung($this->pdo(), new DocumentRepository($this->pdo()), new InvoiceRepository($this->pdo()), $this->audit);
        foreach ($dokumente as $dokument) {
            $festschreibung->festschreiben(new DocumentRepository($this->pdo())->find($dokument) ?? self::fail(), $this->userId, '127.0.0.1', new \DateTimeImmutable());
        }
        $vorher = $this->pdo()->query('SELECT * FROM invoice ORDER BY id')->fetchAll();

        $this->allesAbarbeiten();

        self::assertCount(1, $this->serien());
        self::assertSame($vorher, $this->pdo()->query('SELECT * FROM invoice ORDER BY id')->fetchAll(), 'no invoice row was written');
    }

    public function testASecondSeriesOfTheSameSupplierBecomesASecondSuggestion(): void
    {
        $miete = $this->monatlich(3);
        $this->allesAbarbeiten();
        $this->geprueft('2024-06-10', '120,00');
        $this->geprueft('2025-06-10', '120,00');
        $this->geprueft('2026-06-10', '120,00');
        $this->allesAbarbeiten();

        $serien = $this->serien();
        self::assertCount(2, $serien, 'rent and insurance');
        $perioden = array_map(static fn($s): string => $s->period->value, $serien);
        sort($perioden);
        self::assertSame(['jahr', 'monat'], $perioden);
        $mieteSerie = $serien[0]->period->value === 'monat' ? $serien[0] : $serien[1];
        self::assertSame(array_map($this->rechnungVon(...), $miete), $this->mitglieder($mieteSerie->id));
    }

    public function testLateReceiptsJoiningTwoSuggestionsMergeThem(): void
    {
        foreach (['2024-01-02', '2024-02-01', '2024-03-01'] as $datum) {
            $this->geprueft($datum, '50,00');
        }
        $this->allesAbarbeiten();
        foreach (['2025-01-02', '2025-02-01', '2025-03-03'] as $datum) {
            $this->geprueft($datum, '50,00');
        }
        $this->allesAbarbeiten();
        self::assertCount(2, $this->serien(), 'a gap of ten months makes two suggestions');

        // The missing months arrive late and close the gap.
        foreach (['2024-04-01', '2024-05-02', '2024-06-03', '2024-07-01', '2024-08-01', '2024-09-02', '2024-10-01', '2024-11-04', '2024-12-02'] as $datum) {
            $this->geprueft($datum, '50,00');
        }
        $this->allesAbarbeiten();

        $serien = $this->serien();
        self::assertCount(1, $serien, 'one suggestion, the emptied one is gone');
        self::assertCount(15, $this->mitglieder($serien[0]->id));
    }

    public function testAReceiptMovedToAnotherSupplierLeavesItsSeries(): void
    {
        [, , $dritter] = $this->monatlich(3);
        $this->allesAbarbeiten();
        $serie = $this->serien()[0];
        $andere = $this->lieferant('Versicherung Beispiel AG');

        // Back to review and saved with the other supplier.
        $documents = new DocumentRepository($this->pdo());
        $this->pdo()->prepare("UPDATE document SET status = 'in_pruefung' WHERE id = ?")->execute([$dritter]);
        $felder = [...array_fill_keys(Pruefung::feldnamen(), ''), 'richtung' => 'ausgabe', 'belegart' => 'rechnung', 'datum' => '2026-03-02', 'brutto' => '450,00', 'waehrung' => 'EUR', 'zahlungsweg' => 'konto', 'lieferant' => (string) $andere];
        $this->pruefung()->speichern($documents->find($dritter) ?? self::fail(), $this->tresor, $felder, true, false, $this->userId, '127.0.0.1', new \DateTimeImmutable());
        $this->allesAbarbeiten();

        self::assertNotContains($this->rechnungVon($dritter), $this->mitglieder($serie->id));
    }

    public function testOtherSuppliersAndIncomeAreSeparate(): void
    {
        $andere = $this->lieferant('Versicherung Beispiel AG');
        $this->geprueft('2026-01-02', '450,00');
        $this->geprueft('2026-02-01', '450,00', $andere);
        $this->geprueft('2026-03-02', '450,00');
        $this->allesAbarbeiten();

        self::assertSame([], $this->serien());
    }

    public function testTheToleranceIsASetting(): void
    {
        new SettingRepository($this->pdo())->set(Serienerkennung::SETTING_TOLERANZ, '1');
        $this->geprueft('2026-01-02', '450,00');
        $this->geprueft('2026-02-01', '470,00');
        $this->geprueft('2026-03-02', '490,00');
        $this->allesAbarbeiten();
        self::assertSame([], $this->serien(), '±1 % does not take +4 %');

        new SettingRepository($this->pdo())->set(Serienerkennung::SETTING_TOLERANZ, '15');
        $this->geprueft('2026-04-01', '490,00');
        $this->allesAbarbeiten();
        self::assertCount(4, $this->mitglieder($this->serien()[0]->id));
    }

    public function testTheMigrationQueuesTheNewestReceiptPerSupplier(): void
    {
        $andere = $this->lieferant('Versicherung Beispiel AG');
        $this->geprueft('2026-01-02', '450,00');
        $neuester = $this->geprueft('2026-02-01', '450,00');
        $alt = $this->geprueft('2025-01-01', '99,00', $andere);
        $this->pdo()->exec('DELETE FROM job');

        $sql = (string) file_get_contents($this->migrationsDir() . '/036_recurring_series.sql');
        $this->pdo()->exec(substr($sql, (int) strpos($sql, 'INSERT INTO job')));

        $ids = array_map(intval(...), $this->pdo()->query("SELECT ref_id FROM job WHERE typ = 'detect_recurring' ORDER BY ref_id")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame([$neuester, $alt], $ids);
    }

    // ------------------------------------------------------- pages

    public function testTheListShowsTheSuggestionAndTheFormConfirmsIt(): void
    {
        [$erster] = $this->monatlich(3);
        $this->allesAbarbeiten();
        $serie = $this->serien()[0];

        $liste = $this->get('/app/serien');
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('Hallenvermietung Beispiel GmbH', $liste->body);
        self::assertStringContainsString('1 Vorschlag wartet', $liste->body);
        self::assertStringContainsString('02.04.2026', $liste->body);

        $formular = $this->get('/app/serien/' . $serie->id);
        self::assertSame(200, $formular->status);
        self::assertStringContainsString('Serie bestätigen', $formular->body);
        self::assertStringContainsString('value="450,00"', $formular->body);
        self::assertSame(3, substr_count($formular->body, 'name="belege[]"'));

        // Confirmed without the oldest receipt, with a label.
        $belege = $this->mitglieder($serie->id);
        $ersteRechnung = $this->rechnungVon($erster);
        $antwort = $this->post('/app/serien/' . $serie->id, [
            ...$this->felder(['bezeichnung' => 'Hallenmiete']),
            'belege' => array_map(strval(...), array_values(array_diff($belege, [$ersteRechnung]))),
        ]);
        self::assertSame(302, $antwort->status);
        self::assertSame('/app/serien/' . $serie->id, $antwort->headers['Location'] ?? null);

        $nachher = $this->serien()[0];
        self::assertSame(SeriesStatus::Bestaetigt, $nachher->status);
        self::assertSame($this->userId, $nachher->decidedBy);
        self::assertCount(2, $this->mitglieder($serie->id));
        self::assertSame([['serie.bestaetigt', ['felder' => ['bezeichnung', 'belege']]]], $this->aktionen('serie.'));
        self::assertStringNotContainsString('Hallenmiete', $this->roh('recurring_series') . $this->roh('audit_log'));

        // The freed receipt is offered again, unticked.
        $seite = $this->get('/app/serien/' . $serie->id);
        self::assertStringContainsString('Hallenmiete', $seite->body);
        self::assertStringContainsString('Weitere geprüfte Belege', $seite->body);
        self::assertSame(3, substr_count($seite->body, 'name="belege[]"'));
        self::assertSame(2, substr_count($seite->body, '" checked>'));

        // Saving unchanged writes no audit line.
        $this->post('/app/serien/' . $serie->id, [
            ...$this->felder(['bezeichnung' => 'Hallenmiete']),
            'belege' => array_map(strval(...), $this->mitglieder($serie->id)),
        ]);
        self::assertCount(1, $this->aktionen('serie.'));
    }

    public function testAConfirmedSeriesTakesTheNextReceipt(): void
    {
        $this->monatlich(3);
        $this->allesAbarbeiten();
        $serie = $this->serien()[0];
        $this->post('/app/serien/' . $serie->id, [...$this->felder(), 'belege' => array_map(strval(...), $this->mitglieder($serie->id))]);

        $neu = $this->geprueft('2026-04-03', '470,00');
        $this->allesAbarbeiten();

        $nachher = $this->serien();
        self::assertCount(1, $nachher, 'linked, not a new suggestion');
        self::assertCount(4, $this->mitglieder($serie->id));
        self::assertSame('2026-05-03', $nachher[0]->nextExpected?->format('Y-m-d'));
        self::assertStringContainsString('value="470,00"', $this->get('/app/serien/' . $serie->id)->body);
        self::assertSame(['serie.bestaetigt', 'serie.beleg_verknuepft'], array_column($this->aktionen('serie.'), 0));
        self::assertSame(['beleg' => $neu], $this->aktionen('serie.')[1][1]);

        // A second receipt in the same month is not taken, nor one far off in amount.
        $this->geprueft('2026-04-10', '470,00');
        $this->geprueft('2026-05-02', '900,00');
        $this->allesAbarbeiten();
        self::assertCount(4, $this->mitglieder($serie->id));
    }

    public function testTheOverviewShowsFiguresAndMarksAMissingReceiptOverdue(): void
    {
        $this->monatlich(3);
        $this->allesAbarbeiten();
        $serie = $this->serien()[0];

        // A suggestion has no due date to miss yet.
        $vorschlag = $this->get('/app/serien')->body;
        self::assertStringContainsString('Wiederkehrende Kosten', $vorschlag);
        self::assertStringNotContainsString('marke-fehler', $vorschlag);

        $this->post('/app/serien/' . $serie->id, [...$this->felder(), 'belege' => array_map(strval(...), $this->mitglieder($serie->id))]);
        $neu = $this->geprueft('2026-04-03', '470,00');
        $this->allesAbarbeiten();

        // Next expected 2026-05-03 plus 7 days is long past: overdue.
        $liste = $this->get('/app/serien');
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('<span class="marke marke-fehler">überfällig</span>', $liste->body);
        self::assertStringContainsString('1 Serie ist überfällig', $liste->body);
        self::assertStringContainsString('href="/app/serien/' . $serie->id . '"', $liste->body, 'jump to the series');
        self::assertStringContainsString('href="/app/belege/pruefen/' . $neu . '">Beleg vom 03.04.2026', $liste->body, 'jump to the newest receipt');
        self::assertStringContainsString('470,00 EUR', $liste->body, 'last amount');
        self::assertStringContainsString('5.640,00 EUR', $liste->body, 'yearly projection');
        self::assertStringContainsString('+4,4 %', $liste->body, 'trend since the oldest receipt');
        self::assertStringContainsString('Ausgaben pro Jahr (Hochrechnung):', $liste->body);

        // A series without a fixed rhythm has no due date and no projection.
        $this->post('/app/serien/' . $serie->id, [
            ...$this->felder(['periode' => 'unregelmaessig', 'naechste' => '', 'betrag' => '470,00']),
            'belege' => array_map(strval(...), $this->mitglieder($serie->id)),
        ]);
        $unregelmaessig = $this->get('/app/serien')->body;
        self::assertStringNotContainsString('marke-fehler', $unregelmaessig);
        self::assertStringNotContainsString('pro Jahr (Hochrechnung)', $unregelmaessig);
    }

    public function testADismissedSuggestionIsNotProposedAgain(): void
    {
        $this->monatlich(3);
        $this->allesAbarbeiten();
        $serie = $this->serien()[0];

        $antwort = $this->post('/app/serien/' . $serie->id . '/verwerfen', []);
        self::assertSame(302, $antwort->status);
        self::assertSame(SeriesStatus::Verworfen, new RecurringSeriesRepository($this->pdo())->find($serie->id)?->status);
        self::assertSame([['serie.verworfen', ['von' => 'vorgeschlagen']]], $this->aktionen('serie.'));
        self::assertStringNotContainsString('Hallenvermietung', $this->get('/app/serien')->body, 'gone from the list');

        $this->geprueft('2026-04-01', '450,00');
        $this->geprueft('2026-05-01', '450,00');
        $this->allesAbarbeiten();
        self::assertSame([], $this->serien(), 'two new receipts are too few without the dismissed ones');

        $seite = $this->get('/app/serien/' . $serie->id);
        self::assertStringContainsString('wurde verworfen', $seite->body);
        self::assertStringNotContainsString('Serie bestätigen', $seite->body);
        $this->post('/app/serien/' . $serie->id, [...$this->felder(), 'belege' => array_map(strval(...), $this->mitglieder($serie->id))]);
        self::assertSame(SeriesStatus::Verworfen, new RecurringSeriesRepository($this->pdo())->find($serie->id)?->status, 'a dismissed series is not changed');
    }

    public function testTheFormRejectsBadInputWithoutSaving(): void
    {
        $this->monatlich(3);
        $andere = $this->lieferant('Versicherung Beispiel AG');
        $fremd = $this->rechnungVon($this->geprueft('2026-01-15', '450,00', $andere));
        $this->allesAbarbeiten();
        $serie = $this->serien()[0];
        $belege = array_map(strval(...), $this->mitglieder($serie->id));

        foreach ([
            [['betrag' => '0'], $belege, 'positiven Betrag'],
            [['betrag' => '1,234'], $belege, 'positiven Betrag'],
            [['periode' => 'woche'], $belege, 'Rhythmus'],
            [['naechste' => '2026-02-30'], $belege, 'kein gültiges Datum'],
            [['naechste' => ''], $belege, 'nächste Fälligkeit angeben'],
            [['toleranz' => '500'], $belege, 'Toleranz'],
            [['bezeichnung' => str_repeat('x', 101)], $belege, 'höchstens 100'],
            [[], [], 'mindestens einen Beleg'],
            [[], [...$belege, (string) $fremd], 'gehört schon zu einer anderen'],
        ] as [$felder, $auswahl, $meldung]) {
            $antwort = $this->post('/app/serien/' . $serie->id, [...$this->felder($felder), 'belege' => $auswahl]);
            self::assertSame(422, $antwort->status, $meldung);
            self::assertStringContainsString($meldung, $antwort->body);
        }
        self::assertSame(SeriesStatus::Vorgeschlagen, $this->serien()[0]->status);
        self::assertSame([], $this->aktionen('serie.'));

        // "unregelmäßig" needs no next date.
        $this->post('/app/serien/' . $serie->id, [...$this->felder(['periode' => 'unregelmaessig', 'naechste' => '']), 'belege' => $belege]);
        self::assertNull($this->serien()[0]->nextExpected);
    }

    public function testWithoutVaultOrCsrfNothingHappens(): void
    {
        $this->monatlich(3);
        $this->allesAbarbeiten();
        $serie = $this->serien()[0];

        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $this->get('/app/serien', entsperrt: false)->body);
        self::assertSame(302, $this->get('/app/serien/' . $serie->id, entsperrt: false)->status);
        $this->post('/app/serien/' . $serie->id . '/verwerfen', [], entsperrt: false);
        $this->dispatch(new Request(HttpMethod::Post, '/app/serien/' . $serie->id . '/verwerfen', cookies: $this->tresorCookie(), post: ['_csrf' => 'falsch']));
        self::assertSame(SeriesStatus::Vorgeschlagen, $this->serien()[0]->status);
        self::assertSame(404, $this->get('/app/serien/999999')->status);
    }

    public function testMergingSuppliersMovesTheSeriesAndCountsAsUse(): void
    {
        $this->monatlich(3);
        $this->allesAbarbeiten();
        $ziel = $this->lieferant('Hallenvermietung Beispiel');
        $lieferanten = new SupplierRepository($this->pdo());
        self::assertSame(4, $lieferanten->usageCount($this->miete), 'three receipts and the series');

        $lieferanten->markiereZusammengefuehrt($this->miete, $ziel, new \DateTimeImmutable());

        self::assertSame($ziel, $this->serien()[0]->supplierId);
        // The older receipts still point at the merged supplier where locked - detection resolves it.
        $this->geprueft('2026-04-01', '450,00', $ziel);
        $this->allesAbarbeiten();
        self::assertCount(1, $this->serien());
        self::assertCount(4, $this->mitglieder($this->serien()[0]->id));
    }

    // ------------------------------------------------------- helpers

    /**
     * Monthly receipts of 450,00 from 2 January 2026.
     *
     * @return list<int> document ids
     */
    private function monatlich(int $anzahl): array
    {
        $ids = [];
        foreach (['2026-01-02', '2026-02-01', '2026-03-02', '2026-04-01', '2026-05-04'] as $i => $datum) {
            if ($i < $anzahl) {
                $ids[] = $this->geprueft($datum, '450,00');
            }
        }

        return $ids;
    }

    /** A document captured and marked "Geprüft" on the review service; returns its id. */
    private function geprueft(string $datum, string $brutto, ?int $lieferant = null): int
    {
        $documents = new DocumentRepository($this->pdo());
        $id = $documents->insert(DocumentSource::Intern, null, [], $this->tresor->sealDataKey(DataKey::generate()), new \DateTimeImmutable(), DocumentStatus::InPruefung, createdBy: $this->userId);
        $felder = [
            ...array_fill_keys(Pruefung::feldnamen(), ''),
            'richtung' => 'ausgabe',
            'belegart' => 'rechnung',
            'datum' => $datum,
            'brutto' => $brutto,
            'waehrung' => 'EUR',
            'zahlungsweg' => 'konto',
            'lieferant' => (string) ($lieferant ?? $this->miete),
        ];
        $this->pruefung()->speichern($documents->find($id) ?? self::fail(), $this->tresor, $felder, true, false, $this->userId, '127.0.0.1', new \DateTimeImmutable());

        return $id;
    }

    /**
     * @param array<string, string> $abweichend
     *
     * @return array<string, string>
     */
    private function felder(array $abweichend = []): array
    {
        return [
            'periode' => 'monat',
            'betrag' => '450,00',
            'naechste' => '2026-04-02',
            'toleranz' => '7',
            'bezeichnung' => '',
            'vertrag' => '',
            ...$abweichend,
        ];
    }

    private function allesAbarbeiten(): void
    {
        $berechtigungen = new UserAccessRepository($this->pdo())->berechtigungen($this->userId);
        for ($i = 0; $i < 50; $i++) {
            $lauf = new JobRunner(new JobRepository($this->pdo()), [$this->serienService()])->schritt($berechtigungen, $this->tresor, new \DateTimeImmutable());
            if ($lauf->status !== JobLaufStatus::Gearbeitet) {
                break;
            }
        }
        self::assertSame([], $this->pdo()->query("SELECT typ, last_error FROM job WHERE status = 'fehler'")->fetchAll(), 'no job error');
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM job WHERE status = 'offen'")->fetchColumn(), 'every job done');
    }

    private function anzahlJobs(): int
    {
        return (int) $this->pdo()->query("SELECT COUNT(*) FROM job WHERE typ = 'detect_recurring'")->fetchColumn();
    }

    /**
     * @return list<\App\Domain\RecurringSeriesRecord>
     */
    private function serien(): array
    {
        return new RecurringSeriesRepository($this->pdo())->liste([SeriesStatus::Vorgeschlagen, SeriesStatus::Bestaetigt]);
    }

    /**
     * @return list<int>
     */
    private function mitglieder(int $serie): array
    {
        return new RecurringSeriesRepository($this->pdo())->mitglieder($serie);
    }

    private function rechnungVon(int $documentId): int
    {
        return new InvoiceRepository($this->pdo())->findByDocument($documentId)?->id ?? self::fail('no invoice');
    }

    /**
     * Audit lines whose action starts with $praefix: action and details.
     *
     * @return list<array{0: string, 1: array<mixed>}>
     */
    private function aktionen(string $praefix): array
    {
        $eintraege = [];
        foreach (new AuditLogRepository($this->pdo())->after(0, 1000) as $eintrag) {
            if (str_starts_with($eintrag->action, $praefix)) {
                $eintraege[] = [$eintrag->action, $this->audit->details($eintrag, $this->tresor) ?? []];
            }
        }

        return $eintraege;
    }

    private function roh(string $tabelle): string
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

    private function lieferant(string $name): int
    {
        return $this->supplierService()->anlegen($this->tresor, ['name' => $name, 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id;
    }

    private function supplierService(): SupplierService
    {
        return new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }

    private function serienService(): Serien
    {
        $pdo = $this->pdo();

        return new Serien(
            $pdo,
            new RecurringSeriesRepository($pdo),
            new InvoiceRepository($pdo),
            new DocumentRepository($pdo),
            new SupplierRepository($pdo),
            $this->supplierService(),
            KiAuslesungFabrik::bauen($pdo, $this->blobService(), $this->crypto),
            new SettingRepository($pdo),
            $this->audit,
        );
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
            new Posteingang($documents, $kostenstellen, $this->blobService(), $this->audit, new JobRepository($pdo)),
            $this->audit,
            KiAuslesungFabrik::bauen($pdo, $this->blobService(), $this->crypto),
            new Buchungsregeln($pdo, new AssignmentRuleRepository($pdo), new BankTransactionRepository($pdo), new CategoryRepository($pdo), new SettingRepository($pdo)),
            KiAuslesungFabrik::lieferanten($pdo, $this->blobService(), $this->crypto, $this->audit),
            new JobRepository($pdo),
            ZuordnungFabrik::bauen($pdo, $this->audit),
        );
    }

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $pfad, array $query = [], bool $entsperrt = true): Response
    {
        return $this->dispatch(new Request(HttpMethod::Get, $pfad, query: $query, cookies: $entsperrt ? $this->tresorCookie() : []));
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function post(string $pfad, array $felder, bool $entsperrt = true): Response
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

        $guard = static fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            static function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null ? null : new SessionUser($user, 'Test', new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $serien = fn(): SerienController => new SerienController($view, new Session(), new SessionVault(), $this->serienService());
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        // Every controller closure of app/src/routes.php: $auth, $guard, 36
        // more, then the optional ones by name.
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $unerreichbar,
            $guard,
            ...array_fill(0, 36, $unerreichbar),
            nextcloudAdmin: $unerreichbar,
            serien: $serien,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
