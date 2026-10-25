<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\KassenpruefungController;
use App\Config\Paths;
use App\Domain\BankAccountKind;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSetBy;
use App\Domain\BankTransactionSource;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InvoiceData;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
use App\Domain\Permission;
use App\Domain\PermissionScope;
use App\Domain\SupplierRole;
use App\Domain\SystemRole;
use App\Domain\Zahlungsweg;
use App\Domain\ZuordnungMethode;
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
use App\Repository\CategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
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
use App\Service\Bank\BankAccountService;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Service\Report\AuswertungsCache;
use App\Service\Report\Auswertungsdaten;
use App\Service\Report\Kassenpruefung;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;
use App\View\View;

/**
 * The Kassenprüfung (issue #74/M11-4, docs/spec/05-auswertung-und-export.md
 * section 1) through the real route table, guard, services, schema and
 * vault: balances per account at the start and end, the bookings without a
 * receipt, the cases decided by hand, every booking with its receipt
 * status; the CSV downloads (format, formula guard, file name); rights
 * (`report.view`, `export.csv`, `bank.view`), the period scope of an
 * external auditor (opening balance as carry-over, E-44) and a locked vault.
 * Synthetic data only; past years only.
 */
final class KassenpruefungFlowTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.10';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $laufnummer = 0;

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
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testTheReportShowsBalancesMissingReceiptsManualCasesAndEveryBooking(): void
    {
        $this->beispieldaten();

        $seite = $this->get('/app/auswertung/kassenpruefung', ['art' => 'jahr', 'jahr' => '2025']);

        self::assertSame(200, $seite->status);
        self::assertSame('no-store, private', $seite->headers['Cache-Control'] ?? null);
        self::assertStringContainsString('Zeitraum 01.01.2025 bis 31.12.2025', $seite->body);
        self::assertSame('9', $this->kennzahl($seite->body, 'Buchungen'));
        self::assertSame('3', $this->kennzahl($seite->body, 'Ohne Beleg'));
        self::assertSame('2', $this->kennzahl($seite->body, 'Von Hand entschieden'));

        // Girokonto: 1.000,00 - 9,99 before 2025; +500,00; -8,00 -120,00 -30,00 -50,00 -15,00 -10,00 (transfer).
        self::assertMatchesRegularExpression('#Girokonto\s*</th>\s*<td class="zahl">990,01 €</td>\s*<td class="zahl">0,00 €</td>\s*<td class="zahl">500,00 €</td>\s*<td class="zahl">233,00 €</td>\s*<td class="zahl"><strong>1\.257,01 €#', $seite->body);
        // Kasse: opened in 2025 with 50,00, -15,00, +10,00 (transfer).
        self::assertMatchesRegularExpression('#Kasse\s*<br><span class="gedaempft klein">eröffnet am 01\.02\.2025</span>\s*</th>\s*<td class="zahl">0,00 €</td>\s*<td class="zahl">50,00 €</td>\s*<td class="zahl">10,00 €</td>\s*<td class="zahl">15,00 €</td>\s*<td class="zahl"><strong>45,00 €#', $seite->body);
        self::assertStringNotContainsString('(Übertrag)', $seite->body);

        $ohneBeleg = $this->abschnitt($seite->body, 'kp-ohne-beleg');
        self::assertStringContainsString('Mieter 120', $ohneBeleg);
        self::assertStringContainsString('Teilzahler', $ohneBeleg);
        self::assertMatchesRegularExpression('#-50,00 €</td>\s*<td class="zahl">30,00 €#', $ohneBeleg, 'only the uncovered rest');
        self::assertStringContainsString('Kassenbon', $ohneBeleg);
        self::assertStringNotContainsString('Sportshop', $ohneBeleg);
        self::assertStringNotContainsString('Umbuchung', $ohneBeleg);

        $manuell = $this->abschnitt($seite->body, 'kp-manuell');
        self::assertStringContainsString('Sportshop', $manuell);
        self::assertStringContainsString('Beleg von Hand zugeordnet', $manuell);
        self::assertStringContainsString('Ballhaus GmbH · Nr. R-2025-7 · Geprüft', $manuell);
        self::assertStringContainsString('Kontoführung', $manuell);
        self::assertStringContainsString('von Hand als „kein Beleg nötig“ markiert', $manuell);
        self::assertStringNotContainsString('Teilzahler', $manuell, 'an automatic allocation is no manual case');

        $alle = $this->abschnitt($seite->body, 'kp-buchungen');
        self::assertStringContainsString('9 Buchungen, älteste zuerst', $alle);
        self::assertStringContainsString('Altlieferant', $alle);
        self::assertStringContainsString('(automatisch)', $alle);
        self::assertStringContainsString('Umbuchung', $alle);
        self::assertStringNotContainsString('Vorjahr', $alle, 'nothing from before the period');
        self::assertLessThan(strpos($alle, 'Mieter 120'), (int) strpos($alle, 'Kassenbon'), 'oldest first');

        // The CSV links carry the period, nothing else.
        self::assertStringContainsString('href="/app/auswertung/kassenpruefung/buchungen.csv?art=jahr&amp;jahr=2025"', $seite->body);
        // Balances came through the session cache.
        self::assertArrayHasKey('auswertung_cache', $_SESSION);
    }

    public function testTheCsvDownloadsAreExcelReadyGuardedAndNamedWithoutClubData(): void
    {
        $this->beispieldaten();
        $query = ['art' => 'jahr', 'jahr' => '2025'];

        $salden = $this->get('/app/auswertung/kassenpruefung/salden.csv', $query);
        self::assertSame(200, $salden->status);
        self::assertSame('text/csv; charset=utf-8', $salden->headers['Content-Type'] ?? null);
        self::assertSame('attachment; filename="kassenpruefung-salden-2025-01-01-2025-12-31.csv"', $salden->headers['Content-Disposition'] ?? null);
        self::assertSame('no-store, private', $salden->headers['Cache-Control'] ?? null);
        self::assertStringStartsWith("\u{FEFF}Konto;Währung;Anfang;", $salden->body);
        self::assertStringContainsString("\r\nGirokonto;EUR;990,01;0,00;500,00;233,00;1.257,01\r\n", $salden->body);
        self::assertStringContainsString("\r\nKasse;EUR;0,00;50,00;10,00;15,00;45,00\r\n", $salden->body);

        $buchungen = $this->get('/app/auswertung/kassenpruefung/buchungen.csv', $query)->body;
        self::assertCount(1 + 9, $this->zeilen($buchungen));
        // Formula guard on text from the bank statement; amounts stay numbers.
        self::assertStringContainsString(';"\'=HYPERLINK(""http://x"")";\'+Mieter 120;-120,00;EUR;Kontoauszug;Beleg fehlt;0,00;120,00;', $buchungen);
        self::assertStringContainsString(';Teilzahler;Rate;-50,00;EUR;Kontoauszug;Beleg teilweise zugeordnet;20,00;30,00;Beleg ', $buchungen);
        self::assertStringContainsString('(automatisch) 20,00', $buchungen);
        self::assertStringContainsString('Umbuchung (kein Beleg nötig)', $buchungen);

        $ohne = $this->get('/app/auswertung/kassenpruefung/ohne-beleg.csv', $query)->body;
        self::assertCount(1 + 3, $this->zeilen($ohne));
        self::assertStringContainsString(';Beleg teilweise zugeordnet;30,00', $ohne);

        $manuell = $this->get('/app/auswertung/kassenpruefung/manuell.csv', $query)->body;
        self::assertCount(1 + 2, $this->zeilen($manuell));
        self::assertStringContainsString(';Beleg von Hand zugeordnet;Beleg ', $manuell);
        self::assertStringContainsString(';30,00;' . date('d.m.Y'), $manuell);
        self::assertStringContainsString(';von Hand als „kein Beleg nötig“ markiert;;;', $manuell);

        foreach (['buchungen', 'salden', 'ohne-beleg', 'manuell'] as $teil) {
            $antwort = $this->get('/app/auswertung/kassenpruefung/' . $teil . '.csv', $query);
            self::assertDoesNotMatchRegularExpression('/Girokonto|Mieter|Ballhaus/', $antwort->headers['Content-Disposition'] ?? '');
        }
        self::assertSame(404, $this->get('/app/auswertung/kassenpruefung/geheim.csv', $query)->status);
    }

    public function testAnAuditorWithAPeriodScopeSeesOnlyTheReleasedPeriodWithACarryOver(): void
    {
        $this->beispieldaten();
        $this->rolle(SystemRole::Kassenpruefer);
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-06-30'));

        // The page opens with the released period.
        $seite = $this->get('/app/auswertung/kassenpruefung');
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Zeitraum 01.01.2025 bis 30.06.2025', $seite->body);
        self::assertStringContainsString('Anfang (Übertrag)', $seite->body);
        self::assertStringContainsString('Übertrag aus der Zeit vor dem für Sie freigegebenen Zeitraum', $seite->body);
        // Girokonto at the end of June: 990,01 + 500 - 8 - 120 - 30 - 50.
        self::assertMatchesRegularExpression('#Girokonto\s*</th>\s*<td class="zahl">990,01 €</td>\s*<td class="zahl">0,00 €</td>\s*<td class="zahl">500,00 €</td>\s*<td class="zahl">208,00 €</td>\s*<td class="zahl"><strong>1\.282,01 €#', $seite->body);
        self::assertSame('6', $this->kennzahl($seite->body, 'Buchungen'));
        self::assertStringNotContainsString('Kontoführung', $seite->body, 'July is outside the released period');
        self::assertStringNotContainsString('Vorjahr', $seite->body, 'no single booking before it');
        // The receipt of December 2024 is allocated - but not named.
        self::assertStringContainsString('Beleg außerhalb Ihres Zugriffsbereichs (Vorschlag bestätigt)', $seite->body);
        self::assertStringNotContainsString('Altlieferant', $seite->body);

        // A whole year asked for: only the released half, said so.
        $jahr = $this->get('/app/auswertung/kassenpruefung', ['art' => 'jahr', 'jahr' => '2025']);
        self::assertStringContainsString('nur der für Sie freigegebene Teil', $jahr->body);
        self::assertStringNotContainsString('Kontoführung', $jahr->body);

        // A year outside it shows nothing.
        $vorher = $this->get('/app/auswertung/kassenpruefung', ['art' => 'jahr', 'jahr' => '2024']);
        self::assertStringContainsString('außerhalb des für Sie freigegebenen Zeitraums', $vorher->body);
        self::assertStringNotContainsString('Vorjahr', $vorher->body);
        self::assertStringNotContainsString('990,01', $vorher->body);

        // The downloads follow the same scope.
        $csv = $this->get('/app/auswertung/kassenpruefung/buchungen.csv', ['art' => 'jahr', 'jahr' => '2025']);
        self::assertSame('attachment; filename="kassenpruefung-buchungen-2025-01-01-2025-06-30.csv"', $csv->headers['Content-Disposition'] ?? null);
        self::assertStringNotContainsString('Kontoführung', $csv->body);
        self::assertStringNotContainsString('Altlieferant', $csv->body);
        $salden = $this->get('/app/auswertung/kassenpruefung/salden.csv', ['art' => 'jahr', 'jahr' => '2024']);
        self::assertCount(1, $this->zeilen($salden->body), 'header only');
    }

    public function testWithoutBankViewTheReportStaysClosed(): void
    {
        $this->beispieldaten();
        // report.view and export.csv, but no bank.view.
        $rolle = new RoleRepository($this->pdo())->create('Nur Auswertung', false, [
            Permission::ReportView->value => PermissionScope::Alle,
            Permission::ExportCsv->value => PermissionScope::Alle,
        ]);
        new RoleRepository($this->pdo())->assignToUser($this->userId, [$rolle]);

        $seite = $this->get('/app/auswertung/kassenpruefung', ['art' => 'jahr', 'jahr' => '2025']);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('zusätzlich das Recht „Konten und Buchungen sehen“', $seite->body);
        self::assertStringNotContainsString('Girokonto', $seite->body);
        self::assertStringNotContainsString('Mieter', $seite->body);

        $csv = $this->get('/app/auswertung/kassenpruefung/buchungen.csv', ['art' => 'jahr', 'jahr' => '2025']);
        self::assertSame(403, $csv->status);
        self::assertStringNotContainsString('Mieter', $csv->body);

        // Without export.csv the guard refuses the download before the controller.
        $this->rolle(SystemRole::Vereinsverantwortlicher);
        self::assertSame(403, $this->get('/app/auswertung/kassenpruefung/salden.csv', ['art' => 'jahr', 'jahr' => '2025'])->status);
    }

    public function testWithoutAnUnlockedVaultNothingIsReadOrDownloaded(): void
    {
        $this->beispieldaten();

        $seite = $this->get('/app/auswertung/kassenpruefung', ['art' => 'jahr', 'jahr' => '2025'], entsperrt: false);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $seite->body);
        self::assertStringNotContainsString('Girokonto', $seite->body);
        self::assertArrayNotHasKey('auswertung_cache', $_SESSION);

        $csv = $this->get('/app/auswertung/kassenpruefung/salden.csv', ['art' => 'jahr', 'jahr' => '2025'], entsperrt: false);
        self::assertSame(403, $csv->status);
        self::assertStringNotContainsString('Girokonto', $csv->body);
        self::assertStringNotContainsString('text/csv', $csv->headers['Content-Type'] ?? '');
    }

    public function testWhatIsListedButNotInABalanceOrNotReadableIsSaid(): void
    {
        $giro = $this->bankkonto('Girokonto', '2025-01-01', '100,00');
        $kasse = $this->bankkonto('Kasse', '2025-03-01', '0,00');
        // Entered before the box was opened: listed, but not in its balance.
        $this->buchung($kasse, '2025-02-20', -500, 'Vorab');
        // An allocation whose ciphertext is damaged.
        $sport = $this->buchung($giro, '2025-05-01', -3000, 'Sportshop');
        $this->zuordnen($this->beleg(3000, 'Ballhaus GmbH', 'R-1'), $sport, 3000, ZuordnungMethode::Manuell);
        $this->pdo()->prepare('UPDATE allocation SET amount_enc = ? WHERE transaction_id = ?')->execute([random_bytes(40), $sport]);
        // A booking that does not decrypt at all.
        $kaputt = $this->buchung($giro, '2025-06-01', -100, 'Kaputt');
        $this->pdo()->prepare('UPDATE bank_transaction SET data_enc = ? WHERE id = ?')->execute([random_bytes(60), $kaputt]);

        $seite = $this->get('/app/auswertung/kassenpruefung', ['art' => 'jahr', 'jahr' => '2025']);

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('nicht im Saldo: vor der Kontoeröffnung', $seite->body);
        self::assertMatchesRegularExpression('#Kasse\s*<br><span class="gedaempft klein">eröffnet am 01\.03\.2025</span>\s*</th>\s*<td class="zahl">0,00 €</td>\s*<td class="zahl">0,00 €</td>\s*<td class="zahl">0,00 €</td>\s*<td class="zahl">0,00 €</td>#', $seite->body);
        self::assertStringContainsString('Zuordnung nicht lesbar', $seite->body);
        self::assertStringContainsString('Bei 1 Buchung ließ sich eine Zuordnung nicht entschlüsseln', $seite->body);
        // The broken booking is counted once - in the list, and once for the balances.
        self::assertStringContainsString('1 Buchung des Zeitraums ließ sich nicht entschlüsseln und fehlt in den Listen.', $seite->body);
        self::assertStringContainsString('1 Konto oder Buchung ist nicht lesbar oder in einer anderen Währung als das Konto', $seite->body);
        self::assertStringNotContainsString('Kaputt', $seite->body);
        self::assertSame('2', $this->kennzahl($seite->body, 'Buchungen'));

        $csv = $this->get('/app/auswertung/kassenpruefung/buchungen.csv', ['art' => 'jahr', 'jahr' => '2025'])->body;
        self::assertStringContainsString(';Vorab;Zweck;-5,00;EUR;Kontoauszug;Beleg fehlt;0,00;5,00;;nicht im Saldo: vor der Kontoeröffnung', $csv);
        self::assertStringContainsString(';Zuordnung nicht lesbar;', $csv);
    }

    public function testAMalformedPeriodFallsBackToTheLastYear(): void
    {
        $vorjahr = (int) date('Y') - 1;
        foreach ([['art' => 'jahr', 'jahr' => '1999'], ['art' => 'quartal', 'jahr' => '2025', 'quartal' => '5'], ['art' => 'frei', 'von' => '2025-02-30', 'bis' => '2025-03-01'], ['art' => 'x']] as $query) {
            $seite = $this->get('/app/auswertung/kassenpruefung', $query);
            self::assertSame(200, $seite->status);
            self::assertStringContainsString('Zeitraum 01.01.' . $vorjahr . ' bis 31.12.' . $vorjahr, $seite->body, (string) json_encode($query));
        }
        $quartal = $this->get('/app/auswertung/kassenpruefung', ['art' => 'quartal', 'jahr' => '2025', 'quartal' => '2']);
        self::assertStringContainsString('Zeitraum 01.04.2025 bis 30.06.2025', $quartal->body);
    }

    public function testAFreePeriodOfMoreThanThreeYearsFallsBackWithANote(): void
    {
        $vorjahr = (int) date('Y') - 1;
        // Issue #250: the same limit as "Wohin fließt das Geld" (E-51).
        $lang = $this->get('/app/auswertung/kassenpruefung', ['art' => 'frei', 'von' => '2021-01-01', 'bis' => '2024-01-01']);
        self::assertSame(200, $lang->status);
        self::assertStringContainsString('Ein freier Zeitraum umfasst höchstens 3 Jahre; gezeigt wird das Jahr ' . $vorjahr . '.', $lang->body);
        self::assertStringContainsString('Zeitraum 01.01.' . $vorjahr . ' bis 31.12.' . $vorjahr, $lang->body);
        $knapp = $this->get('/app/auswertung/kassenpruefung', ['art' => 'frei', 'von' => '2021-01-02', 'bis' => '2024-01-01']);
        self::assertStringNotContainsString('Ein freier Zeitraum umfasst höchstens', $knapp->body);
        self::assertStringContainsString('Zeitraum 02.01.2021 bis 01.01.2024', $knapp->body);

        // An auditor's default is the released period: the note names it.
        $this->rolle(SystemRole::Kassenpruefer);
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-06-30'));
        $pruefer = $this->get('/app/auswertung/kassenpruefung', ['art' => 'frei', 'von' => '2020-01-01', 'bis' => '2025-06-30']);
        self::assertStringContainsString('gezeigt wird der Zeitraum vom 01.01.2025 bis 30.06.2025.', $pruefer->body);
    }

    // ------------------------------------------------------------ scaffolding

    /**
     * Two accounts with every kind of case the report distinguishes.
     */
    private function beispieldaten(): void
    {
        $giro = $this->bankkonto('Girokonto', '2024-01-01', '1000,00');
        $kasse = $this->bankkonto('Kasse', '2025-02-01', '50,00');
        $this->buchung($giro, '2024-06-01', -999, 'Vorjahr');
        $this->buchung($giro, '2025-03-01', 50000, 'Mitgliedsbeiträge');
        $this->buchung($kasse, '2025-02-10', -1500, 'Kassenbon');
        // Missing receipt, with a counterparty that tries to plant a formula.
        $this->buchung($giro, '2025-04-01', -12000, '=HYPERLINK("http://x")', '+Mieter 120');
        // Allocated by hand.
        $sport = $this->buchung($giro, '2025-05-01', -3000, 'Sportshop');
        $this->zuordnen($this->beleg(3000, 'Ballhaus GmbH', 'R-2025-7'), $sport, 3000, ZuordnungMethode::Manuell);
        // Partly allocated automatically: 30,00 still without a receipt.
        $teil = $this->buchung($giro, '2025-06-01', -5000, 'Teilzahler', 'Rate');
        $this->zuordnen($this->beleg(2000, 'Ratenhaus', 'T-1'), $teil, 2000, ZuordnungMethode::Auto);
        // A receipt from before the auditor's period, confirmed suggestion.
        $nach = $this->buchung($giro, '2025-02-15', -800, 'Nachzahlung');
        $this->zuordnen($this->beleg(800, 'Altlieferant', 'A-1', '2024-12-20'), $nach, 800, ZuordnungMethode::VorschlagBestaetigt);
        // "kein Beleg nötig" set by a person.
        $gebuehr = $this->buchung($giro, '2025-07-01', -1500, 'Kontoführung');
        $this->pdo()->prepare('UPDATE bank_transaction SET doc_status = ?, doc_source = ? WHERE id = ?')
            ->execute([BankTransactionDocStatus::NichtNoetig->value, BankTransactionSetBy::Manuell->value, $gebuehr]);
        // Transfer giro -> box.
        foreach ([[$giro, -1000], [$kasse, 1000]] as [$konto, $cent]) {
            $id = $this->buchung($konto, '2025-08-01', $cent, 'Umbuchung Kasse');
            $this->pdo()->prepare('UPDATE bank_transaction SET doc_status = ?, doc_source = ? WHERE id = ?')
                ->execute([BankTransactionDocStatus::NichtNoetig->value, BankTransactionSetBy::Umbuchung->value, $id]);
        }
    }

    /** The figure shown in the tile labelled $label. */
    private function kennzahl(string $html, string $label): string
    {
        self::assertSame(1, preg_match('#<dt>' . preg_quote($label, '#') . '</dt>\s*<dd class="kennzahl-wert">([^<]*)</dd>#', $html, $treffer), $label);

        return html_entity_decode($treffer[1]);
    }

    /** The section with the heading id $id. */
    private function abschnitt(string $html, string $id): string
    {
        self::assertSame(1, preg_match('#<section aria-labelledby="' . preg_quote($id, '#') . '">(.*?)</section>#s', $html, $treffer), $id);

        return html_entity_decode($treffer[1]);
    }

    /**
     * @return list<string> the CSV lines without the trailing empty one
     */
    private function zeilen(string $csv): array
    {
        return array_values(array_filter(explode("\r\n", $csv), static fn(string $z): bool => $z !== ''));
    }

    private function rolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    private function beleg(int $brutto, string $lieferant, string $nummer, string $datum = '2025-03-14'): int
    {
        ++$this->laufnummer;
        $dokument = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('-' . $this->laufnummer . ' seconds'),
            DocumentStatus::Geprueft,
            createdBy: $this->userId,
        );
        $lieferanten = new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
        $lieferantId = $lieferanten->anlegen($this->tresor, ['name' => $lieferant, 'rolle' => SupplierRole::Lieferant->value], new \DateTimeImmutable())->id;
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, $lieferantId, new \DateTimeImmutable($datum), null, null, null, null, null, Zahlungsweg::Konto),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData($nummer, $brutto, null, [], 'EUR', '', '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    private function bankkonto(string $name, string $eroeffnet, string $bestand): int
    {
        return new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen(
            $this->tresor,
            $name === 'Kasse' ? BankAccountKind::Kasse : BankAccountKind::Bank,
            ['name' => $name, 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => $bestand, 'opening_date' => $eroeffnet],
            new \DateTimeImmutable(),
        )->id;
    }

    private function buchung(int $konto, string $datum, int $cent, string $gegenseite, string $zweck = 'Zweck'): int
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
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => $gegenseite, 'counterparty_iban' => '',
            'purpose' => $zweck, 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    private function zuordnen(int $beleg, int $buchung, int $betrag, ZuordnungMethode $methode): void
    {
        ZuordnungFabrik::bauen($this->pdo(), $this->audit)->zuordnen($this->tresor, $beleg, $buchung, $betrag, $methode, null, null, $this->userId, self::IP, new \DateTimeImmutable());
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
        $kassenpruefung = static fn(): KassenpruefungController => new KassenpruefungController(
            $view,
            new Session(),
            new SessionVault(),
            static fn(): Kassenpruefung => new Kassenpruefung(
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
                new BankTransactionRepository($pdo),
                ZuordnungFabrik::bauen($pdo, $audit),
                new InvoiceRepository($pdo),
                new SupplierService($pdo, new SupplierRepository($pdo), new CategoryRepository($pdo)),
            ),
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
            kassenpruefung: $kassenpruefung,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
