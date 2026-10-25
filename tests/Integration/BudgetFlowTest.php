<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\BudgetController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\BankAccountKind;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSource;
use App\Domain\CategoryDirection;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InvoiceData;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
use App\Domain\Permission;
use App\Domain\PermissionScope;
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
use App\Repository\AuswertungsStandRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\MasterData\CostCenterRuleViolation;
use App\Service\MasterData\CostCenterService;
use App\Service\Migration\Migrator;
use App\Service\Report\AuswertungsCache;
use App\Service\Report\Auswertungsdaten;
use App\Service\Report\Budgets;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;
use App\View\View;

/**
 * Budgets per category and cost center with Soll/Ist (issue #92, E-58)
 * through the real route table, guard, services, schema and vault: keeping
 * budgets (create, change, delete, refused input, CSRF), the amount stored
 * only encrypted and bound to its row, Soll/Ist/Abweichung on both bases,
 * the cost-center scope of a Vereinsverantwortlicher and the period scope of
 * an external auditor, a locked vault, the CSV, the audit log without
 * amounts and the report cache seeing a changed budget. Synthetic data;
 * past years only.
 */
final class BudgetFlowTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.10';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $laufnummer = 0;

    private int $platz;
    private int $reise;
    private int $beitraege;
    private int $jugend;
    private int $herren;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $crypto);
        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $this->rolle(SystemRole::Finanzen);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];

        $this->beispieldaten();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testBudgetsAreStoredEncryptedAndShowSollAgainstIst(): void
    {
        $antwort = $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);
        self::assertSame(302, $antwort->status);
        self::assertSame('/app/auswertung/budget?jahr=2025&grundlage=buchungen', $antwort->headers['Location'] ?? null);
        $this->speichern(['kostenstelle' => (string) $this->jugend, 'betrag' => '200']);
        $this->speichern(['kategorie' => (string) $this->reise, 'kostenstelle' => (string) $this->herren, 'betrag' => '100,00']);

        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025', 'grundlage' => 'belege']);
        self::assertSame(200, $seite->status);
        self::assertSame('no-store, private', $seite->headers['Cache-Control'] ?? null);
        self::assertStringContainsString('Budget angelegt.', $seite->body);
        self::assertStringContainsString('vom 01.01.2025 bis 31.12.2025', $seite->body);

        // Receipt basis: Platzpflege 300 (Jugend) + 50 (no cost center);
        // Jugend 300; Reisekosten on Herren 100. "alle …" sorts first.
        self::assertSame([
            ['alle Kategorien · Testkst Jugend', '200,00 €', '300,00 €', '-100,00 €', '−50,0 %', '150,0 %'],
            ['Testkat Platzpflege · alle Kostenstellen', '500,00 €', '350,00 €', '150,00 €', '30,0 %', '70,0 %'],
            ['Testkat Reisekosten · Testkst Herren', '100,00 €', '100,00 €', '0,00 €', '0,0 %', '100,0 %'],
        ], $this->tabelle($seite->body));
        // Overspent is said in words, not only by the bar.
        self::assertSame(1, substr_count($seite->body, '<span class="marke marke-fehler">überschritten</span>'));
        self::assertStringContainsString('<rect class="balken-wert" width="1000" height="10"/>', $seite->body);
        self::assertStringContainsString('<rect class="balken-wert" width="700" height="10"/>', $seite->body);

        // Only ciphertext and structure in the table.
        $roh = $this->rohTabelle('budget');
        foreach (['50000', '500,00', '20000', '10000', '"amount"'] as $klartext) {
            self::assertStringNotContainsString($klartext, $roh, $klartext . ' is not stored in the clear');
        }
        $zeile = $this->pdo()->query('SELECT year, category_id, cost_center_id FROM budget WHERE category_id = ' . $this->platz)->fetch();
        self::assertSame(['year' => 2025, 'category_id' => $this->platz, 'cost_center_id' => null], [
            'year' => (int) $zeile['year'], 'category_id' => (int) $zeile['category_id'], 'cost_center_id' => $zeile['cost_center_id'],
        ]);

        // The audit log names year and ids, never the amount.
        $eintraege = $this->protokoll(AuditAction::BudgetGespeichert);
        self::assertCount(3, $eintraege);
        foreach ($eintraege as $eintrag) {
            $details = $this->audit->details($eintrag, $this->tresor) ?? [];
            self::assertSame(['jahr', 'kategorie_id', 'kostenstelle_id', 'neu'], array_keys($details));
            self::assertSame(2025, $details['jahr']);
            self::assertTrue($details['neu']);
        }
    }

    public function testTheIstOnTheBookingBasisIsTheDefault(): void
    {
        $this->speichern(['kategorie' => (string) $this->reise, 'betrag' => '100,00']);
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);

        // Bookings: only the 40,00 Reisekosten booking (receipts are not
        // allocated to bookings here).
        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025']);
        self::assertStringContainsString('Grundlage Buchungen (Zahlungsfluss)', $seite->body);
        self::assertSame([
            ['Testkat Platzpflege · alle Kostenstellen', '500,00 €', '0,00 €', '500,00 €', '100,0 %', '0,0 %'],
            ['Testkat Reisekosten · alle Kostenstellen', '100,00 €', '40,00 €', '60,00 €', '60,0 %', '40,0 %'],
        ], $this->tabelle($seite->body));
    }

    public function testSavingTheSamePairAgainChangesItsAmount(): void
    {
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);
        $schluesselVorher = $this->pdo()->query('SELECT dek_sealed FROM budget')->fetchColumn();
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '1.234,56']);

        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());
        self::assertNotSame($schluesselVorher, $this->pdo()->query('SELECT dek_sealed FROM budget')->fetchColumn(), 'a fresh key for the new amount');
        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025', 'grundlage' => 'belege']);
        self::assertStringContainsString('Budget geändert.', $seite->body);
        self::assertSame([['Testkat Platzpflege · alle Kostenstellen', '1.234,56 €', '350,00 €', '884,56 €', '71,6 %', '28,4 %']], $this->tabelle($seite->body));

        // The same category in another year is a budget of its own.
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '10,00'], '2024');
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());
        self::assertFalse($this->protokoll(AuditAction::BudgetGespeichert)[0]->detailsEnc === null);
    }

    public function testInvalidInputIsRefusedAndNothingIsStored(): void
    {
        $faelle = [
            [['betrag' => '100'], 'Bitte eine Kategorie, eine Kostenstelle oder beides wählen.'],
            [['kategorie' => (string) $this->platz, 'betrag' => ''], 'Bitte einen Betrag in Euro bis 999.999.999,99 angeben'],
            [['kategorie' => (string) $this->platz, 'betrag' => 'viel'], 'Bitte einen Betrag in Euro bis 999.999.999,99 angeben'],
            [['kategorie' => (string) $this->platz, 'betrag' => '-5,00'], 'Ein Budget kann nicht negativ sein.'],
            [['kategorie' => (string) $this->platz, 'betrag' => '1.000.000.000,00'], 'Bitte einen Betrag in Euro bis 999.999.999,99 angeben'],
            [['kategorie' => (string) $this->beitraege, 'betrag' => '100'], 'reine Einnahmen-Kategorie'],
            [['kategorie' => '999999', 'betrag' => '100'], 'Diese Kategorie gibt es nicht'],
            [['kategorie' => '1 OR 1=1', 'betrag' => '100'], 'Diese Kategorie gibt es nicht'],
            [['kostenstelle' => '999999', 'betrag' => '100'], 'Diese Kostenstelle gibt es nicht oder sie ist deaktiviert.'],
        ];
        foreach ($faelle as [$felder, $meldung]) {
            $antwort = $this->speichern($felder);
            self::assertSame(422, $antwort->status, $meldung);
            self::assertStringContainsString(htmlspecialchars($meldung, ENT_QUOTES), $antwort->body);
            self::assertStringContainsString('aria-invalid="true"', $antwort->body);
        }
        // Deactivated ones cannot be chosen - not even by a hand-made form.
        $kategorien = new CategoryRepository($this->pdo());
        $alt = $kategorien->create('Testkat Alt', CategoryDirection::Ausgabe, null, '');
        $kategorien->update($alt, 'Testkat Alt', CategoryDirection::Ausgabe, null, '', false);
        $altKst = new CostCenterRepository($this->pdo())->create('Testkst Alt');
        new CostCenterRepository($this->pdo())->update($altKst, 'Testkst Alt', false);
        self::assertStringContainsString('sie ist deaktiviert', $this->speichern(['kategorie' => (string) $alt, 'betrag' => '100'])->body);
        self::assertStringContainsString('sie ist deaktiviert', $this->speichern(['kostenstelle' => (string) $altKst, 'betrag' => '100'])->body);

        $jahr = $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '100'], '1999');
        self::assertSame(422, $jahr->status);
        self::assertStringContainsString('Budgets gibt es für die Jahre 2000 bis ' . ((int) date('Y') + 1), $jahr->body);
        $kaputt = $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '100'], 'abcd');
        self::assertSame(422, $kaputt->status);

        // Without a valid CSRF token: back to the page, nothing written.
        $ohneToken = $this->kernel()->handle(new Request(HttpMethod::Post, '/app/auswertung/budget', post: ['jahr' => '2025', 'kategorie' => (string) $this->platz, 'betrag' => '100'], cookies: $this->tresorCookie()));
        self::assertInstanceOf(Response::class, $ohneToken);
        self::assertSame(403, $ohneToken->status);

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());
        self::assertSame([], $this->protokoll(AuditAction::BudgetGespeichert));
    }

    public function testKeepingBudgetsAlsoNeedsTheReportRight(): void
    {
        // A hand-made role with bank.book alone: the form lives on the
        // report page, so writing is refused too.
        $rollen = new RoleRepository($this->pdo());
        $rolle = $rollen->create('Testrolle Buchen', false, [Permission::BankBook->value => PermissionScope::Alle]);
        $rollen->assignToUser($this->userId, [$rolle]);

        $antwort = $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => 'kaputt']);
        self::assertSame(403, $antwort->status);
        self::assertStringNotContainsString('Testkat Platzpflege', $antwort->body);
        self::assertSame(403, $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '1'])->status);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());
    }

    public function testTheBasisIsKeptAfterSaving(): void
    {
        $antwort = $this->post('/app/auswertung/budget', ['jahr' => '2025', 'grundlage' => 'belege', 'kategorie' => (string) $this->platz, 'kostenstelle' => '', 'betrag' => '5']);
        self::assertSame('/app/auswertung/budget?jahr=2025&grundlage=belege', $antwort->headers['Location'] ?? null);
        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025', 'grundlage' => 'belege']);
        self::assertStringContainsString('<input type="hidden" name="grundlage" value="belege">', $seite->body);
    }

    public function testDeleting(): void
    {
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);
        $id = (int) $this->pdo()->query('SELECT id FROM budget')->fetchColumn();
        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025']);
        self::assertStringContainsString('action="/app/auswertung/budget/' . $id . '/loeschen"', $seite->body);

        $antwort = $this->post('/app/auswertung/budget/' . $id . '/loeschen', ['jahr' => '2025', 'grundlage' => 'belege']);
        self::assertSame(302, $antwort->status);
        self::assertSame('/app/auswertung/budget?jahr=2025&grundlage=belege', $antwort->headers['Location'] ?? null);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());
        $geloescht = $this->protokoll(AuditAction::BudgetGeloescht);
        self::assertCount(1, $geloescht);
        self::assertSame($id, $geloescht[0]->entityId);
        self::assertStringContainsString('Für 2025 sind keine Budgets angelegt.', $this->get('/app/auswertung/budget', ['jahr' => '2025'])->body);

        self::assertSame(404, $this->post('/app/auswertung/budget/' . $id . '/loeschen', ['jahr' => '2025'])->status);

        // A category or cost center with a budget counts as used: it is
        // deactivated, not deleted - no budget vanishes without an audit entry.
        $kategorien = new CategoryRepository($this->pdo());
        $neu = $kategorien->create('Testkat Neu', CategoryDirection::Ausgabe, null, '');
        $kostenstelle = new CostCenterRepository($this->pdo())->create('Testkst Neu');
        self::assertSame(0, $kategorien->usageCount($neu));
        $this->speichern(['kategorie' => (string) $neu, 'kostenstelle' => (string) $kostenstelle, 'betrag' => '1,00']);
        self::assertSame(1, $kategorien->usageCount($neu));
        self::assertSame(1, new CostCenterRepository($this->pdo())->budgetCount($kostenstelle));
        try {
            new CostCenterService(new CostCenterRepository($this->pdo()))->loeschen($kostenstelle);
            self::fail('a cost center with a budget is not deleted');
        } catch (CostCenterRuleViolation $e) {
            self::assertStringContainsString('Budgets angelegt', $e->getMessage());
        }
        try {
            $kategorien->delete($neu);
            self::fail('the schema refuses it as well');
        } catch (\PDOException) {
        }
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());
    }

    public function testAnAmountMovedToAnotherRowCannotBeRead(): void
    {
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);
        $this->speichern(['kategorie' => (string) $this->reise, 'betrag' => '100,00']);
        // Copy key and ciphertext of the first row onto the second: the AAD
        // (table|id|column) no longer matches.
        $zeilen = $this->pdo()->query('SELECT id, dek_sealed, data_enc FROM budget ORDER BY id')->fetchAll();
        $stmt = $this->pdo()->prepare('UPDATE budget SET dek_sealed = ?, data_enc = ? WHERE id = ?');
        $stmt->execute([$zeilen[0]['dek_sealed'], $zeilen[0]['data_enc'], $zeilen[1]['id']]);

        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025']);
        self::assertStringContainsString('1 Budget ließ sich nicht entschlüsseln und ist nicht aufgeführt.', $seite->body);
        self::assertCount(1, $this->tabelle($seite->body));
    }

    public function testACostCenterScopeSeesOnlyTheBudgetsOfItsCostCenters(): void
    {
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);
        $this->speichern(['kostenstelle' => (string) $this->jugend, 'betrag' => '200']);
        $this->speichern(['kategorie' => (string) $this->platz, 'kostenstelle' => (string) $this->jugend, 'betrag' => '400']);
        $this->speichern(['kategorie' => (string) $this->reise, 'kostenstelle' => (string) $this->herren, 'betrag' => '100,00']);

        $this->rolle(SystemRole::Vereinsverantwortlicher);
        new UserAccessRepository($this->pdo())->setCostCenters($this->userId, [$this->jugend]);

        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025', 'grundlage' => 'belege']);
        self::assertSame(200, $seite->status);
        self::assertSame([
            ['alle Kategorien · Testkst Jugend', '200,00 €', '300,00 €', '-100,00 €', '−50,0 %', '150,0 %'],
            ['Testkat Platzpflege · Testkst Jugend', '400,00 €', '300,00 €', '100,00 €', '25,0 %', '75,0 %'],
        ], $this->tabelle($seite->body));
        self::assertStringNotContainsString('Testkst Herren', $seite->body);
        self::assertStringNotContainsString('alle Kostenstellen', $seite->body);
        // Neither the form nor the delete buttons, no CSV link (no export.csv).
        self::assertStringNotContainsString('Budget speichern', $seite->body);
        self::assertStringNotContainsString('/loeschen', $seite->body);
        self::assertStringNotContainsString('budget.csv', $seite->body);
        self::assertSame(403, $this->get('/app/auswertung/budget.csv', ['jahr' => '2025'])->status);
        self::assertSame(403, $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '1'])->status);
        self::assertSame(4, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());
    }

    public function testAScopedWriterKeepsOnlyBudgetsOfHisCostCenters(): void
    {
        // N-07: writing and deleting stay inside the cost-center scope.
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);
        $this->speichern(['kategorie' => (string) $this->reise, 'kostenstelle' => (string) $this->herren, 'betrag' => '100,00']);
        $this->speichern(['kostenstelle' => (string) $this->jugend, 'betrag' => '200']);
        $ids = array_map(intval(...), $this->pdo()->query('SELECT id FROM budget ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        [$ohneKostenstelle, $herren, $jugend] = $ids;

        $rollen = new RoleRepository($this->pdo());
        $rolle = $rollen->create('Testrolle Abteilungskasse', false, [
            Permission::BankBook->value => PermissionScope::Kostenstelle,
            Permission::ReportView->value => PermissionScope::Kostenstelle,
        ]);
        $rollen->assignToUser($this->userId, [$rolle]);
        new UserAccessRepository($this->pdo())->setCostCenters($this->userId, [$this->jugend]);

        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025']);
        self::assertStringContainsString('Budget speichern', $seite->body);
        self::assertStringContainsString('>Testkst Jugend</option>', $seite->body);
        self::assertStringNotContainsString('Testkst Herren', $seite->body, 'neither as a row nor as an option');
        self::assertStringNotContainsString('<option value="">alle Kostenstellen</option>', $seite->body);

        $hinweis = 'Budgets pflegen Sie nur für Ihre eigenen Kostenstellen.';
        foreach ([
            ['kategorie' => (string) $this->platz, 'betrag' => '1,00'],
            ['kategorie' => (string) $this->reise, 'kostenstelle' => (string) $this->herren, 'betrag' => '1,00'],
        ] as $felder) {
            $antwort = $this->speichern($felder);
            self::assertSame(422, $antwort->status);
            self::assertStringContainsString($hinweis, $antwort->body);
        }
        self::assertSame(3, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());
        self::assertCount(3, $this->protokoll(AuditAction::BudgetGespeichert), 'the refused saves leave no audit entry');

        self::assertSame(302, $this->speichern(['kostenstelle' => (string) $this->jugend, 'betrag' => '250'])->status);
        self::assertSame(302, $this->speichern(['kategorie' => (string) $this->platz, 'kostenstelle' => (string) $this->jugend, 'betrag' => '50'])->status);
        self::assertSame(4, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());

        // A budget outside the scope looks like one that does not exist.
        self::assertSame(404, $this->post('/app/auswertung/budget/' . $herren . '/loeschen', ['jahr' => '2025'])->status);
        self::assertSame(404, $this->post('/app/auswertung/budget/' . $ohneKostenstelle . '/loeschen', ['jahr' => '2025'])->status);
        self::assertSame([], $this->protokoll(AuditAction::BudgetGeloescht));
        self::assertSame(302, $this->post('/app/auswertung/budget/' . $jugend . '/loeschen', ['jahr' => '2025'])->status);
        $uebrig = array_map(intval(...), $this->pdo()->query('SELECT id FROM budget ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame([$ohneKostenstelle, $herren], array_slice($uebrig, 0, 2));
        self::assertCount(3, $uebrig);
    }

    public function testAWriteScopeNarrowerThanTheReadScopeShowsDeleteOnlyWhereItWorks(): void
    {
        $this->speichern(['kategorie' => (string) $this->reise, 'kostenstelle' => (string) $this->herren, 'betrag' => '100,00']);
        $this->speichern(['kostenstelle' => (string) $this->jugend, 'betrag' => '200']);
        [$herren, $jugend] = array_map(intval(...), $this->pdo()->query('SELECT id FROM budget ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));

        $rollen = new RoleRepository($this->pdo());
        $rolle = $rollen->create('Testrolle Abteilung liest alles', false, [
            Permission::BankBook->value => PermissionScope::Kostenstelle,
            Permission::ReportView->value => PermissionScope::Alle,
        ]);
        $rollen->assignToUser($this->userId, [$rolle]);
        new UserAccessRepository($this->pdo())->setCostCenters($this->userId, [$this->jugend]);

        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025']);
        self::assertStringContainsString('Testkst Herren', $seite->body, 'reading is not narrowed');
        self::assertStringContainsString('action="/app/auswertung/budget/' . $jugend . '/loeschen"', $seite->body);
        self::assertStringNotContainsString('action="/app/auswertung/budget/' . $herren . '/loeschen"', $seite->body);
        self::assertStringNotContainsString('>Testkst Herren</option>', $seite->body);
        self::assertSame(404, $this->post('/app/auswertung/budget/' . $herren . '/loeschen', ['jahr' => '2025'])->status);
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());
    }

    public function testAPeriodScopeCountsOnlyTheReleasedPartOfTheYear(): void
    {
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);
        $this->rolle(SystemRole::Kassenpruefer);
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-04-30'));

        // Default year: the end of the released period.
        $seite = $this->get('/app/auswertung/budget', ['grundlage' => 'belege']);
        self::assertStringContainsString('<h2>Budget 2025</h2>', $seite->body);
        self::assertStringContainsString('vom 01.01.2025 bis 30.04.2025', $seite->body);
        self::assertStringContainsString('Das Ist umfasst nur den für Sie freigegebenen Teil des Jahres', $seite->body);
        self::assertSame([['Testkat Platzpflege · alle Kostenstellen', '500,00 €', '300,00 €', '200,00 €', '40,0 %', '60,0 %']], $this->tabelle($seite->body));
        // No way to the years outside.
        self::assertStringNotContainsString('jahr=2024', $seite->body);
        self::assertStringNotContainsString('jahr=2026', $seite->body);

        $vorher = $this->get('/app/auswertung/budget', ['jahr' => '2024']);
        self::assertStringContainsString('außerhalb des für Sie freigegebenen Zeitraums', $vorher->body);
        self::assertStringNotContainsString('500,00', $vorher->body);

        // The auditor reads, but keeps nothing.
        self::assertStringNotContainsString('Budget speichern', $seite->body);
        self::assertSame(403, $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '1'])->status);
    }

    public function testWithoutAnUnlockedVaultNoFiguresAndNoWrites(): void
    {
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);

        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025'], entsperrt: false);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $seite->body);
        self::assertStringNotContainsString('500,00', $seite->body);
        self::assertStringNotContainsString('Testkat Platzpflege', $seite->body);
        self::assertStringNotContainsString('Budget speichern', $seite->body);
        self::assertArrayNotHasKey('auswertung_cache', $_SESSION);

        self::assertSame(403, $this->speichern(['kategorie' => (string) $this->reise, 'betrag' => '1'], entsperrt: false)->status);
        self::assertSame(403, $this->get('/app/auswertung/budget.csv', ['jahr' => '2025'], entsperrt: false)->status);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM budget')->fetchColumn());
    }

    public function testTheCsvDownload(): void
    {
        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);
        $this->speichern(['kostenstelle' => (string) $this->jugend, 'betrag' => '200']);

        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025', 'grundlage' => 'belege']);
        self::assertStringContainsString('href="/app/auswertung/budget.csv?jahr=2025&amp;grundlage=belege"', $seite->body);

        $csv = $this->get('/app/auswertung/budget.csv', ['jahr' => '2025', 'grundlage' => 'belege']);
        self::assertSame(200, $csv->status);
        self::assertSame('text/csv; charset=utf-8', $csv->headers['Content-Type'] ?? null);
        self::assertSame('attachment; filename="budget_2025.csv"', $csv->headers['Content-Disposition'] ?? null);
        self::assertSame('no-store, private', $csv->headers['Cache-Control'] ?? null);
        self::assertSame(
            "\u{FEFF}Kategorie;Kostenstelle;Soll (EUR);Ist (EUR);Abweichung Soll − Ist (EUR);Abweichung (%);Ausgeschöpft (%)\r\n"
            // The percent minus is U+2212: no formula guard, no apostrophe.
            . "alle Kategorien;Testkst Jugend;200,00;300,00;-100,00;−50,0 %;150,0 %\r\n"
            . "Testkat Platzpflege;alle Kostenstellen;500,00;350,00;150,00;30,0 %;70,0 %\r\n",
            $csv->body,
        );
    }

    public function testTheReportCacheSeesAChangedBudget(): void
    {
        $stand = new AuswertungsStandRepository($this->pdo());
        $vorher = $stand->fingerabdruck();
        self::assertNotNull($vorher);

        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '500,00']);
        $nachher = $stand->fingerabdruck();
        self::assertNotSame($vorher, $nachher);

        $this->speichern(['kategorie' => (string) $this->platz, 'betrag' => '600,00']);
        self::assertNotSame($nachher, $stand->fingerabdruck());
    }

    public function testTheTabRowAndTheYearSwitcher(): void
    {
        $seite = $this->get('/app/auswertung/budget', ['jahr' => '2025']);
        self::assertStringContainsString('href="/app/auswertung/budget" aria-current="page">Budget</a>', $seite->body);
        self::assertStringContainsString('href="/app/auswertung/budget?jahr=2024&amp;grundlage=buchungen" rel="prev"', $seite->body);
        self::assertStringContainsString('href="/app/auswertung/budget?jahr=2026&amp;grundlage=buchungen" rel="next"', $seite->body);
        // Budgets are planned ahead: next year can be opened, the year after not.
        $naechstes = (string) ((int) date('Y') + 1);
        $planung = $this->get('/app/auswertung/budget', ['jahr' => $naechstes]);
        self::assertStringContainsString('<h2>Budget ' . $naechstes . '</h2>', $planung->body);
        self::assertStringNotContainsString('rel="next"', $planung->body);
        self::assertStringContainsString('<h2>Budget ' . date('Y') . '</h2>', $this->get('/app/auswertung/budget', ['jahr' => (string) ((int) date('Y') + 2)])->body);
        // The form offers only expense categories.
        self::assertStringContainsString('>Testkat Platzpflege</option>', $seite->body);
        self::assertStringNotContainsString('>Testkat Beiträge</option>', $seite->body);
    }

    // ------------------------------------------------------------ scaffolding

    /**
     * 2025: receipts 300,00 (Platzpflege, Jugend), 100,00 (Reisekosten,
     * Herren), 50,00 (Platzpflege, no cost center); bookings -40,00
     * Reisekosten without a receipt and -9,99 in 2024.
     */
    private function beispieldaten(): void
    {
        $kategorien = new CategoryRepository($this->pdo());
        $this->platz = $kategorien->create('Testkat Platzpflege', CategoryDirection::Ausgabe, null, '');
        $this->reise = $kategorien->create('Testkat Reisekosten', CategoryDirection::Beide, null, '');
        $this->beitraege = $kategorien->create('Testkat Beiträge', CategoryDirection::Einnahme, null, '');
        $kostenstellen = new CostCenterRepository($this->pdo());
        $this->jugend = $kostenstellen->create('Testkst Jugend');
        $this->herren = $kostenstellen->create('Testkst Herren');

        $this->beleg(30000, '2025-03-14', $this->platz, $this->jugend);
        $this->beleg(10000, '2025-05-02', $this->reise, $this->herren);
        $this->beleg(5000, '2025-08-01', $this->platz, null);
        $konto = $this->bankkonto();
        $this->buchung($konto, '2025-06-01', -4000, $this->reise);
        $this->buchung($konto, '2024-06-01', -999, $this->reise);
    }

    /**
     * @param array<string, string> $felder
     */
    private function speichern(array $felder, string $jahr = '2025', bool $entsperrt = true): Response
    {
        return $this->post('/app/auswertung/budget', ['jahr' => $jahr, 'kategorie' => '', 'kostenstelle' => '', ...$felder], $entsperrt);
    }

    /**
     * Rows of the budget table: name, Soll, Ist, Abweichung, Abweichung %,
     * Ausgeschöpft.
     *
     * @return list<list<string>>
     */
    private function tabelle(string $html): array
    {
        if (preg_match('#<table class="tabelle tabelle-karten geldfluss budget">(.*?)</table>#s', $html, $tabelle) !== 1) {
            return [];
        }
        preg_match_all('#<tr>\s*<th scope="row">\s*(.*?)\s*(?:<span class="marke[^"]*">[^<]*</span>\s*)?</th>.*?<td class="zahl" data-label="Soll">([^<]*)</td>\s*<td class="zahl" data-label="Ist">([^<]*)</td>\s*<td class="zahl" data-label="Abweichung">([^<]*)</td>\s*<td class="zahl" data-label="Abweichung %">([^<]*)</td>\s*<td class="zahl" data-label="Ausgeschöpft">([^<]*)</td>#s', $tabelle[1], $treffer, PREG_SET_ORDER);

        return array_map(static fn(array $t): array => array_map(static fn(string $s): string => html_entity_decode($s, ENT_QUOTES), array_slice($t, 1)), $treffer);
    }

    /** @return list<AuditEntry> newest first */
    private function protokoll(AuditAction $aktion): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(aktion: $aktion), null, 20);
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

    private function rolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    private function beleg(int $brutto, string $datum, ?int $kategorie, ?int $kostenstelle): int
    {
        ++$this->laufnummer;
        $dokument = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('-' . $this->laufnummer . ' seconds'),
            DocumentStatus::Geprueft,
            costCenterId: $kostenstelle,
            createdBy: $this->userId,
        );
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, null, new \DateTimeImmutable($datum), null, null, null, $kategorie, $kostenstelle),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData('', $brutto, null, [], 'EUR', '', '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    private function bankkonto(): int
    {
        return new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen(
            $this->tresor,
            BankAccountKind::Bank,
            ['name' => 'Girokonto', 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '1000,00', 'opening_date' => '2024-01-01'],
            new \DateTimeImmutable(),
        )->id;
    }

    private function buchung(int $konto, string $datum, int $cent, ?int $kategorie): void
    {
        $repository = new BankTransactionRepository($this->pdo());
        $richtung = BankTransactionDirection::ausDemBetrag($cent);
        $key = DataKey::generate();
        $id = $repository->insert(
            $konto,
            null,
            new \DateTimeImmutable($datum),
            null,
            $richtung,
            $this->tresor->sealDataKey($key),
            random_bytes(32),
            null,
            BankTransactionDocStatus::fuerNeueBuchung($richtung === BankTransactionDirection::Ausgabe),
            $richtung === BankTransactionDirection::Ausgabe,
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
            $kategorie,
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => 'Gegenseite', 'counterparty_iban' => '',
            'purpose' => 'Zweck', 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $pfad, array $query = [], bool $entsperrt = true): Response
    {
        $antwort = $this->kernel()->handle(new Request(HttpMethod::Get, $pfad, query: $query, cookies: $entsperrt ? $this->tresorCookie() : []));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @param array<string, string> $felder
     */
    private function post(string $pfad, array $felder, bool $entsperrt = true): Response
    {
        $antwort = $this->kernel()->handle(new Request(
            HttpMethod::Post,
            $pfad,
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
            ip: self::IP,
            cookies: $entsperrt ? $this->tresorCookie() : [],
        ));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();
        $audit = $this->audit;

        $guard = static fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            static function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null ? null : new SessionUser($user, 'Test', new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $userId = $this->userId;
        $budgets = static fn(): BudgetController => new BudgetController(
            $view,
            new Session(),
            new SessionVault(),
            static fn(): Budgets => new Budgets(
                $pdo,
                new BudgetRepository($pdo),
                new CategoryRepository($pdo),
                new CostCenterRepository($pdo),
                new AuswertungsCache(
                    new Auswertungsdaten(
                        new InvoiceRepository($pdo),
                        new BankTransactionRepository($pdo),
                        ZuordnungFabrik::bauen($pdo, $audit),
                        new BankAccountService($pdo, new BankAccountRepository($pdo)),
                        new SettingRepository($pdo),
                    ),
                    new AuswertungsStandRepository($pdo),
                    $userId,
                ),
            ),
            static fn(): AuditLog => $audit,
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $unerreichbar,
            $guard,
            ...array_fill(0, 36, $unerreichbar),
            nextcloudAdmin: $unerreichbar,
            budgets: $budgets,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
