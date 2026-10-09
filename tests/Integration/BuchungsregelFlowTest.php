<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\AccountController;
use App\App\BuchungController;
use App\App\BuchungsregelController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSetBy;
use App\Domain\BankTransactionSource;
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
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\CashCountRepository;
use App\Repository\CategoryRepository;
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
use App\Service\Bank\BelegStandard;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Buchungsregeln;
use App\Service\Bank\Kassensturz;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * Rules "kein Beleg nötig" / category and setting a booking by hand, end to
 * end (M9-6, issue #64, docs/spec/04-bank-und-abgleich.md section 5 "Stand
 * M9-6"): the real route table, guard, controllers, services, repositories
 * and schema, with a real vault.
 *
 * Acceptance criteria: rules on purpose/counterparty, a category set right
 * at a booking, the effect of a rule traceable and reversible. The import
 * side is KontoauszugImportFlowTest::testTheImportAppliesTheOldestMatchingRule(),
 * the matching itself RegelabgleichTest.
 */
final class BuchungsregelFlowTest extends DatabaseTestCase
{
    private const string IBAN_SPARKASSE = 'DE89370400440532013000';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $konto;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), new ServerCrypto(random_bytes(32)));

        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $this->rolle(SystemRole::Finanzen);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];

        $this->konto = new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen($this->tresor, \App\Domain\BankAccountKind::Bank, [
            'name' => 'Girokonto', 'iban' => '', 'opening_balance' => '0,00', 'opening_date' => '2024-01-01',
        ], new \DateTimeImmutable())->id;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ------------------------------------------------------------ rules

    public function testARuleIsStoredEncryptedAndAuditedWithoutItsPattern(): void
    {
        $antwort = $this->post('/app/buchungen/regeln', $this->regelFelder([
            'bezeichnung' => 'Kontoführung Sparkasse',
            'stichwort' => 'Entgeltabschluss',
            'gegenseite' => 'de89 3704 0044 0532 0130 00',
            'richtung' => 'ausgabe',
            'kategorie' => (string) $this->kategorie('Bankgebühren'),
        ]));

        self::assertSame(302, $antwort->status, $antwort->body);
        $id = (int) substr($antwort->headers['Location'] ?? '', strlen('/app/buchungen/regeln/'));
        self::assertGreaterThan(0, $id);

        $regel = $this->regeln()->finde($this->tresor, $id) ?? self::fail();
        self::assertSame('Kontoführung Sparkasse', $regel->label);
        self::assertSame('Entgeltabschluss', $regel->stichwort);
        self::assertSame(self::IBAN_SPARKASSE, $regel->gegenseite, 'an IBAN is stored normalised');
        self::assertSame(BankTransactionDirection::Ausgabe, $regel->direction);
        self::assertTrue($regel->noReceipt);
        self::assertSame($this->kategorie('Bankgebühren'), $regel->categoryId);
        self::assertTrue($regel->active);

        $roh = $this->rohTabelle('assignment_rule');
        foreach (['Kontoführung', 'Sparkasse', 'Entgelt', 'DE89', '0532013000'] as $klartext) {
            self::assertStringNotContainsString($klartext, $roh, $klartext . ' is not stored in the clear');
        }

        $protokoll = $this->protokoll('assignment_rule', $id);
        self::assertSame([AuditAction::RegelAngelegt->value], array_map(static fn(AuditEntry $e): string => $e->action, $protokoll));
        self::assertSame(['richtung' => 'ausgabe', 'kein_beleg' => true, 'kategorie' => $this->kategorie('Bankgebühren')], $this->audit->details($protokoll[0], $this->tresor));

        $liste = $this->get('/app/buchungen/regeln')->body;
        self::assertStringContainsString('Kontoführung Sparkasse', $liste);
        self::assertStringContainsString('Zweck enthält „Entgeltabschluss“', $liste);
        self::assertStringContainsString('IBAN der Gegenseite DE89 3704 0044 0532 0130 00', $liste);
        self::assertStringContainsString('nur Ausgaben', $liste);
        self::assertStringContainsString('Bankgebühren', $liste);
    }

    public function testInvalidRulesAreRefusedAndTheFieldMarked(): void
    {
        $faelle = [
            [['bezeichnung' => ''], 'bezeichnung', 'einen Namen geben'],
            [['stichwort' => '', 'gegenseite' => ''], 'stichwort', 'sonst träfe die Regel jede Buchung'],
            [['stichwort' => 'ab'], 'stichwort', 'mindestens 3 Zeichen'],
            [['gegenseite' => 'DE00 3704 0044 0532 0130 00'], 'gegenseite', 'IBAN der Gegenseite ist ungültig'],
            [['kein_beleg' => '', 'kategorie' => ''], 'kein_beleg', 'was die Regel tun soll'],
            [['richtung' => '', 'kategorie' => (string) $this->kategorie('Bankgebühren')], 'richtung', 'Richtung der Regel passend wählen'],
            [['richtung' => 'einnahme', 'kategorie' => (string) $this->kategorie('Bankgebühren')], 'kategorie', 'die Regel gilt für Einnahmen'],
            [['richtung' => 'quer'], 'richtung', 'gültige Richtung'],
        ];
        foreach ($faelle as [$felder, $feld, $meldung]) {
            $antwort = $this->post('/app/buchungen/regeln', $this->regelFelder($felder));
            self::assertSame(422, $antwort->status, $feld);
            self::assertStringContainsString($meldung, $antwort->body);
            self::assertMatchesRegularExpression('/name="' . $feld . '"[^>]*aria-invalid="true"/', $antwort->body, $feld . ' is marked');
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM assignment_rule')->fetchColumn());
    }

    /**
     * Applying a rule on request acts on imported bookings no rule has
     * touched yet - and leaves alone what a person set, manual bookings and
     * an allocated receipt.
     */
    public function testARuleIsAppliedToExistingBookingsOnRequestAndSparesWhatPeopleSet(): void
    {
        $gebuehr = $this->importierteBuchung('2026-01-31', -350, 'Entgelt Kontoführung 01/2026', 'Sparkasse');
        $vonHand = $this->importierteBuchung('2026-02-28', -350, 'Entgelt Kontoführung 02/2026', 'Sparkasse');
        $zugeordnet = $this->importierteBuchung('2026-03-31', -350, 'Entgelt Kontoführung 03/2026', 'Sparkasse', BankTransactionDocStatus::Zugeordnet);
        $fremd = $this->importierteBuchung('2026-03-02', -2500, 'Rechnung 4711 Platzpflege', 'Rasen GmbH');
        $einnahme = $this->importierteBuchung('2026-03-03', 900, 'Entgelt Kontoführung Erstattung', 'Sparkasse');
        $manuell = $this->manuelleBuchung('Entgelt Kontoführung bar');

        // A person decided the second one needs a receipt.
        self::assertSame(302, $this->post('/app/buchungen/' . $vonHand . '/einordnung', ['kategorie' => '', 'beleg' => Buchungen::BELEG_NOETIG])->status);

        $id = $this->regelAnlegen(['stichwort' => 'kontoführung', 'richtung' => 'ausgabe', 'kategorie' => (string) $this->kategorie('Bankgebühren')]);
        self::assertSame(BankTransactionDocStatus::Fehlt, $this->zeile($gebuehr)['doc_status'], 'creating a rule changes no existing booking');

        $seite = $this->get('/app/buchungen/regeln/' . $id)->body;
        self::assertStringContainsString('Auf 3 Buchungen anwenden', $seite);

        $antwort = $this->post('/app/buchungen/regeln/' . $id . '/anwenden', []);
        self::assertSame('/app/buchungen/regeln/' . $id, $antwort->headers['Location'] ?? null);
        self::assertSame('Die Regel wurde auf 3 Buchungen angewendet.', $_SESSION['flash']['text'] ?? null);

        $kategorie = $this->kategorie('Bankgebühren');
        self::assertSame([false, BankTransactionDocStatus::NichtNoetig, BankTransactionSetBy::Regel, $kategorie, BankTransactionSetBy::Regel, $id], $this->stand($gebuehr));
        self::assertSame([true, BankTransactionDocStatus::Fehlt, BankTransactionSetBy::Manuell, $kategorie, BankTransactionSetBy::Regel, $id], $this->stand($vonHand), 'the receipt set by hand stays, the empty category is filled');
        self::assertSame([false, BankTransactionDocStatus::Zugeordnet, BankTransactionSetBy::Regel, $kategorie, BankTransactionSetBy::Regel, $id], $this->stand($zugeordnet), 'an allocated receipt stays allocated');
        self::assertSame([true, BankTransactionDocStatus::Fehlt, BankTransactionSetBy::Standard, null, null, null], $this->stand($fremd));
        self::assertSame([false, BankTransactionDocStatus::NichtNoetig, BankTransactionSetBy::Standard, null, null, null], $this->stand($einnahme), 'expenses only');
        self::assertNull($this->zeile($manuell)['rule_id'], 'manual bookings are never touched');

        self::assertStringContainsString('Unter den bestehenden Buchungen ist keine weitere', $this->get('/app/buchungen/regeln/' . $id)->body);
        self::assertSame(0, $this->regeln()->anwenden($this->tresor, $this->regeln()->finde($this->tresor, $id) ?? self::fail(), \App\Domain\Zugriffsbereich::unbeschraenkt(), new \DateTimeImmutable()), 'applying twice changes nothing');

        // Traceable: the list of the rule, the mark, the booking names the rule.
        $liste = $this->get('/app/buchungen', ['regel' => (string) $id, 'von' => '', 'bis' => ''])->body;
        self::assertStringContainsString('nur die Buchungen gezeigt, die <a href="/app/buchungen/regeln/' . $id . '">diese Regel</a>', $liste);
        self::assertSame(3, substr_count($liste, '<span class="marke">Regel</span>'));
        self::assertStringNotContainsString('Rasen GmbH', $liste);
        self::assertStringContainsString('>3</a>', $this->get('/app/buchungen/regeln')->body);
        $buchung = $this->get('/app/buchungen/' . $gebuehr)->body;
        self::assertStringContainsString('(durch Regel <a href="/app/buchungen/regeln/' . $id . '">„Kontoführung“</a>)', $buchung);

        $protokoll = $this->protokoll('assignment_rule', $id);
        self::assertSame(AuditAction::RegelAngewendet->value, $protokoll[0]->action);
        self::assertSame(['buchungen' => 3], $this->audit->details($protokoll[0], $this->tresor));
    }

    public function testDeactivatingAndDeletingTakeTheEffectBack(): void
    {
        $gebuehr = $this->importierteBuchung('2026-01-31', -350, 'Entgelt Kontoführung', 'Sparkasse');
        $zugeordnet = $this->importierteBuchung('2026-02-28', -350, 'Entgelt Kontoführung', 'Sparkasse', BankTransactionDocStatus::Zugeordnet);
        $id = $this->regelAnlegen(['stichwort' => 'Kontoführung', 'richtung' => 'ausgabe', 'kategorie' => (string) $this->kategorie('Bankgebühren')]);
        $this->post('/app/buchungen/regeln/' . $id . '/anwenden', []);

        $this->post('/app/buchungen/regeln/' . $id . '/aktiv', ['aktiv' => '0']);
        self::assertSame('Regel deaktiviert. Ihre Wirkung auf 2 Buchungen wurde zurückgenommen.', $_SESSION['flash']['text'] ?? null);
        self::assertSame([true, BankTransactionDocStatus::Fehlt, BankTransactionSetBy::Standard, null, null, null], $this->stand($gebuehr));
        self::assertSame([true, BankTransactionDocStatus::Zugeordnet, BankTransactionSetBy::Standard, null, null, null], $this->stand($zugeordnet));
        $seite = $this->get('/app/buchungen/regeln/' . $id)->body;
        self::assertStringContainsString('Diese Regel ist deaktiviert', $seite);
        self::assertStringNotContainsString('/anwenden"', $seite);
        $this->post('/app/buchungen/regeln/' . $id . '/anwenden', []);
        self::assertSame('Die Regel ist deaktiviert – erst aktivieren, dann anwenden.', $_SESSION['flash']['text'] ?? null);

        $this->post('/app/buchungen/regeln/' . $id . '/aktiv', ['aktiv' => '1']);
        self::assertNull($this->stand($gebuehr)[5], 'activating does not apply by itself');
        $this->post('/app/buchungen/regeln/' . $id . '/anwenden', []);
        self::assertSame($id, $this->stand($gebuehr)[5]);

        $antwort = $this->post('/app/buchungen/regeln/' . $id . '/loeschen', []);
        self::assertSame('/app/buchungen/regeln', $antwort->headers['Location'] ?? null);
        self::assertSame('Regel gelöscht. Ihre Wirkung auf 2 Buchungen wurde zurückgenommen.', $_SESSION['flash']['text'] ?? null);
        self::assertSame([true, BankTransactionDocStatus::Fehlt, BankTransactionSetBy::Standard, null, null, null], $this->stand($gebuehr));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM assignment_rule')->fetchColumn());

        self::assertSame(
            [AuditAction::RegelGeloescht->value, AuditAction::RegelAngewendet->value, AuditAction::RegelAktiviert->value,
                AuditAction::RegelDeaktiviert->value, AuditAction::RegelAngewendet->value, AuditAction::RegelAngelegt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $this->protokoll('assignment_rule', $id)),
        );
    }

    /** Taking a rule back returns income to the default of the setting. */
    public function testTakingARuleBackFollowsTheIncomeSetting(): void
    {
        $zinsen = $this->importierteBuchung('2026-03-31', 120, 'Zinsen 1. Quartal', 'Sparkasse');
        new SettingRepository($this->pdo())->set(BelegStandard::SETTING_EINNAHME, '1');
        $id = $this->regelAnlegen(['stichwort' => 'Zinsen', 'richtung' => 'einnahme', 'kategorie' => '']);
        $this->post('/app/buchungen/regeln/' . $id . '/anwenden', []);
        self::assertSame([false, BankTransactionDocStatus::NichtNoetig, BankTransactionSetBy::Regel, null, null, $id], $this->stand($zinsen));

        $this->post('/app/buchungen/regeln/' . $id . '/loeschen', []);
        self::assertSame([true, BankTransactionDocStatus::Fehlt, BankTransactionSetBy::Standard, null, null, null], $this->stand($zinsen));
    }

    public function testChangingARuleAppliesItAgainToTheBookingsItHadTouched(): void
    {
        $januar = $this->importierteBuchung('2026-01-31', -350, 'Entgelt Kontoführung 01', 'Sparkasse');
        $februar = $this->importierteBuchung('2026-02-28', -350, 'Entgelt Porto 02', 'Sparkasse');
        $id = $this->regelAnlegen(['stichwort' => 'Entgelt', 'richtung' => 'ausgabe', 'kategorie' => (string) $this->kategorie('Bankgebühren')]);
        $this->post('/app/buchungen/regeln/' . $id . '/anwenden', []);

        $this->post('/app/buchungen/regeln/' . $id, $this->regelFelder([
            'stichwort' => 'Kontoführung', 'richtung' => 'ausgabe', 'kategorie' => (string) $this->kategorie('Sonstiges'),
        ]));
        self::assertSame(
            'Regel gespeichert. Die bisherige Wirkung auf 2 Buchungen wurde zurückgenommen, die geänderte Regel wirkt wieder auf 1 Buchung.',
            $_SESSION['flash']['text'] ?? null,
        );
        self::assertSame([false, BankTransactionDocStatus::NichtNoetig, BankTransactionSetBy::Regel, $this->kategorie('Sonstiges'), BankTransactionSetBy::Regel, $id], $this->stand($januar));
        self::assertSame([true, BankTransactionDocStatus::Fehlt, BankTransactionSetBy::Standard, null, null, null], $this->stand($februar), 'no longer matching');

        $protokoll = $this->protokoll('assignment_rule', $id);
        self::assertSame(AuditAction::RegelGeaendert->value, $protokoll[0]->action);
        self::assertSame(['felder' => ['stichwort', 'category_id'], 'zurueckgenommen' => 2, 'erneut' => 1], $this->audit->details($protokoll[0], $this->tresor));

        $this->post('/app/buchungen/regeln/' . $id, $this->regelFelder([
            'stichwort' => 'Kontoführung', 'richtung' => 'ausgabe', 'kategorie' => (string) $this->kategorie('Sonstiges'),
        ]));
        self::assertSame('Nichts geändert.', $_SESSION['flash']['text'] ?? null);
        self::assertCount(count($protokoll), $this->protokoll('assignment_rule', $id));
    }

    public function testTheRuleFormIsPrefilledFromABooking(): void
    {
        $buchung = $this->importierteBuchung('2026-01-31', -350, 'Entgelt Kontoführung', 'Sparkasse Musterstadt', iban: self::IBAN_SPARKASSE);

        $seite = $this->get('/app/buchungen/' . $buchung)->body;
        self::assertStringContainsString('href="/app/buchungen/regeln/neu?buchung=' . $buchung . '"', $seite);

        $formular = $this->get('/app/buchungen/regeln/neu', ['buchung' => (string) $buchung])->body;
        self::assertStringContainsString('name="bezeichnung" value="Sparkasse Musterstadt"', $formular);
        self::assertStringContainsString('name="gegenseite" value="DE89 3704 0044 0532 0130 00"', $formular);
        self::assertStringContainsString('<option value="ausgabe" selected>nur Ausgaben</option>', $formular);
        self::assertMatchesRegularExpression('/name="kein_beleg" value="1" checked/', $formular);
    }

    // ------------------------------------------------- at the booking

    public function testAnImportedBookingIsSetByHandAndRulesLeaveItAlone(): void
    {
        $id = $this->importierteBuchung('2026-01-31', -350, 'Entgelt Kontoführung', 'Sparkasse');
        $seite = $this->get('/app/buchungen/' . $id)->body;
        self::assertStringContainsString('action="/app/buchungen/' . $id . '/einordnung"', $seite);
        self::assertStringContainsString('(Standard für Ausgaben)', $seite);

        $antwort = $this->post('/app/buchungen/' . $id . '/einordnung', ['kategorie' => (string) $this->kategorie('Bankgebühren'), 'beleg' => Buchungen::BELEG_NICHT_NOETIG]);
        self::assertSame('/app/buchungen/' . $id, $antwort->headers['Location'] ?? null);
        self::assertSame([false, BankTransactionDocStatus::NichtNoetig, BankTransactionSetBy::Manuell, $this->kategorie('Bankgebühren'), BankTransactionSetBy::Manuell, null], $this->stand($id));
        self::assertStringContainsString('(von Hand gesetzt)', $this->get('/app/buchungen/' . $id)->body);

        $protokoll = $this->protokoll('bank_transaction', $id);
        self::assertSame(AuditAction::BuchungEingeordnet->value, $protokoll[0]->action);
        self::assertSame(['felder' => ['category_id', 'doc_required']], $this->audit->details($protokoll[0], $this->tresor));

        $regel = $this->regelAnlegen(['stichwort' => 'Kontoführung', 'richtung' => 'ausgabe', 'kategorie' => (string) $this->kategorie('Sonstiges')]);
        $this->post('/app/buchungen/regeln/' . $regel . '/anwenden', []);
        self::assertSame('Die Regel wurde auf 0 Buchungen angewendet.', $_SESSION['flash']['text'] ?? null);

        $this->post('/app/buchungen/' . $id . '/einordnung', ['kategorie' => (string) $this->kategorie('Bankgebühren'), 'beleg' => Buchungen::BELEG_NICHT_NOETIG]);
        self::assertSame('Nichts geändert.', $_SESSION['flash']['text'] ?? null);

        // "Standard" hands the receipt back to the default of the direction.
        $this->post('/app/buchungen/' . $id . '/einordnung', ['kategorie' => '', 'beleg' => '']);
        self::assertSame([true, BankTransactionDocStatus::Fehlt, BankTransactionSetBy::Standard, null, null, null], $this->stand($id));

        $falsch = $this->post('/app/buchungen/' . $id . '/einordnung', ['kategorie' => (string) $this->kategorie('Spenden'), 'beleg' => '']);
        self::assertSame(422, $falsch->status);
        self::assertStringContainsString('gehört zu den Einnahmen, die Buchung ist eine Ausgabe', $falsch->body);
    }

    /** Saving the form unchanged keeps the rule as the source of what it set. */
    public function testAnUnchangedFieldKeepsTheRuleAsItsSource(): void
    {
        $id = $this->importierteBuchung('2026-01-31', -350, 'Entgelt Kontoführung', 'Sparkasse');
        $regel = $this->regelAnlegen(['stichwort' => 'Kontoführung', 'richtung' => 'ausgabe', 'kategorie' => (string) $this->kategorie('Bankgebühren')]);
        $this->post('/app/buchungen/regeln/' . $regel . '/anwenden', []);

        self::assertStringContainsString('<option value="' . Buchungen::BELEG_NICHT_NOETIG . '" selected>kein Beleg nötig</option>', $this->get('/app/buchungen/' . $id)->body);
        $this->post('/app/buchungen/' . $id . '/einordnung', ['kategorie' => (string) $this->kategorie('Sonstiges'), 'beleg' => Buchungen::BELEG_NICHT_NOETIG]);
        self::assertSame([false, BankTransactionDocStatus::NichtNoetig, BankTransactionSetBy::Regel, $this->kategorie('Sonstiges'), BankTransactionSetBy::Manuell, $regel], $this->stand($id));

        // Taking the rule back now only touches what it still accounts for.
        $this->post('/app/buchungen/regeln/' . $regel . '/loeschen', []);
        self::assertSame([true, BankTransactionDocStatus::Fehlt, BankTransactionSetBy::Standard, $this->kategorie('Sonstiges'), BankTransactionSetBy::Manuell, null], $this->stand($id));
    }

    public function testAManualBookingIsNotSetThroughTheImportForm(): void
    {
        $id = $this->manuelleBuchung('Spende bar');
        self::assertStringNotContainsString('/einordnung"', $this->get('/app/buchungen/' . $id)->body);
        $antwort = $this->post('/app/buchungen/' . $id . '/einordnung', ['kategorie' => '', 'beleg' => '']);
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Eine manuelle Buchung wird über ihr Formular geändert.', $antwort->body);
    }

    // ---------------------------------------------------------- setting

    public function testTheIncomeDefaultCanBeSwitched(): void
    {
        self::assertStringContainsString('nach Richtung (Ausgabe: Beleg nötig, Einnahme: kein Beleg nötig)', $this->get('/app/buchungen/neu')->body);

        $this->post('/app/buchungen/regeln/standard', ['einnahme_beleg_noetig' => '1']);
        self::assertSame('1', new SettingRepository($this->pdo())->get(BelegStandard::SETTING_EINNAHME));
        self::assertStringContainsString('Neue Einnahmen brauchen ab jetzt einen Beleg', $_SESSION['flash']['text'] ?? '');
        self::assertStringContainsString('nach Richtung (Ausgabe: Beleg nötig, Einnahme: Beleg nötig)', $this->get('/app/buchungen/neu')->body);

        $id = $this->manuelleBuchung('Startgelder', 'einnahme');
        self::assertSame(BankTransactionDocStatus::Fehlt, $this->zeile($id)['doc_status']);

        $eintraege = new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 20);
        $einstellung = array_values(array_filter($eintraege, static fn(AuditEntry $e): bool => $e->action === AuditAction::EinstellungBelegStandard->value));
        self::assertCount(1, $einstellung);
        self::assertSame(['einnahme_beleg_noetig' => true], $this->audit->details($einstellung[0], $this->tresor));

        $this->post('/app/buchungen/regeln/standard', ['einnahme_beleg_noetig' => '1']);
        self::assertCount(1, array_filter(
            new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 20),
            static fn(AuditEntry $e): bool => $e->action === AuditAction::EinstellungBelegStandard->value,
        ), 'saving the same value records nothing');

        new SettingRepository($this->pdo())->set(BelegStandard::SETTING_EINNAHME, 'kaputt');
        self::assertFalse(BelegStandard::fromSettings(new SettingRepository($this->pdo()))->einnahmeBelegNoetig, 'anything but 1 is the default');
    }

    // ---------------------------------------------------- the rest

    public function testACategoryUsedByARuleIsInUse(): void
    {
        $kategorie = $this->kategorie('Bankgebühren');
        $vorher = new CategoryRepository($this->pdo())->usageCount($kategorie);
        $this->regelAnlegen(['stichwort' => 'Kontoführung', 'richtung' => 'ausgabe', 'kategorie' => (string) $kategorie]);

        self::assertSame($vorher + 1, new CategoryRepository($this->pdo())->usageCount($kategorie));
    }

    public function testReadersSeeTheRulesButCannotWrite(): void
    {
        $id = $this->regelAnlegen(['stichwort' => 'Kontoführung', 'richtung' => 'ausgabe', 'kategorie' => '']);
        $buchung = $this->importierteBuchung('2026-01-31', -350, 'Entgelt', 'Sparkasse');

        $this->rolle(SystemRole::Kassenpruefer);
        $liste = $this->get('/app/buchungen/regeln');
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('Kontoführung', $liste->body);
        self::assertStringNotContainsString('/app/buchungen/regeln/neu"', $liste->body);
        self::assertStringNotContainsString('name="einnahme_beleg_noetig"', $liste->body);

        $seite = $this->get('/app/buchungen/regeln/' . $id)->body;
        self::assertMatchesRegularExpression('/name="bezeichnung" value="Kontoführung" required\s+maxlength="100" disabled>/', $seite);
        self::assertStringNotContainsString('/loeschen"', $seite);
        self::assertStringNotContainsString('/einordnung"', $this->get('/app/buchungen/' . $buchung)->body);

        self::assertSame(403, $this->get('/app/buchungen/regeln/neu')->status);
        self::assertSame(403, $this->post('/app/buchungen/regeln/' . $id . '/loeschen', [])->status);
        self::assertSame(403, $this->post('/app/buchungen/' . $buchung . '/einordnung', ['kategorie' => '', 'beleg' => '0'])->status);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM assignment_rule')->fetchColumn());
    }

    /** The period scope narrows what "anwenden" reaches. */
    public function testApplyingStaysWithinThePeriodScope(): void
    {
        $alt = $this->importierteBuchung('2024-01-31', -350, 'Entgelt Kontoführung', 'Sparkasse');
        $neu = $this->importierteBuchung(new \DateTimeImmutable()->format('Y-m-d'), -350, 'Entgelt Kontoführung', 'Sparkasse');
        $id = $this->regelAnlegen(['stichwort' => 'Kontoführung', 'richtung' => 'ausgabe', 'kategorie' => '']);

        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('today -30 days'), null);
        $this->post('/app/buchungen/regeln/' . $id . '/anwenden', []);

        self::assertNull($this->stand($alt)[5]);
        self::assertSame($id, $this->stand($neu)[5]);
    }

    public function testWithoutTheVaultCsrfOrAnUnknownRuleNothingHappens(): void
    {
        $id = $this->regelAnlegen(['stichwort' => 'Geheimwort', 'richtung' => 'ausgabe', 'kategorie' => '']);

        $liste = $this->get('/app/buchungen/regeln', entsperrt: false);
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('Melden Sie sich neu an, um sie zu sehen.', $liste->body);
        self::assertStringNotContainsString('Geheimwort', $liste->body);
        self::assertSame('/app/buchungen/regeln', $this->get('/app/buchungen/regeln/' . $id, entsperrt: false)->headers['Location'] ?? null);
        $this->post('/app/buchungen/regeln/' . $id . '/loeschen', [], entsperrt: false);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM assignment_rule')->fetchColumn());

        $ohneToken = $this->dispatch(new Request(HttpMethod::Post, '/app/buchungen/regeln/' . $id . '/loeschen', cookies: $this->tresorCookie(), post: []));
        self::assertSame('/app/buchungen/regeln/' . $id, $ohneToken->headers['Location'] ?? null);
        $ohneToken = $this->dispatch(new Request(HttpMethod::Post, '/app/buchungen/regeln/standard', cookies: $this->tresorCookie(), post: ['einnahme_beleg_noetig' => '1']));
        self::assertSame('', new SettingRepository($this->pdo())->get(BelegStandard::SETTING_EINNAHME));
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM assignment_rule')->fetchColumn());

        self::assertSame(404, $this->get('/app/buchungen/regeln/999')->status);
        self::assertSame(404, $this->post('/app/buchungen/regeln/999', $this->regelFelder([]))->status);
        self::assertSame(404, $this->post('/app/buchungen/regeln/999/anwenden', [])->status);
        self::assertSame(404, $this->post('/app/buchungen/999/einordnung', [])->status);
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
    private function regelFelder(array $felder): array
    {
        return [...[
            'bezeichnung' => 'Kontoführung',
            'stichwort' => 'Kontoführung',
            'gegenseite' => '',
            'richtung' => 'ausgabe',
            'kein_beleg' => Buchungsregeln::KEIN_BELEG,
            'kategorie' => '',
        ], ...$felder];
    }

    /**
     * @param array<string, string> $felder
     */
    private function regelAnlegen(array $felder): int
    {
        $antwort = $this->post('/app/buchungen/regeln', $this->regelFelder($felder));
        self::assertSame(302, $antwort->status, $antwort->body);

        return (int) substr($antwort->headers['Location'] ?? '', strlen('/app/buchungen/regeln/'));
    }

    /** The id of a seeded category (docs/spec/02-datenmodell.md "Kategorien"). */
    private function kategorie(string $name): int
    {
        foreach (new CategoryRepository($this->pdo())->all() as $kategorie) {
            if ($kategorie->name === $name) {
                return $kategorie->id;
            }
        }

        self::fail('No category ' . $name);
    }

    /** A booking as the statement import writes it, encrypted under the test vault. */
    private function importierteBuchung(
        string $datum,
        int $cent,
        string $zweck,
        string $name,
        ?BankTransactionDocStatus $status = null,
        string $iban = '',
    ): int {
        $repository = new BankTransactionRepository($this->pdo());
        $richtung = BankTransactionDirection::ausDemBetrag($cent);
        $noetig = $richtung === BankTransactionDirection::Ausgabe;
        $key = DataKey::generate();
        $id = $repository->insert(
            $this->konto,
            null,
            new \DateTimeImmutable($datum),
            null,
            $richtung,
            $this->tresor->sealDataKey($key),
            random_bytes(32),
            null,
            $status ?? BankTransactionDocStatus::fuerNeueBuchung($noetig),
            $noetig,
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => $name, 'counterparty_iban' => $iban,
            'purpose' => $zweck, 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    private function manuelleBuchung(string $zweck, string $richtung = 'ausgabe'): int
    {
        $antwort = $this->post('/app/buchungen', [
            'konto' => (string) $this->konto,
            'datum' => new \DateTimeImmutable()->format('Y-m-d'),
            'betrag' => '3,50',
            'richtung' => $richtung,
            'kategorie' => (string) $this->kategorie($richtung === 'ausgabe' ? 'Sonstiges' : 'Sonstige Einnahmen'),
            'zweck' => $zweck,
            'gegenseite' => '',
            'beleg' => '',
        ]);
        self::assertSame(302, $antwort->status, $antwort->body);

        return (int) substr($antwort->headers['Location'] ?? '', strlen('/app/buchungen/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function zeile(int $id): array
    {
        $record = new BankTransactionRepository($this->pdo())->find($id) ?? self::fail('No booking ' . $id);

        return [
            'doc_required' => $record->docRequired,
            'doc_status' => $record->docStatus,
            'doc_source' => $record->docSource,
            'category_id' => $record->categoryId,
            'category_source' => $record->categorySource,
            'rule_id' => $record->ruleId,
        ];
    }

    /**
     * @return list<mixed> doc_required, doc_status, doc_source, category_id, category_source, rule_id
     */
    private function stand(int $id): array
    {
        return array_values($this->zeile($id));
    }

    /**
     * @return list<AuditEntry> newest first
     */
    private function protokoll(string $entity, int $id): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: $entity, entityId: $id), null, 20);
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

    private function regeln(): Buchungsregeln
    {
        $pdo = $this->pdo();

        return new Buchungsregeln($pdo, new AssignmentRuleRepository($pdo), new BankTransactionRepository($pdo), new CategoryRepository($pdo), new SettingRepository($pdo));
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
        $konten = new BankAccountService($pdo, new BankAccountRepository($pdo));
        $kategorien = new CategoryRepository($pdo);
        $buchungen = new Buchungen($pdo, new BankTransactionRepository($pdo), $konten, $kategorien, BelegStandard::fromSettings(new SettingRepository($pdo)));
        $kassensturz = new Kassensturz($pdo, new CashCountRepository($pdo), $buchungen);
        $kontoSeiten = fn(): AccountController => new AccountController($view, new Session(), new SessionVault(), $konten, $kassensturz, $this->audit);
        $buchungSeiten = fn(): BuchungController => new BuchungController(
            $view,
            new Session(),
            new SessionVault(),
            $buchungen,
            $konten,
            $kategorien,
            $kassensturz,
            $this->audit,
            $this->regeln(),
        );
        $regelSeiten = fn(): BuchungsregelController => new BuchungsregelController(
            $view,
            new Session(),
            new SessionVault(),
            $this->regeln(),
            $buchungen,
            $kategorien,
            $this->audit,
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the account pages 28th, the bookings 34th, the
        // rules last.
        $controller = array_fill(0, 36, $unerreichbar);
        $controller[1] = $guard;
        $controller[27] = $kontoSeiten;
        $controller[33] = $buchungSeiten;
        $controller[34] = $regelSeiten;

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
