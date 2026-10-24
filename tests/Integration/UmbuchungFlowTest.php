<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\AccountController;
use App\App\BuchungController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\BankTransaction;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSetBy;
use App\Domain\BankTransactionSource;
use App\Domain\SystemRole;
use App\Domain\UmbuchungMethode;
use App\Domain\Zugriffsbereich;
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
use App\Repository\TransferRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Buchungsregeln;
use App\Service\Bank\Import\KontoauszugImport;
use App\Service\Bank\Kassensturz;
use App\Service\Bank\Umbuchungen;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * Transfers between own accounts end to end (M10-6, issue #70, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand M10-6", E-38): the real route
 * table, guard, controller, services, repositories and schema, with a real
 * vault.
 *
 * Mandatory test of the spec for this scope: "Umbuchung eigene Konten" -
 * recognised and paired, neither income nor expense, paired and unpaired by
 * hand, the derived receipt status consistent.
 */
final class UmbuchungFlowTest extends DatabaseTestCase
{
    private const string GIRO_IBAN = 'DE89370400440532013000';
    private const string SPAR_IBAN = 'DE02120300000000202051';
    private const string FREMD_IBAN = 'DE75512108001245126199';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;

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
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Acceptance criteria "erkennen und paaren" and "nicht als Einnahme/
     * Ausgabe gewertet": the counterparty IBAN of each booking is the other
     * own account - both are paired automatically, need no receipt, and the
     * list's income and expense leave them out while the balance still
     * moves.
     */
    public function testATransferBetweenOwnAccountsIsRecognisedAndCountsAsNeither(): void
    {
        [$giro, $spar] = $this->zweiBankkonten();
        $ab = $this->importierteBuchung($giro, '2026-03-02', -50000, 'Übertrag Rücklage', 'TSV Sparkonto', self::SPAR_IBAN);
        $an = $this->importierteBuchung($spar, '2026-03-04', 50000, 'Übertrag Rücklage', 'TSV Girokonto', self::GIRO_IBAN);
        $fremd = $this->importierteBuchung($giro, '2026-03-03', -12000, 'Trikots', 'Sporthaus', self::FREMD_IBAN);
        $spende = $this->importierteBuchung($giro, '2026-03-05', 3000, 'Spende', 'Erika Mustermann', self::FREMD_IBAN);
        self::assertSame(BankTransactionDocStatus::Fehlt, $this->buchungAus($ab)->docStatus, 'an expense needs a receipt until it is recognised');

        $antwort = $this->post('/app/buchungen/umbuchungen', []);
        self::assertSame(302, $antwort->status);
        self::assertSame('/app/buchungen', $antwort->headers['Location'] ?? null);
        self::assertSame('Eine Umbuchung zwischen eigenen Konten erkannt und verknüpft.', $_SESSION['flash']['text'] ?? null);

        foreach ([$ab, $an] as $id) {
            $buchung = $this->buchungAus($id);
            self::assertSame(BankTransactionSetBy::Umbuchung, $buchung->docSource);
            self::assertFalse($buchung->docRequired);
            self::assertSame(BankTransactionDocStatus::NichtNoetig, $buchung->docStatus);
        }
        self::assertSame(BankTransactionSetBy::Standard, $this->buchungAus($fremd)->docSource);
        self::assertSame(BankTransactionDocStatus::Fehlt, $this->buchungAus($fremd)->docStatus);

        $paar = $this->umbuchungen()->fuerBuchung($an) ?? self::fail('no transfer');
        self::assertSame($ab, $paar->ausgabeId);
        self::assertSame($an, $paar->einnahmeId);
        self::assertSame(UmbuchungMethode::Auto, $paar->method);
        self::assertSame($paar->id, $this->umbuchungen()->fuerBuchung($ab)?->id);

        $protokoll = $this->protokoll($paar->id);
        self::assertSame([AuditAction::UmbuchungVerknuepft->value], array_map(static fn(AuditEntry $e): string => $e->action, $protokoll));
        self::assertSame(['ausgabe_id' => $ab, 'einnahme_id' => $an, 'method' => 'auto'], $this->audit->details($protokoll[0], $this->tresor));
        self::assertStringNotContainsString('50000', $this->rohTabelle('transfer'), 'the pair stores no amount');

        // Income 30,00, expense -120,00; the transfer is shown on its own and
        // nets to zero over both accounts.
        $liste = $this->get('/app/buchungen', ['von' => '2026-01-01', 'bis' => '2026-12-31']);
        self::assertSame(200, $liste->status);
        self::assertMatchesRegularExpression('#<th scope="row">Einnahmen</th><td class="zahl">30,00 €</td>#', $liste->body);
        self::assertMatchesRegularExpression('#<th scope="row">Ausgaben</th><td class="zahl">-120,00 €</td>#', $liste->body);
        self::assertStringContainsString('Umbuchungen eigene Konten <span class="gedaempft">(2, weder Einnahme noch Ausgabe)</span></th><td class="zahl">0,00 €</td>', $liste->body);
        self::assertStringContainsString('<strong>-90,00 €</strong>', $liste->body);
        self::assertSame(2, substr_count($liste->body, '<span class="marke">Umbuchung</span>'));

        // On one account the transfer still moves the balance.
        $nurGiro = $this->get('/app/buchungen', ['konto' => (string) $giro, 'von' => '2026-01-01', 'bis' => '2026-12-31']);
        self::assertStringContainsString('(1, weder Einnahme noch Ausgabe)</span></th><td class="zahl">-500,00 €</td>', $nurGiro->body);
        self::assertStringContainsString('<strong>-590,00 €</strong>', $nurGiro->body);

        // Running it again finds nothing new.
        $this->post('/app/buchungen/umbuchungen', []);
        self::assertStringStartsWith('Keine weiteren eindeutigen Umbuchungen', $_SESSION['flash']['text'] ?? '');
    }

    /**
     * Only the unambiguous: one counter booking with the same amount, the
     * opposite direction, within the window, on the account the IBAN names
     * - and that one has no rival either. Everything else is left to a
     * person.
     */
    public function testTheRecognitionLeavesEverythingAmbiguousAlone(): void
    {
        [$giro, $spar] = $this->zweiBankkonten();
        // Two equal counter bookings: which one is meant is unclear.
        $doppelt = $this->importierteBuchung($giro, '2026-04-01', -10000, 'Übertrag', 'TSV Sparkonto', self::SPAR_IBAN);
        $kandidat1 = $this->importierteBuchung($spar, '2026-04-02', 10000, 'Übertrag', '', '');
        $kandidat2 = $this->importierteBuchung($spar, '2026-04-03', 10000, 'Übertrag', '', '');
        // Different amount, outside the window, counter booking from a stranger.
        $anders = $this->importierteBuchung($giro, '2026-05-01', -20000, 'Übertrag', 'TSV Sparkonto', self::SPAR_IBAN);
        $this->importierteBuchung($spar, '2026-05-01', 19999, 'Übertrag', '', '');
        $spaet = $this->importierteBuchung($giro, '2026-06-01', -30000, 'Übertrag', 'TSV Sparkonto', self::SPAR_IBAN);
        $this->importierteBuchung($spar, '2026-06-10', 30000, 'Übertrag', '', '');
        $vonFremd = $this->importierteBuchung($giro, '2026-07-01', -40000, 'Übertrag', 'TSV Sparkonto', self::SPAR_IBAN);
        $this->importierteBuchung($spar, '2026-07-01', 40000, 'Miete', 'Fremder', self::FREMD_IBAN);

        self::assertSame(0, $this->umbuchungen()->erkennen($this->tresor, Zugriffsbereich::unbeschraenkt(), $this->userId, '192.0.2.1', new \DateTimeImmutable()));
        foreach ([$doppelt, $kandidat1, $kandidat2, $anders, $spaet, $vonFremd] as $id) {
            self::assertNull($this->umbuchungen()->fuerBuchung($id));
        }

        // Once one of the two candidates is otherwise accounted for, the
        // other is unambiguous - and a counter booking without IBAN is fine.
        $this->umbuchungen()->paaren($this->tresor, $kandidat1, $this->importierteBuchung($giro, '2026-04-02', -10000, 'x', '', ''), UmbuchungMethode::Manuell, $this->userId, '192.0.2.1', new \DateTimeImmutable());
        self::assertSame(1, $this->umbuchungen()->erkennen($this->tresor, Zugriffsbereich::unbeschraenkt(), $this->userId, '192.0.2.1', new \DateTimeImmutable()));
        self::assertSame($kandidat2, $this->umbuchungen()->fuerBuchung($doppelt)?->einnahmeId);
    }

    /**
     * Acceptance criterion "Manuelles Paaren und Lösen": cash taken from the
     * bank into the cash box - the bank booking has no IBAN to recognise it
     * by, so a person pairs it with the cash booking; unpairing gives both
     * the default receipt status of their direction back.
     */
    public function testATransferIsPairedAndUnpairedByHand(): void
    {
        $giro = $this->bankkonto('Girokonto', self::GIRO_IBAN);
        $kasse = $this->konto(['art' => 'kasse', 'name' => 'Barkasse', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01']);
        $abhebung = $this->importierteBuchung($giro, '2026-03-10', -20000, 'Bargeldauszahlung', '', '');
        $this->post('/app/buchungen', [
            'konto' => (string) $kasse, 'datum' => '2026-03-10', 'betrag' => '200,00', 'richtung' => 'einnahme',
            'kategorie' => (string) $this->kategorie('Sonstige Einnahmen'), 'zweck' => 'Wechselgeld von der Bank', 'gegenseite' => '', 'beleg' => '',
        ]);
        $einlage = (int) $this->pdo()->query("SELECT id FROM bank_transaction WHERE source = 'manuell'")->fetchColumn();
        // A booking that does not fit is not offered.
        $this->importierteBuchung($giro, '2026-03-11', 20000, 'Gutschrift', '', '');

        $seite = $this->get('/app/buchungen/' . $abhebung);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('action="/app/buchungen/' . $abhebung . '/umbuchung"', $seite->body);
        self::assertStringContainsString('<option value="' . $einlage . '">10.03.2026 · Barkasse · 200,00 €', $seite->body);
        self::assertSame(1, $this->optionenDerUmbuchung($seite->body), 'only the fitting cash booking');

        $antwort = $this->post('/app/buchungen/' . $abhebung . '/umbuchung', ['gegenbuchung' => (string) $einlage]);
        self::assertSame(302, $antwort->status);
        self::assertSame('/app/buchungen/' . $abhebung, $antwort->headers['Location'] ?? null);
        $paar = $this->umbuchungen()->fuerBuchung($einlage) ?? self::fail('not paired');
        self::assertSame(UmbuchungMethode::Manuell, $paar->method);
        self::assertSame($abhebung, $paar->ausgabeId);
        self::assertSame(BankTransactionDocStatus::NichtNoetig, $this->buchungAus($abhebung)->docStatus);
        self::assertSame(BankTransactionSetBy::Umbuchung, $this->buchungAus($einlage)->docSource);

        $seite = $this->get('/app/buchungen/' . $einlage);
        self::assertStringContainsString('zählt weder als Einnahme noch als Ausgabe', $seite->body);
        self::assertStringContainsString('<a href="/app/buchungen/' . $abhebung . '">10.03.2026,', $seite->body);
        self::assertStringContainsString('action="/app/buchungen/' . $einlage . '/umbuchung/loesen"', $seite->body);
        self::assertStringNotContainsString('/loeschen"', $seite->body, 'a paired booking is not deleted');

        $antwort = $this->post('/app/buchungen/' . $einlage . '/umbuchung/loesen', []);
        self::assertSame(302, $antwort->status);
        self::assertNull($this->umbuchungen()->fuerBuchung($abhebung));
        $bank = $this->buchungAus($abhebung);
        self::assertSame(BankTransactionSetBy::Standard, $bank->docSource);
        self::assertTrue($bank->docRequired, 'an expense needs a receipt again');
        self::assertSame(BankTransactionDocStatus::Fehlt, $bank->docStatus);
        $bar = $this->buchungAus($einlage);
        self::assertSame(BankTransactionSetBy::Standard, $bar->docSource);
        self::assertSame(BankTransactionDocStatus::NichtNoetig, $bar->docStatus, 'income needs none by default (E-17)');

        self::assertSame(
            [AuditAction::UmbuchungGeloest->value, AuditAction::UmbuchungVerknuepft->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $this->protokoll($paar->id)),
        );
        self::assertSame(302, $this->post('/app/buchungen/' . $einlage . '/umbuchung/loesen', [])->status, 'unpairing twice is refused');
        self::assertSame('Diese Buchung ist nicht als Umbuchung verknüpft.', $_SESSION['flash']['text'] ?? null);
    }

    /**
     * A pair is an expense and an income of the same amount on two different
     * accounts, neither paired already nor with a receipt allocated.
     */
    public function testPairingChecksBothBookings(): void
    {
        [$giro, $spar] = $this->zweiBankkonten();
        $ab = $this->importierteBuchung($giro, '2026-03-02', -50000, 'Übertrag', '', '');
        $an = $this->importierteBuchung($spar, '2026-03-02', 50000, 'Übertrag', '', '');
        $gleichesKonto = $this->importierteBuchung($giro, '2026-03-02', 50000, 'Rückbuchung', '', '');
        $gleicheRichtung = $this->importierteBuchung($spar, '2026-03-02', -50000, 'Abgang', '', '');
        $andererBetrag = $this->importierteBuchung($spar, '2026-03-02', 49999, 'Übertrag', '', '');
        $mitBeleg = $this->importierteBuchung($spar, '2026-03-03', 50000, 'Übertrag', '', '');
        new BankTransactionRepository($this->pdo())->setzeBelegstatus($mitBeleg, BankTransactionDocStatus::Zugeordnet, new \DateTimeImmutable());

        foreach ([
            [$gleichesKonto, 'demselben Konto'],
            [$gleicheRichtung, 'einer Ausgabe auf dem einen und einer Einnahme'],
            [$andererBetrag, 'Beträge der beiden Buchungen sind verschieden'],
            [$mitBeleg, 'ein Beleg zugeordnet'],
            [$ab, 'nicht mit sich selbst'],
            [999999, 'Gegenbuchung auf dem anderen Konto wählen'],
        ] as [$gegen, $meldung]) {
            $antwort = $this->post('/app/buchungen/' . $ab . '/umbuchung', ['gegenbuchung' => (string) $gegen]);
            self::assertSame(422, $antwort->status, $meldung);
            self::assertStringContainsString($meldung, $antwort->body);
            self::assertStringContainsString('id="umbuchung-gegenbuchung" name="gegenbuchung" aria-invalid="true"', $antwort->body, $meldung);
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM transfer')->fetchColumn());

        self::assertSame(302, $this->post('/app/buchungen/' . $an . '/umbuchung', ['gegenbuchung' => (string) $ab])->status);
        try {
            $this->umbuchungen()->paaren($this->tresor, $ab, $andererBetrag, UmbuchungMethode::Manuell, $this->userId, '192.0.2.1', new \DateTimeImmutable());
            self::fail('a paired booking is paired once');
        } catch (BankRuleViolation $e) {
            self::assertStringContainsString('bereits als Umbuchung verknüpft', $e->getMessage());
        }
    }

    /**
     * What a pair relies on stays: the manual half keeps account, amount,
     * direction and receipt need and is not deleted; the "Einordnung" of an
     * imported half changes the category but not the receipt status; a rule
     * passes it by.
     */
    public function testAPairedBookingKeepsWhatThePairReliesOn(): void
    {
        $giro = $this->bankkonto('Girokonto', self::GIRO_IBAN);
        $kasse = $this->konto(['art' => 'kasse', 'name' => 'Barkasse', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01']);
        $einzahlung = $this->importierteBuchung($giro, '2026-03-10', 15000, 'Bareinzahlung', '', '');
        $felder = [
            'konto' => (string) $kasse, 'datum' => '2026-03-10', 'betrag' => '150,00', 'richtung' => 'ausgabe',
            'kategorie' => (string) $this->kategorie('Sonstiges'), 'zweck' => 'Zur Bank gebracht', 'gegenseite' => '', 'beleg' => '',
        ];
        $this->post('/app/buchungen', $felder);
        $kassenbuchung = (int) $this->pdo()->query("SELECT id FROM bank_transaction WHERE source = 'manuell'")->fetchColumn();
        $this->umbuchungen()->paaren($this->tresor, $kassenbuchung, $einzahlung, UmbuchungMethode::Manuell, $this->userId, '192.0.2.1', new \DateTimeImmutable());

        // The form as the page fills it for the paired booking: "kein Beleg nötig".
        $felder['beleg'] = Buchungen::BELEG_NICHT_NOETIG;
        foreach (['betrag' => '160,00', 'beleg' => Buchungen::BELEG_NOETIG, 'konto' => (string) $giro] as $feld => $wert) {
            $antwort = $this->post('/app/buchungen/' . $kassenbuchung, [...$felder, $feld => $wert]);
            self::assertSame(422, $antwort->status, $feld);
            self::assertStringContainsString('erst ändern, wenn die Umbuchung gelöst ist', $antwort->body);
            self::assertMatchesRegularExpression('#id="buchung-' . $feld . '" name="' . $feld . '"[^>]*aria-invalid="true"#', $antwort->body, 'the field that caused it is marked');
        }
        self::assertSame(302, $this->post('/app/buchungen/' . $kassenbuchung, [...$felder, 'zweck' => 'Zur Sparkasse gebracht'])->status);
        $nachher = $this->buchungAus($kassenbuchung);
        self::assertSame('Zur Sparkasse gebracht', $nachher->purpose);
        self::assertSame(BankTransactionSetBy::Umbuchung, $nachher->docSource, 'changing the purpose keeps the pair');
        self::assertSame(BankTransactionDocStatus::NichtNoetig, $nachher->docStatus);

        $this->post('/app/buchungen/' . $kassenbuchung . '/loeschen', []);
        self::assertStringContainsString('erst die Umbuchung lösen', $_SESSION['flash']['text'] ?? '');
        self::assertNotNull(new BankTransactionRepository($this->pdo())->find($kassenbuchung));

        $antwort = $this->post('/app/buchungen/' . $einzahlung . '/einordnung', ['kategorie' => '', 'beleg' => Buchungen::BELEG_NOETIG]);
        self::assertSame(302, $antwort->status);
        self::assertSame(BankTransactionSetBy::Umbuchung, $this->buchungAus($einzahlung)->docSource, 'the receipt status is the pair\'s');
        self::assertStringContainsString('Umbuchung zwischen eigenen Konten', $this->get('/app/buchungen/' . $einzahlung)->body);

        // A rule "Beleg nötig" on everything leaves the pair alone.
        $regeln = new Buchungsregeln($this->pdo(), new AssignmentRuleRepository($this->pdo()), new BankTransactionRepository($this->pdo()), new CategoryRepository($this->pdo()), new SettingRepository($this->pdo()));
        $regelId = $regeln->anlegen($this->tresor, ['bezeichnung' => 'Bareinzahlungen', 'stichwort' => 'Bareinzahlung', 'gegenseite' => '', 'richtung' => '', 'kein_beleg' => Buchungsregeln::KEIN_BELEG, 'kategorie' => ''], $this->userId, new \DateTimeImmutable());
        $regel = $regeln->finde($this->tresor, $regelId) ?? self::fail();
        $regeln->anwenden($this->tresor, $regel, Zugriffsbereich::unbeschraenkt(), new \DateTimeImmutable());
        self::assertSame(BankTransactionSetBy::Umbuchung, $this->buchungAus($einzahlung)->docSource);
    }

    /**
     * A booking page loaded before an automatic pairing: saving it must not
     * bring the receipt need back - compared with the locked row.
     */
    public function testAStalePageDoesNotUndoAPairing(): void
    {
        $giro = $this->bankkonto('Girokonto', self::GIRO_IBAN);
        $kasse = $this->konto(['art' => 'kasse', 'name' => 'Barkasse', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01']);
        $einzahlung = $this->importierteBuchung($giro, '2026-03-10', 15000, 'Bareinzahlung', '', '');
        $felder = [
            'konto' => (string) $kasse, 'datum' => '2026-03-10', 'betrag' => '150,00', 'richtung' => 'ausgabe',
            'kategorie' => (string) $this->kategorie('Sonstiges'), 'zweck' => 'Zur Bank gebracht', 'gegenseite' => '', 'beleg' => '',
        ];
        $this->post('/app/buchungen', $felder);
        $kassenbuchung = (int) $this->pdo()->query("SELECT id FROM bank_transaction WHERE source = 'manuell'")->fetchColumn();
        $veraltet = $this->buchungAus($kassenbuchung);
        self::assertTrue($veraltet->docRequired);

        $this->umbuchungen()->paaren($this->tresor, $kassenbuchung, $einzahlung, UmbuchungMethode::Auto, $this->userId, '192.0.2.1', new \DateTimeImmutable());

        try {
            $this->buchungen()->aendern($this->tresor, $veraltet, [...$felder, 'zweck' => 'Zur Sparkasse gebracht'], new \DateTimeImmutable());
            self::fail('the stale receipt need must not win');
        } catch (BankRuleViolation $e) {
            self::assertSame('beleg', $e->feld);
        }
        $nachher = $this->buchungAus($kassenbuchung);
        self::assertSame(BankTransactionSetBy::Umbuchung, $nachher->docSource);
        self::assertFalse($nachher->docRequired);
        self::assertSame(BankTransactionDocStatus::NichtNoetig, $nachher->docStatus);
    }

    /**
     * A rule that set only the receipt status before the pairing is
     * forgotten on unpairing, so it may act on the booking again.
     */
    public function testUnpairingLetsARuleActAgain(): void
    {
        [$giro, $spar] = $this->zweiBankkonten();
        $ab = $this->importierteBuchung($giro, '2026-03-02', -50000, 'Übertrag Rücklage', '', '');
        $an = $this->importierteBuchung($spar, '2026-03-02', 50000, 'Übertrag Rücklage', '', '');
        $regeln = new Buchungsregeln($this->pdo(), new AssignmentRuleRepository($this->pdo()), new BankTransactionRepository($this->pdo()), new CategoryRepository($this->pdo()), new SettingRepository($this->pdo()));
        $regelId = $regeln->anlegen($this->tresor, ['bezeichnung' => 'Rücklage', 'stichwort' => 'Rücklage', 'gegenseite' => '', 'richtung' => '', 'kein_beleg' => Buchungsregeln::KEIN_BELEG, 'kategorie' => ''], $this->userId, new \DateTimeImmutable());
        $regel = $regeln->finde($this->tresor, $regelId) ?? self::fail();
        $regeln->anwenden($this->tresor, $regel, Zugriffsbereich::unbeschraenkt(), new \DateTimeImmutable());
        self::assertSame(BankTransactionSetBy::Regel, $this->buchungAus($ab)->docSource);

        $this->umbuchungen()->paaren($this->tresor, $ab, $an, UmbuchungMethode::Manuell, $this->userId, '192.0.2.1', new \DateTimeImmutable());
        $this->umbuchungen()->loesen($ab, $this->userId, '192.0.2.1', new \DateTimeImmutable());
        self::assertNull($this->buchungAus($ab)->ruleId);
        self::assertSame(BankTransactionSetBy::Standard, $this->buchungAus($ab)->docSource);

        $regeln->anwenden($this->tresor, $regel, Zugriffsbereich::unbeschraenkt(), new \DateTimeImmutable());
        self::assertSame(BankTransactionSetBy::Regel, $this->buchungAus($ab)->docSource, 'the rule acts again');
        self::assertSame(BankTransactionDocStatus::NichtNoetig, $this->buchungAus($ab)->docStatus);
    }

    /** Unpairing rewrites both bookings: whoever may not edit the partner may not unpair. */
    public function testThePartnerOutsideTheScopeIsNotUnpaired(): void
    {
        [$giro, $spar] = $this->zweiBankkonten();
        $alt = $this->importierteBuchung($giro, '2026-01-02', -50000, 'Übertrag', '', '');
        $neu = $this->importierteBuchung($spar, '2026-03-02', 50000, 'Übertrag', '', '');
        $this->umbuchungen()->paaren($this->tresor, $alt, $neu, UmbuchungMethode::Manuell, $this->userId, '192.0.2.1', new \DateTimeImmutable());
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('2026-02-01'), null);

        self::assertStringContainsString('Die Gegenbuchung liegt außerhalb Ihres Zugriffsbereichs.', $this->get('/app/buchungen/' . $neu)->body);
        $antwort = $this->post('/app/buchungen/' . $neu . '/umbuchung/loesen', []);
        self::assertSame('/app/buchungen/' . $neu, $antwort->headers['Location'] ?? null);
        self::assertStringContainsString('außerhalb Ihres Zugriffsbereichs', $_SESSION['flash']['text'] ?? '');
        self::assertNotNull($this->umbuchungen()->fuerBuchung($neu));
    }

    /** Readers see the pair; only `bank.book` pairs, unpairs and recognises. CSRF and the vault as everywhere. */
    public function testReadersSeeThePairButOnlyBankBookWrites(): void
    {
        [$giro, $spar] = $this->zweiBankkonten();
        $ab = $this->importierteBuchung($giro, '2026-03-02', -50000, 'Übertrag', '', '');
        $an = $this->importierteBuchung($spar, '2026-03-02', 50000, 'Übertrag', '', '');
        $this->umbuchungen()->paaren($this->tresor, $ab, $an, UmbuchungMethode::Manuell, $this->userId, '192.0.2.1', new \DateTimeImmutable());

        $ohneCsrf = $this->dispatch(new Request(HttpMethod::Post, '/app/buchungen/' . $ab . '/umbuchung/loesen', cookies: $this->tresorCookie(), post: []));
        self::assertSame('/app/buchungen/' . $ab, $ohneCsrf->headers['Location'] ?? null);
        $this->post('/app/buchungen/' . $ab . '/umbuchung/loesen', [], entsperrt: false);
        self::assertNotNull($this->umbuchungen()->fuerBuchung($ab), 'neither without token nor without vault');
        self::assertSame(404, $this->post('/app/buchungen/999999/umbuchung', ['gegenbuchung' => (string) $an])->status);

        $this->rolle(SystemRole::Kassenpruefer);
        $seite = $this->get('/app/buchungen/' . $ab);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('zählt weder als Einnahme noch als Ausgabe', $seite->body);
        self::assertStringNotContainsString('/umbuchung/loesen"', $seite->body);
        self::assertStringNotContainsString('action="/app/buchungen/umbuchungen"', $this->get('/app/buchungen')->body);
        self::assertSame(403, $this->post('/app/buchungen/' . $ab . '/umbuchung/loesen', [])->status);
        self::assertSame(403, $this->post('/app/buchungen/umbuchungen', [])->status);
        self::assertNotNull($this->umbuchungen()->fuerBuchung($ab));
    }

    // ---------------------------------------------------------- helpers

    /**
     * @return array{int, int} Girokonto and Sparkonto, both with an IBAN
     */
    private function zweiBankkonten(): array
    {
        return [$this->bankkonto('Girokonto', self::GIRO_IBAN), $this->bankkonto('Sparkonto', self::SPAR_IBAN)];
    }

    /** How many counter bookings the transfer's choice offers. */
    private function optionenDerUmbuchung(string $html): int
    {
        $start = strpos($html, 'id="umbuchung-gegenbuchung"');
        self::assertNotFalse($start);
        $ende = strpos($html, '</select>', $start);
        self::assertNotFalse($ende);

        return substr_count(substr($html, $start, $ende - $start), '<option value="');
    }

    private function rolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    private function bankkonto(string $name, string $iban): int
    {
        return $this->konto(['art' => 'bank', 'name' => $name, 'iban' => $iban, 'opening_balance' => '0,00', 'opening_date' => '2026-01-01']);
    }

    /**
     * @param array<string, string> $felder
     */
    private function konto(array $felder): int
    {
        $antwort = $this->post('/app/konten', [...['iban' => '', 'bic' => '', 'bank' => ''], ...$felder]);
        self::assertSame(302, $antwort->status);

        return (int) substr($antwort->headers['Location'] ?? '', strlen('/app/konten/'));
    }

    private function kategorie(string $name): int
    {
        foreach (new CategoryRepository($this->pdo())->all() as $kategorie) {
            if ($kategorie->name === $name) {
                return $kategorie->id;
            }
        }

        self::fail('No category ' . $name);
    }

    /** A booking as the statement import writes it - counterparty IBAN and its blind index included. */
    private function importierteBuchung(int $konto, string $datum, int $cent, string $zweck, string $name, string $iban): int
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
            $iban === '' ? null : $this->tresor->blindIndex()->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, $iban),
            BankTransactionDocStatus::fuerNeueBuchung($richtung === BankTransactionDirection::Ausgabe),
            $richtung === BankTransactionDirection::Ausgabe,
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => $name, 'counterparty_iban' => $iban,
            'purpose' => $zweck, 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    private function buchungen(): Buchungen
    {
        return new Buchungen(
            $this->pdo(),
            new BankTransactionRepository($this->pdo()),
            new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo())),
            new CategoryRepository($this->pdo()),
        );
    }

    private function umbuchungen(): Umbuchungen
    {
        return new Umbuchungen(
            $this->pdo(),
            new TransferRepository($this->pdo()),
            new BankTransactionRepository($this->pdo()),
            new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo())),
            $this->audit,
        );
    }

    private function buchungAus(int $id): BankTransaction
    {
        return $this->buchungen()->finde($this->tresor, $id, Zugriffsbereich::unbeschraenkt()) ?? self::fail('No booking ' . $id);
    }

    /**
     * @return list<AuditEntry> newest first
     */
    private function protokoll(int $id): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'transfer', entityId: $id), null, 10);
    }

    private function rohTabelle(string $tabelle): string
    {
        $roh = '';
        foreach ($this->pdo()->query('SELECT * FROM ' . $tabelle)->fetchAll() as $zeile) {
            $roh .= implode('|', array_map(static fn(mixed $w): string => (string) $w, $zeile)) . "\n";
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
        $konten = new BankAccountService($pdo, new BankAccountRepository($pdo));
        $kassensturz = new Kassensturz($pdo, new CashCountRepository($pdo), $this->buchungen());
        $kontoSeiten = fn(): AccountController => new AccountController($view, new Session(), new SessionVault(), $konten, $kassensturz, $this->audit);
        $buchungSeiten = fn(): BuchungController => new BuchungController(
            $view,
            new Session(),
            new SessionVault(),
            $this->buchungen(),
            $konten,
            new CategoryRepository($pdo),
            $kassensturz,
            $this->audit,
            new Buchungsregeln($pdo, new AssignmentRuleRepository($pdo), new BankTransactionRepository($pdo), new CategoryRepository($pdo), new SettingRepository($pdo)),
            $this->umbuchungen(),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the account pages 28th, the bookings 34th.
        $controller = array_fill(0, 38, $unerreichbar);
        $controller[1] = $guard;
        $controller[27] = $kontoSeiten;
        $controller[33] = $buchungSeiten;

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
