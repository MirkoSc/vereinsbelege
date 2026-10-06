<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\AccountController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\BankAccount;
use App\Domain\BankAccountKind;
use App\Domain\Permission;
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
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\CashCountRepository;
use App\Repository\CategoryRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Kassensturz;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * Bank accounts, cash boxes and the cash count end to end (M9-1, issue
 * #59, docs/spec/04-bank-und-abgleich.md section 1): the real route table,
 * the real guard, the real controller, services, repositories and schema,
 * with a real vault.
 */
final class BankAccountFlowTest extends DatabaseTestCase
{
    private const string IBAN_1 = 'DE89370400440532013000';
    private const string IBAN_2 = 'DE02120300000000202051';

    private Vault $tresor;
    private AuditLog $audit;
    private BankAccountRepository $konten;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), new ServerCrypto(random_bytes(32)));
        $this->konten = new BankAccountRepository($this->pdo());

        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $this->rolle(SystemRole::Finanzen);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ------------------------------------------------------- accounts

    /**
     * Acceptance criteria "Konten-Verwaltung (CRUD), IBAN verschlüsselt" and
     * "Beträge als Integer-Cent".
     */
    public function testABankAccountIsStoredWithItsIbanAndBalanceEncrypted(): void
    {
        $antwort = $this->post('/app/konten', $this->felder([
            'art' => 'bank',
            'name' => 'Girokonto Sparkasse',
            'iban' => 'de89 3704 0044 0532 0130 00',
            'bic' => 'cobadeff xxx',
            'bank' => 'Sparkasse Musterstadt',
            'opening_balance' => '1.234,56',
            'opening_date' => '2026-01-01',
        ]));

        self::assertSame(302, $antwort->status);
        $id = $this->einzigesKonto();
        self::assertSame('/app/konten/' . $id, $antwort->headers['Location'] ?? null);

        $seite = $this->get('/app/konten/' . $id);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Girokonto Sparkasse', $seite->body);
        self::assertStringContainsString('DE89 3704 0044 0532 0130 00', $seite->body, 'IBAN normalised and grouped');
        self::assertStringContainsString('COBADEFFXXX', $seite->body);
        self::assertStringContainsString('value="1.234,56"', $seite->body);

        $konto = $this->konto($id);
        self::assertSame(BankAccountKind::Bank, $konto->kind);
        self::assertSame(self::IBAN_1, $konto->data->iban);
        self::assertSame(123456, $konto->openingBalance, 'integer cents');
        self::assertSame('EUR', $konto->currency);
        self::assertSame('2026-01-01', $konto->openingDate->format('Y-m-d'));
        self::assertTrue($konto->active);

        $record = $this->konten->find($id) ?? self::fail();
        self::assertSame($this->tresor->blindIndex()->forValue('bank_account.iban', self::IBAN_1), $record->ibanBi);

        $roh = $this->rohTabelle('bank_account');
        foreach (['Girokonto', 'Sparkasse', self::IBAN_1, 'DE89 3704', 'COBADEFF', '1.234,56', '123456'] as $klartext) {
            self::assertStringNotContainsStringIgnoringCase($klartext, $roh, $klartext . ' is not stored in the clear');
        }

        $zeilen = $this->protokoll('bank_account', $id);
        self::assertSame([AuditAction::KontoAngelegt->value], array_map(static fn(AuditEntry $e): string => $e->action, $zeilen));
        self::assertSame(
            ['art' => 'bank', 'felder' => ['kind', 'name', 'iban', 'bic', 'bank', 'opening_balance', 'opening_date']],
            $this->audit->details($zeilen[0], $this->tresor),
        );
    }

    /**
     * Acceptance criterion "Kasse als Konto": same table, no bank
     * connection, no negative opening balance - a bank account may be
     * overdrawn.
     */
    public function testACashBoxIsAnAccountWithoutBankConnection(): void
    {
        $neu = $this->get('/app/konten/neu', ['art' => 'kasse']);
        self::assertSame(200, $neu->status);
        self::assertStringContainsString('Neue Kasse', $neu->body);
        self::assertStringNotContainsString('name="iban"', $neu->body);

        $kasse = $this->kasse('Barkasse', '150,00');
        $record = $this->konten->find($kasse) ?? self::fail();
        self::assertSame(BankAccountKind::Kasse, $record->kind);
        self::assertNull($record->ibanBi);
        self::assertSame(15000, $this->konto($kasse)->openingBalance);

        $mitIban = $this->post('/app/konten', $this->felder(['art' => 'kasse', 'name' => 'Vereinsheim', 'iban' => self::IBAN_2]));
        self::assertSame(422, $mitIban->status);
        self::assertStringContainsString('Eine Kasse hat keine Bankverbindung.', $mitIban->body);

        $negativ = $this->post('/app/konten', $this->felder(['art' => 'kasse', 'name' => 'Vereinsheim', 'opening_balance' => '-5,00']));
        self::assertSame(422, $negativ->status);
        self::assertStringContainsString('Der Anfangsbestand einer Kasse kann nicht negativ sein.', $negativ->body);

        $dispo = $this->post('/app/konten', $this->felder(['art' => 'bank', 'name' => 'Girokonto', 'opening_balance' => '-50,00']));
        self::assertSame(302, $dispo->status);
        self::assertCount(2, $this->konten->all());

        $liste = $this->get('/app/konten')->body;
        self::assertStringContainsString('<h3>Bankkonten</h3>', $liste);
        self::assertStringContainsString('<h3>Kassen</h3>', $liste);
        self::assertStringContainsString('Barkasse', $liste);
        self::assertStringContainsString('-50,00 €', $liste);
    }

    public function testInvalidInputIsRefusedAndTheFieldMarked(): void
    {
        $faelle = [
            ['name', ['name' => '  '], 'Bitte einen Namen angeben.'],
            ['iban', ['iban' => 'DE89 3704 0044 0532 0130 01'], 'Die IBAN ist ungültig'],
            ['bic', ['bic' => 'COBA'], 'Die BIC ist ungültig'],
            ['saldo', ['opening_balance' => 'zwölf'], 'Den Anfangssaldo bitte als Betrag'],
            ['saldo', ['opening_balance' => '1,234'], 'Den Anfangssaldo bitte als Betrag'],
            ['stichtag', ['opening_date' => '2026-02-30'], 'Bitte den Stichtag'],
            ['stichtag', ['opening_date' => new \DateTimeImmutable('tomorrow')->format('Y-m-d')], 'Der Stichtag kann nicht in der Zukunft liegen.'],
        ];
        foreach ($faelle as [$feld, $eingabe, $meldung]) {
            $antwort = $this->post('/app/konten', $this->felder(['art' => 'bank', 'name' => 'Girokonto', ...$eingabe]));
            self::assertSame(422, $antwort->status, $meldung);
            self::assertStringContainsString($meldung, $antwort->body);
            self::assertMatchesRegularExpression('/id="konto-' . $feld . '"[^>]*aria-invalid="true"/', $antwort->body, $feld . ' is marked');
        }

        self::assertSame([], $this->konten->all());
    }

    public function testAnIbanBelongsToOneAccountOnly(): void
    {
        $erstes = $this->bankkonto('Girokonto Sparkasse', self::IBAN_1);

        $antwort = $this->post('/app/konten', $this->felder(['art' => 'bank', 'name' => 'Doppelt', 'iban' => 'DE89 3704 0044 0532 0130 00']));
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Diese IBAN gehört bereits zum Konto „Girokonto Sparkasse“.', $antwort->body);
        self::assertStringContainsString('href="/app/konten/' . $erstes . '"', $antwort->body);
        self::assertCount(1, $this->konten->all());

        // Saving the account with its own IBAN is no conflict.
        $speichern = $this->post('/app/konten/' . $erstes, $this->felder(['name' => 'Girokonto', 'iban' => self::IBAN_1, 'active' => '1']));
        self::assertSame(302, $speichern->status);
    }

    /**
     * The audit log keeps the names of the changed fields - never a value,
     * never an amount - and nothing at all when nothing changed. The kind is
     * fixed once the account exists.
     */
    public function testChangingAnAccountLogsOnlyTheNamesOfTheChangedFields(): void
    {
        $id = $this->bankkonto('Girokonto', self::IBAN_1);

        $antwort = $this->post('/app/konten/' . $id, $this->felder([
            'art' => 'kasse',
            'name' => 'Girokonto VR Bank',
            'iban' => self::IBAN_2,
            'opening_balance' => '99,95',
            'opening_date' => '2026-01-01',
            'active' => '1',
        ]));
        self::assertSame(302, $antwort->status);
        self::assertSame('ok', $_SESSION['flash']['art'] ?? null);

        $konto = $this->konto($id);
        self::assertSame(BankAccountKind::Bank, $konto->kind, 'the kind does not change');
        self::assertSame('Girokonto VR Bank', $konto->data->name);
        self::assertSame(9995, $konto->openingBalance);
        self::assertSame($this->tresor->blindIndex()->forValue('bank_account.iban', self::IBAN_2), $this->konten->find($id)?->ibanBi);

        // Unchanged: no second row, and the row is not even written.
        $this->pdo()->exec("UPDATE bank_account SET updated_at = '2026-01-02 03:04:05' WHERE id = " . $id);
        $this->post('/app/konten/' . $id, $this->felder([
            'name' => 'Girokonto VR Bank', 'iban' => self::IBAN_2, 'opening_balance' => '99,95', 'opening_date' => '2026-01-01', 'active' => '1',
        ]));
        self::assertSame('2026-01-02 03:04:05', $this->konten->find($id)?->updatedAt->format('Y-m-d H:i:s'));

        $zeilen = $this->protokoll('bank_account', $id);
        self::assertSame(
            [AuditAction::KontoGeaendert->value, AuditAction::KontoAngelegt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $zeilen),
        );
        self::assertSame(['felder' => ['name', 'iban', 'opening_balance']], $this->audit->details($zeilen[0], $this->tresor));
    }

    /**
     * A recorded cash count was measured against the opening balance of its
     * time: the opening date may move up to the first count, not past it.
     */
    public function testTheOpeningDateStaysOnOrBeforeTheFirstCashCount(): void
    {
        $kasse = $this->kasse('Barkasse', '100,00');
        $zaehltag = new \DateTimeImmutable('today -10 days');
        $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'speichern', 'datum' => $zaehltag->format('Y-m-d'), 'ist' => '100,00', 'notiz' => '']);
        $felder = ['name' => 'Barkasse', 'opening_balance' => '100,00', 'active' => '1'];

        $danach = $this->post('/app/konten/' . $kasse, $this->felder([...$felder, 'opening_date' => $zaehltag->modify('+1 day')->format('Y-m-d')]));
        self::assertSame(422, $danach->status);
        self::assertStringContainsString('Der Stichtag kann nicht nach dem ersten Kassensturz (' . $zaehltag->format('d.m.Y') . ') liegen.', $danach->body);
        self::assertMatchesRegularExpression('/id="konto-stichtag"[^>]*aria-invalid="true"/', $danach->body);
        self::assertSame('2026-01-01', $this->konto($kasse)->openingDate->format('Y-m-d'));

        $amZaehltag = $this->post('/app/konten/' . $kasse, $this->felder([...$felder, 'opening_date' => $zaehltag->format('Y-m-d')]));
        self::assertSame(302, $amZaehltag->status);
        self::assertSame($zaehltag->format('Y-m-d'), $this->konto($kasse)->openingDate->format('Y-m-d'));
    }

    /**
     * "Löschen nur ohne Verwendung, sonst deaktivieren": a cash box with a
     * cash count stays - the service refuses, and the foreign key alone
     * would too - and is deactivated instead.
     */
    public function testAnAccountInUseIsDeactivatedInsteadOfDeleted(): void
    {
        $kasse = $this->kasse('Barkasse', '100,00');
        $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'speichern', 'datum' => $this->heute(), 'ist' => '100,00', 'notiz' => '']);

        $seite = $this->get('/app/konten/' . $kasse)->body;
        self::assertStringContainsString('wird verwendet und lässt sich deshalb nicht löschen', $seite);
        self::assertStringNotContainsString('/loeschen', $seite);

        $antwort = $this->post('/app/konten/' . $kasse . '/loeschen', []);
        self::assertSame(302, $antwort->status);
        self::assertSame('fehler', $_SESSION['flash']['art'] ?? null);
        self::assertNotNull($this->konten->find($kasse));

        try {
            $this->pdo()->exec('DELETE FROM bank_account WHERE id = ' . $kasse);
            self::fail('The foreign key must refuse to delete a cash box with counts.');
        } catch (\PDOException) {
        }

        $this->post('/app/konten/' . $kasse, $this->felder(['name' => 'Barkasse', 'opening_balance' => '100,00', 'opening_date' => '2026-01-01', 'active' => '']));
        self::assertFalse($this->konto($kasse)->active);
        self::assertSame(['felder' => ['active']], $this->audit->details($this->protokoll('bank_account', $kasse)[0], $this->tresor));

        self::assertStringContainsString('<span class="marke">inaktiv</span>', $this->get('/app/konten')->body);
        self::assertStringContainsString('Diese Kasse ist deaktiviert.', $this->get('/app/konten/' . $kasse)->body);
    }

    public function testAnUnusedAccountIsDeleted(): void
    {
        $id = $this->bankkonto('Sparbuch', '');

        $antwort = $this->post('/app/konten/' . $id . '/loeschen', []);

        self::assertSame(302, $antwort->status);
        self::assertSame('/app/konten', $antwort->headers['Location'] ?? null);
        self::assertSame([], $this->konten->all());
        $zeilen = $this->protokoll('bank_account', $id);
        self::assertSame(AuditAction::KontoGeloescht->value, $zeilen[0]->action);
        self::assertSame(['art' => 'bank'], $this->audit->details($zeilen[0], $this->tresor));
    }

    /**
     * Vorstand, Kassenprüfer and Steuerberater hold `bank.view` only: they
     * see the accounts, but no form, no delete and no cash count button.
     */
    public function testReadersWithoutBankBookSeeTheAccountsReadOnly(): void
    {
        $bank = $this->bankkonto('Girokonto Sparkasse', self::IBAN_1);
        $kasse = $this->kasse('Barkasse', '80,00');
        $this->rolle(SystemRole::Vorstand);

        $liste = $this->get('/app/konten');
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('Girokonto Sparkasse', $liste->body);
        self::assertStringNotContainsString('/app/konten/neu', $liste->body);

        $seite = $this->get('/app/konten/' . $bank);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('DE89 3704 0044 0532 0130 00', $seite->body);
        self::assertStringNotContainsString('<form method="post" action="/app/konten', $seite->body);

        $kassenSeite = $this->get('/app/konten/' . $kasse)->body;
        self::assertStringContainsString('Kassenstürze', $kassenSeite);
        self::assertStringNotContainsString('/kassensturz"', $kassenSeite);

        self::assertSame(403, $this->post('/app/konten/' . $bank, $this->felder(['name' => 'X']))->status);
        self::assertSame(403, $this->get('/app/konten/' . $kasse . '/kassensturz')->status);
    }

    /**
     * Without the unlocked vault there is nothing to show and nothing is
     * written - the names, IBANs and amounts are vault data.
     */
    public function testWithoutTheVaultThePagesShowAndWriteNothing(): void
    {
        $id = $this->bankkonto('Girokonto Sparkasse', self::IBAN_1);

        $liste = $this->get('/app/konten', entsperrt: false);
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $liste->body);
        self::assertStringNotContainsString('/app/konten/' . $id, $liste->body);

        self::assertSame('/app/konten', $this->get('/app/konten/' . $id, entsperrt: false)->headers['Location'] ?? null);

        $antwort = $this->post('/app/konten', $this->felder(['art' => 'bank', 'name' => 'Neu']), entsperrt: false);
        self::assertSame(302, $antwort->status);
        self::assertSame('fehler', $_SESSION['flash']['art'] ?? null);
        self::assertCount(1, $this->konten->all());
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $antwort = $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/konten',
            cookies: $this->tresorCookie(),
            post: [...$this->felder(['art' => 'bank', 'name' => 'Girokonto']), '_csrf' => 'falsch'],
        ));

        self::assertSame(302, $antwort->status);
        self::assertSame([], $this->konten->all());
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        $bank = $this->bankkonto('Girokonto', self::IBAN_1);

        self::assertSame(404, $this->get('/app/konten/999')->status);
        self::assertSame(404, $this->get('/app/konten/999/kassensturz')->status);
        self::assertSame(404, $this->get('/app/konten/' . $bank . '/kassensturz')->status, 'a bank account has no cash count');
        self::assertSame(404, $this->post('/app/konten/' . $bank . '/kassensturz', ['aktion' => 'speichern', 'datum' => $this->heute(), 'ist' => '1,00'])->status);

        // Deleting an unknown account answers 404 directly - no error left
        // behind for whatever page comes next.
        unset($_SESSION['flash']);
        self::assertSame(404, $this->post('/app/konten/999/loeschen', [])->status);
        self::assertArrayNotHasKey('flash', $_SESSION);
    }

    /**
     * Only structure in plaintext: no column that could hold a name, an
     * IBAN or an amount outside the ciphertexts.
     */
    public function testTheTablesKeepOnlyStructureInPlaintext(): void
    {
        self::assertSame(
            ['id', 'kind', 'dek_sealed', 'data_enc', 'iban_bi', 'opening_balance_enc', 'opening_date', 'active', 'created_at', 'updated_at'],
            $this->spalten('bank_account'),
        );
        self::assertSame(
            ['id', 'account_id', 'counted_on', 'dek_sealed', 'data_enc', 'created_by', 'created_at'],
            $this->spalten('cash_count'),
        );
    }

    // ------------------------------------------------------ cash count

    /**
     * Acceptance criterion "Kassensturz mit Differenzanzeige": the form
     * shows the expected amount, "Differenz berechnen" shows shortfall,
     * surplus or a match without storing anything.
     */
    public function testTheCashCountShowsTheDifferenceBeforeSaving(): void
    {
        $kasse = $this->kasse('Barkasse', '150,00');

        $formular = $this->get('/app/konten/' . $kasse . '/kassensturz');
        self::assertSame(200, $formular->status);
        self::assertStringContainsString('150,00 €', $formular->body);

        $faelle = [
            ['142,50', 'Fehlbetrag', '-7,50 €'],
            ['150', 'Kasse stimmt', '0,00 €'],
            ['151,20', 'Überschuss', '1,20 €'],
        ];
        foreach ($faelle as [$ist, $ergebnis, $differenz]) {
            $antwort = $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'berechnen', 'datum' => $this->heute(), 'ist' => $ist, 'notiz' => '']);
            self::assertSame(200, $antwort->status, $ist);
            self::assertStringContainsString($ergebnis, $antwort->body);
            self::assertStringContainsString('<th scope="row">Differenz</th><td class="zahl">' . $differenz . '</td>', $antwort->body);
            self::assertStringContainsString('noch nicht gespeichert', $antwort->body);
        }

        self::assertSame(0, $this->anzahlKassenstuerze());
    }

    /**
     * Saving records expected and counted amount encrypted, with the
     * expected amount computed on the server - a posted one is ignored. The
     * audit row names the count, never an amount.
     */
    public function testSavingACashCountRecordsItEncrypted(): void
    {
        $kasse = $this->kasse('Barkasse', '150,00');

        $antwort = $this->post('/app/konten/' . $kasse . '/kassensturz', [
            'aktion' => 'speichern',
            'datum' => $this->heute(),
            'ist' => '142,50',
            'notiz' => 'Gezählt von der Kassenwartin',
            'soll' => '142,50',
        ]);

        self::assertSame(302, $antwort->status);
        $zaehlungId = (int) $this->pdo()->query('SELECT MAX(id) FROM cash_count')->fetchColumn();
        self::assertSame('/app/buchungen/neu?kassensturz=' . $zaehlungId, $antwort->headers['Location'] ?? null, 'a difference goes on to the suggested booking (M9-5)');
        self::assertSame('Kassensturz gespeichert.', $_SESSION['flash']['text'] ?? null);

        $bereich = new UserAccessRepository($this->pdo())->berechtigungen($this->userId)->zugriffsbereich(Permission::BankView);
        $zaehlungen = $this->kassensturz()->liste($this->tresor, $this->konto($kasse), $bereich);
        self::assertCount(1, $zaehlungen);
        self::assertSame(15000, $zaehlungen[0]->expected);
        self::assertSame(14250, $zaehlungen[0]->counted);
        self::assertSame(-750, $zaehlungen[0]->differenz());
        self::assertSame('Gezählt von der Kassenwartin', $zaehlungen[0]->note);
        self::assertSame($this->heute(), $zaehlungen[0]->countedOn->format('Y-m-d'));
        self::assertSame($this->userId, $zaehlungen[0]->createdBy);

        $roh = $this->rohTabelle('cash_count');
        foreach (['Gezählt', 'Kassenwartin', '14250', '142,50', '15000'] as $klartext) {
            self::assertStringNotContainsString($klartext, $roh, $klartext . ' is not stored in the clear');
        }

        $seite = $this->get('/app/konten/' . $kasse)->body;
        self::assertStringContainsString('<span class="marke marke-fehler">Fehlbetrag</span>', $seite);
        self::assertStringContainsString('-7,50 €', $seite);

        $zeilen = $this->protokoll('cash_count', $zaehlungen[0]->id);
        self::assertSame([AuditAction::KassensturzErfasst->value], array_map(static fn(AuditEntry $e): string => $e->action, $zeilen));
        self::assertNull($zeilen[0]->detailsEnc, 'no amount in the audit log');
    }

    public function testACashCountOutsideItsRulesIsRefused(): void
    {
        $kasse = $this->kasse('Barkasse', '100,00', stichtag: '2026-01-01');

        $faelle = [
            [['datum' => '2025-12-31', 'ist' => '1,00'], 'nicht vor dem Stichtag des Anfangsbestands (01.01.2026)', 'datum'],
            [['datum' => new \DateTimeImmutable('tomorrow')->format('Y-m-d'), 'ist' => '1,00'], 'nicht in der Zukunft', 'datum'],
            [['datum' => '', 'ist' => '1,00'], 'Bitte das Datum', 'datum'],
            [['datum' => $this->heute(), 'ist' => ''], 'Den gezählten Bestand bitte als Betrag', 'ist'],
            [['datum' => $this->heute(), 'ist' => '-3,00'], 'nicht negativ', 'ist'],
            [['datum' => $this->heute(), 'ist' => '1,00', 'notiz' => str_repeat('x', 501)], 'höchstens 500 Zeichen', 'notiz'],
        ];
        foreach ($faelle as [$eingabe, $meldung, $feld]) {
            $antwort = $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'speichern', 'notiz' => '', ...$eingabe]);
            self::assertSame(422, $antwort->status, $meldung);
            self::assertStringContainsString($meldung, $antwort->body);
            self::assertMatchesRegularExpression('/id="kassensturz-' . $feld . '"[^>]*aria-invalid="true"/s', $antwort->body, $feld . ' is marked');
        }

        // A deactivated cash box takes no count.
        $this->post('/app/konten/' . $kasse, $this->felder(['name' => 'Barkasse', 'opening_balance' => '100,00', 'opening_date' => '2026-01-01', 'active' => '']));
        self::assertSame('/app/konten/' . $kasse, $this->get('/app/konten/' . $kasse . '/kassensturz')->headers['Location'] ?? null);
        $inaktiv = $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'speichern', 'datum' => $this->heute(), 'ist' => '1,00', 'notiz' => '']);
        self::assertSame(422, $inaktiv->status);
        self::assertStringContainsString('Die Kasse ist deaktiviert', $inaktiv->body);

        self::assertSame(0, $this->anzahlKassenstuerze());
    }

    /**
     * The period scope of external accounts (docs/spec/01-sicherheit.md
     * section 4) narrows the cash counts in SQL, by their date.
     */
    public function testThePeriodScopeHidesOlderCashCounts(): void
    {
        $vor2Jahren = new \DateTimeImmutable('today -2 years');
        $kasse = $this->kasse('Barkasse', '100,00', stichtag: $vor2Jahren->format('Y-m-d'));
        $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'speichern', 'datum' => new \DateTimeImmutable('today -1 year')->format('Y-m-d'), 'ist' => '91,00', 'notiz' => '']);
        $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'speichern', 'datum' => $this->heute(), 'ist' => '102,00', 'notiz' => '']);
        self::assertSame(2, $this->anzahlKassenstuerze());

        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('today -30 days'), null);

        $seite = $this->get('/app/konten/' . $kasse)->body;
        self::assertStringContainsString('102,00 €', $seite);
        self::assertStringNotContainsString('91,00 €', $seite);
        self::assertStringContainsString('1 Kassensturz,', $seite);
    }

    /**
     * The period scope covers the opening balance too: it is the balance of
     * the opening date. An external reader whose period starts later sees
     * the account, but not that amount.
     */
    public function testReadersOutsideTheirPeriodDoNotSeeTheOpeningBalance(): void
    {
        $alt = $this->bankkonto('Girokonto Sparkasse', self::IBAN_1);
        $antwort = $this->post('/app/konten', $this->felder(['art' => 'bank', 'name' => 'Tagesgeld', 'opening_balance' => '777,00', 'opening_date' => $this->heute()]));
        $neu = (int) substr($antwort->headers['Location'] ?? '', strlen('/app/konten/'));
        $this->rolle(SystemRole::Steuerberater);
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('today -30 days'), null);

        $liste = $this->get('/app/konten')->body;
        self::assertStringContainsString('Girokonto Sparkasse', $liste);
        self::assertStringContainsString('außerhalb Ihres Zeitraums', $liste);
        self::assertStringNotContainsString('500,00', $liste);
        self::assertStringContainsString('777,00 €', $liste);

        $seite = $this->get('/app/konten/' . $alt)->body;
        self::assertStringNotContainsString('500,00', $seite);
        self::assertStringContainsString('Der Stichtag liegt außerhalb Ihres Zeitraums', $seite);
        self::assertStringContainsString('value="777,00"', $this->get('/app/konten/' . $neu)->body);
    }

    // ---------------------------------------------------------- helpers

    private function rolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    /**
     * @param array<string, string> $felder
     *
     * @return array<string, string>
     */
    private function felder(array $felder): array
    {
        return [...[
            'name' => '', 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01',
        ], ...$felder];
    }

    private function bankkonto(string $name, string $iban): int
    {
        $antwort = $this->post('/app/konten', $this->felder(['art' => 'bank', 'name' => $name, 'iban' => $iban, 'opening_balance' => '500,00']));
        self::assertSame(302, $antwort->status);

        return (int) substr($antwort->headers['Location'] ?? '', strlen('/app/konten/'));
    }

    private function kasse(string $name, string $bestand, string $stichtag = '2026-01-01'): int
    {
        $antwort = $this->post('/app/konten', $this->felder(['art' => 'kasse', 'name' => $name, 'opening_balance' => $bestand, 'opening_date' => $stichtag]));
        self::assertSame(302, $antwort->status);

        return (int) substr($antwort->headers['Location'] ?? '', strlen('/app/konten/'));
    }

    private function einzigesKonto(): int
    {
        $alle = $this->konten->all();
        self::assertCount(1, $alle);

        return $alle[0]->id;
    }

    private function konto(int $id): BankAccount
    {
        return $this->service()->finde($this->tresor, $id) ?? self::fail('No account ' . $id);
    }

    private function heute(): string
    {
        return new \DateTimeImmutable()->format('Y-m-d');
    }

    private function anzahlKassenstuerze(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM cash_count')->fetchColumn();
    }

    /**
     * @return list<AuditEntry> newest first
     */
    private function protokoll(string $entity, int $id): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: $entity, entityId: $id), null, 10);
    }

    private function service(): BankAccountService
    {
        return new BankAccountService($this->pdo(), $this->konten);
    }

    private function kassensturz(): Kassensturz
    {
        $buchungen = new Buchungen($this->pdo(), new BankTransactionRepository($this->pdo()), $this->service(), new CategoryRepository($this->pdo()));

        return new Kassensturz($this->pdo(), new CashCountRepository($this->pdo()), $buchungen);
    }

    /**
     * @return list<string>
     */
    private function spalten(string $tabelle): array
    {
        $stmt = $this->pdo()->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
        $stmt->execute([$tabelle]);

        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
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
     * @param array<string, string> $felder
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
        $konten = fn(): AccountController => new AccountController(
            $view,
            new Session(),
            new SessionVault(),
            $this->service(),
            $this->kassensturz(),
            $this->audit,
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the account pages 28th.
        $controller = array_fill(0, 34, $unerreichbar);
        $controller[1] = $guard;
        $controller[27] = $konten;

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
