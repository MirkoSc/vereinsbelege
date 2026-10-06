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
use App\Domain\BankTransactionSource;
use App\Domain\Permission;
use App\Domain\SystemRole;
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
use App\Service\Bank\BuchungFilter;
use App\Service\Bank\Buchungen;
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
 * The booking list and manual bookings end to end (M9-5, issue #63,
 * docs/spec/04-bank-und-abgleich.md section 1, E-17): the real route table,
 * guard, controllers, services, repositories and schema, with a real vault.
 *
 * Mandatory tests of the spec for this scope: income defaults to "kein
 * Beleg nötig", a manual booking without receipt, the cash count difference.
 */
final class BuchungFlowTest extends DatabaseTestCase
{
    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $jahr;

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
        $this->jahr = (int) new \DateTimeImmutable()->format('Y');

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ------------------------------------------------- manual bookings

    /**
     * Acceptance criteria "Manuelle Buchungen für Kasse und Konten" and
     * "Einnahmen ohne Beleg möglich, klar gekennzeichnet" - the mandatory
     * tests "Einnahme-Default kein Beleg nötig" and "manuelle Buchung ohne
     * Beleg": income needs no receipt unless chosen, it is stored like an
     * imported booking (signed cents, vault data) and marked in the list.
     */
    public function testIncomeWithoutReceiptIsBookedEncryptedAndMarked(): void
    {
        $kasse = $this->kasse('Barkasse', '50,00');
        $spenden = $this->kategorie('Spenden');

        $formular = $this->get('/app/buchungen/neu', ['konto' => (string) $kasse, 'richtung' => 'einnahme']);
        self::assertSame(200, $formular->status);
        self::assertMatchesRegularExpression('/<option value="' . $kasse . '" selected>\s*Barkasse \(Kasse\)/', $formular->body);
        self::assertStringContainsString('<option value="einnahme" selected>', $formular->body);

        $antwort = $this->post('/app/buchungen', $this->buchung($kasse, [
            'betrag' => '120,50',
            'richtung' => 'einnahme',
            'kategorie' => (string) $spenden,
            'zweck' => 'Spende in bar Sommerfest',
            'gegenseite' => 'Erika Mustermann',
        ]));

        self::assertSame(302, $antwort->status);
        $id = $this->einzigeBuchung();
        self::assertSame('/app/buchungen/' . $id, $antwort->headers['Location'] ?? null);
        self::assertSame('Buchung gespeichert.', $_SESSION['flash']['text'] ?? null);

        $buchung = $this->buchungAus($id);
        self::assertSame(12050, $buchung->amount, 'integer cents, income positive');
        self::assertSame('EUR', $buchung->currency);
        self::assertSame(BankTransactionDirection::Einnahme, $buchung->direction);
        self::assertSame(BankTransactionSource::Manuell, $buchung->source);
        self::assertFalse($buchung->docRequired);
        self::assertSame(BankTransactionDocStatus::NichtNoetig, $buchung->docStatus);
        self::assertSame($spenden, $buchung->categoryId);
        self::assertSame('Spende in bar Sommerfest', $buchung->purpose);
        self::assertSame('Erika Mustermann', $buchung->counterpartyName);
        self::assertNull($buchung->importId);

        $zeile = $this->pdo()->query('SELECT dedup_bi, counterparty_bi FROM bank_transaction')->fetch();
        self::assertNull($zeile['dedup_bi']);
        self::assertNull($zeile['counterparty_bi']);

        $roh = $this->rohTabelle('bank_transaction');
        foreach (['Spende', 'Sommerfest', 'Erika', 'Mustermann', '12050', '120,50'] as $klartext) {
            self::assertStringNotContainsString($klartext, $roh, $klartext . ' is not stored in the clear');
        }

        $liste = $this->get('/app/buchungen')->body;
        self::assertStringContainsString('Spende in bar Sommerfest', $liste);
        self::assertStringContainsString('<span class="marke">kein Beleg nötig</span>', $liste);
        self::assertStringContainsString('<span class="marke">manuell</span>', $liste);
        self::assertStringContainsString('120,50 €', $liste);

        $zeilen = $this->protokoll($id);
        self::assertSame([AuditAction::BuchungAngelegt->value], array_map(static fn(AuditEntry $e): string => $e->action, $zeilen));
        self::assertSame(['konto' => $kasse, 'richtung' => 'einnahme', 'beleg' => 'nicht_noetig'], $this->audit->details($zeilen[0], $this->tresor));
    }

    /**
     * An expense needs a receipt by default (E-17), stored negative; the
     * choice overrides the default either way - on a bank account too.
     */
    public function testAnExpenseNeedsAReceiptUnlessChosenOtherwise(): void
    {
        $bank = $this->bankkonto('Girokonto');
        $bankgebuehren = $this->kategorie('Bankgebühren');
        $spenden = $this->kategorie('Spenden');

        $this->post('/app/buchungen', $this->buchung($bank, ['betrag' => '4,90', 'richtung' => 'ausgabe', 'kategorie' => (string) $bankgebuehren, 'zweck' => 'Kontoführung']));
        $this->post('/app/buchungen', $this->buchung($bank, ['betrag' => '1,00', 'richtung' => 'ausgabe', 'kategorie' => (string) $bankgebuehren, 'zweck' => 'Porto', 'beleg' => '0']));
        $this->post('/app/buchungen', $this->buchung($bank, ['betrag' => '300', 'richtung' => 'einnahme', 'kategorie' => (string) $spenden, 'zweck' => 'Spende mit Quittung', 'beleg' => '1']));

        $buchungen = $this->alle();
        self::assertCount(3, $buchungen);
        [$spende, $porto, $gebuehr] = $buchungen;
        self::assertSame(-490, $gebuehr->amount);
        self::assertSame(BankTransactionDocStatus::Fehlt, $gebuehr->docStatus);
        self::assertTrue($gebuehr->docRequired);
        self::assertSame(-100, $porto->amount);
        self::assertSame(BankTransactionDocStatus::NichtNoetig, $porto->docStatus);
        self::assertSame(30000, $spende->amount);
        self::assertSame(BankTransactionDocStatus::Fehlt, $spende->docStatus);

        $liste = $this->get('/app/buchungen')->body;
        self::assertStringContainsString('<span class="marke marke-warnung">Beleg fehlt</span>', $liste);
        self::assertStringContainsString('-4,90 €', $liste);
        // Sums of what is shown.
        self::assertStringContainsString('<th scope="row">Einnahmen</th><td class="zahl">300,00 €</td>', $liste);
        self::assertStringContainsString('<th scope="row">Ausgaben</th><td class="zahl">-5,90 €</td>', $liste);
        self::assertStringContainsString('<strong>294,10 €</strong>', $liste);
    }

    public function testInvalidInputIsRefusedAndTheFieldMarked(): void
    {
        $kasse = $this->kasse('Barkasse', '0,00', stichtag: '2026-01-01');
        $spenden = (string) $this->kategorie('Spenden');
        $ausgabeKategorie = (string) $this->kategorie('Bankgebühren');

        $faelle = [
            ['konto', ['konto' => ''], 'Bitte das Konto oder die Kasse wählen.'],
            ['konto', ['konto' => '999'], 'Bitte das Konto oder die Kasse wählen.'],
            ['datum', ['datum' => '2026-02-30'], 'Bitte das Datum der Buchung angeben.'],
            ['datum', ['datum' => '2025-12-31'], 'nicht vor dem Stichtag des Anfangssaldos (01.01.2026)'],
            ['datum', ['datum' => new \DateTimeImmutable('tomorrow')->format('Y-m-d')], 'nicht in der Zukunft'],
            ['betrag', ['betrag' => ''], 'Den Betrag bitte als positiven Betrag'],
            ['betrag', ['betrag' => '-5,00'], 'Den Betrag bitte als positiven Betrag'],
            ['betrag', ['betrag' => '0,00'], 'Den Betrag bitte als positiven Betrag'],
            ['betrag', ['betrag' => '1,234'], 'Den Betrag bitte als positiven Betrag'],
            ['richtung', ['richtung' => ''], 'Einnahme oder eine Ausgabe'],
            ['kategorie', ['kategorie' => ''], 'Bitte eine Kategorie wählen.'],
            ['kategorie', ['kategorie' => $ausgabeKategorie], 'Die Kategorie „Bankgebühren“ gehört zu den Ausgaben, die Buchung ist eine Einnahme.'],
            ['zweck', ['zweck' => '  '], 'Bitte den Zweck angeben'],
            ['zweck', ['zweck' => str_repeat('x', 301)], 'höchstens 300 Zeichen'],
            ['gegenseite', ['gegenseite' => str_repeat('x', 101)], 'höchstens 100 Zeichen'],
        ];
        foreach ($faelle as [$feld, $eingabe, $meldung]) {
            $antwort = $this->post('/app/buchungen', $this->buchung($kasse, ['kategorie' => $spenden, ...$eingabe]));
            self::assertSame(422, $antwort->status, $meldung);
            self::assertStringContainsString(e($meldung), $antwort->body);
            self::assertMatchesRegularExpression('/id="buchung-' . $feld . '"[^>]*aria-invalid="true"/s', $antwort->body, $feld . ' is marked');
        }

        // A deactivated account and a deactivated category take nothing new.
        $this->pdo()->exec('UPDATE category SET active = 0 WHERE id = ' . (int) $spenden);
        $inaktiveKategorie = $this->post('/app/buchungen', $this->buchung($kasse, ['kategorie' => $spenden]));
        self::assertSame(422, $inaktiveKategorie->status);
        self::assertStringContainsString('Die Kategorie „Spenden“ ist deaktiviert.', $inaktiveKategorie->body);

        $this->pdo()->exec('UPDATE bank_account SET active = 0 WHERE id = ' . $kasse);
        $inaktiv = $this->post('/app/buchungen', $this->buchung($kasse, ['kategorie' => (string) $this->kategorie('Sonstige Einnahmen')]));
        self::assertSame(422, $inaktiv->status);
        self::assertStringContainsString('„Barkasse“ ist deaktiviert und nimmt keine Buchungen an.', $inaktiv->body);

        self::assertSame([], $this->alle());
    }

    /**
     * Changing a manual booking logs only the names of the changed fields;
     * deleting it is logged with its account. Without a change nothing is
     * written and nothing logged.
     */
    public function testAManualBookingIsChangedAndDeleted(): void
    {
        $kasse = $this->kasse('Barkasse', '0,00');
        $this->post('/app/buchungen', $this->buchung($kasse, ['kategorie' => (string) $this->kategorie('Spenden'), 'zweck' => 'Spende']));
        $id = $this->einzigeBuchung();
        $vorher = $this->buchungAus($id);

        $seite = $this->get('/app/buchungen/' . $id)->body;
        self::assertStringContainsString('value="10,00"', $seite);
        self::assertStringContainsString('Buchung löschen', $seite);

        $ohneAenderung = $this->post('/app/buchungen/' . $id, $this->buchung($kasse, ['kategorie' => (string) $this->kategorie('Spenden'), 'zweck' => 'Spende']));
        self::assertSame(302, $ohneAenderung->status);
        self::assertEquals($vorher->updatedAt, $this->buchungAus($id)->updatedAt);
        self::assertCount(1, $this->protokoll($id));

        $antwort = $this->post('/app/buchungen/' . $id, $this->buchung($kasse, [
            'betrag' => '12,00',
            'richtung' => 'ausgabe',
            'kategorie' => (string) $this->kategorie('Verpflegung & Bewirtung'),
            'zweck' => 'Brötchen Helfer',
        ]));
        self::assertSame(302, $antwort->status);
        self::assertSame('/app/buchungen/' . $id, $antwort->headers['Location'] ?? null);

        $nachher = $this->buchungAus($id);
        self::assertSame(-1200, $nachher->amount);
        self::assertSame(BankTransactionDirection::Ausgabe, $nachher->direction);
        self::assertSame('Brötchen Helfer', $nachher->purpose);
        self::assertSame(BankTransactionDocStatus::Fehlt, $nachher->docStatus, 'the receipt default follows the new direction');

        $zeilen = $this->protokoll($id);
        self::assertSame(AuditAction::BuchungGeaendert->value, $zeilen[0]->action);
        self::assertSame(['felder' => ['amount', 'direction', 'category_id', 'purpose', 'doc_required']], $this->audit->details($zeilen[0], $this->tresor));

        $loeschen = $this->post('/app/buchungen/' . $id . '/loeschen', []);
        self::assertSame(302, $loeschen->status);
        self::assertSame('/app/buchungen?konto=' . $kasse, $loeschen->headers['Location'] ?? null);
        self::assertSame([], $this->alle());
        $zeilen = $this->protokoll($id);
        self::assertSame(AuditAction::BuchungGeloescht->value, $zeilen[0]->action);
        self::assertSame(['konto' => $kasse], $this->audit->details($zeilen[0], $this->tresor));

        foreach ($this->protokoll($id) as $zeile) {
            $details = (string) json_encode($this->audit->details($zeile, $this->tresor), JSON_UNESCAPED_UNICODE);
            foreach (['Spende', 'Brötchen', '1200', '12,00', '1000'] as $klartext) {
                self::assertStringNotContainsString($klartext, $details, 'no purpose or amount in the audit log');
            }
        }
    }

    /**
     * An imported booking is what the bank says: shown read-only, never
     * changed or deleted - not even through a crafted POST.
     */
    public function testAnImportedBookingIsReadOnly(): void
    {
        $bank = $this->bankkonto('Girokonto');
        $id = $this->importierteBuchung($bank, '2026-03-02', -2500, 'Rechnung 4711 Platzpflege', 'Rasen GmbH');

        $seite = $this->get('/app/buchungen/' . $id);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Buchung aus einem Kontoauszug', $seite->body);
        self::assertStringContainsString('Rechnung 4711 Platzpflege', $seite->body);
        self::assertStringNotContainsString('name="betrag"', $seite->body);
        self::assertStringNotContainsString('/loeschen"', $seite->body);

        $aendern = $this->post('/app/buchungen/' . $id, $this->buchung($bank, ['kategorie' => (string) $this->kategorie('Spenden')]));
        self::assertSame(422, $aendern->status);
        self::assertStringContainsString('Eine Buchung aus einem Kontoauszug lässt sich nicht ändern.', $aendern->body);

        $this->post('/app/buchungen/' . $id . '/loeschen', []);
        self::assertSame('Eine Buchung aus einem Kontoauszug lässt sich nicht löschen.', $_SESSION['flash']['text'] ?? null);
        self::assertCount(1, $this->alle());
        self::assertSame(-2500, $this->buchungAus($id)->amount);
        self::assertSame([], $this->protokoll($id));
    }

    // ------------------------------------------------------------ list

    /**
     * Acceptance criterion "Buchungsliste mit Filtern": account, period,
     * direction, receipt, origin, category and the search text. Without
     * dates the current year is shown.
     */
    public function testTheListFilters(): void
    {
        $kasse = $this->kasse('Barkasse', '0,00', stichtag: ($this->jahr - 1) . '-01-01');
        $bank = $this->bankkonto('Girokonto', stichtag: ($this->jahr - 1) . '-01-01');
        $spenden = $this->kategorie('Spenden');
        $getraenke = $this->kategorie('Verkauf Speisen & Getränke');

        $heute = new \DateTimeImmutable()->format('Y-m-d');
        $this->post('/app/buchungen', $this->buchung($kasse, ['datum' => $heute, 'kategorie' => (string) $getraenke, 'zweck' => 'Getränkeverkauf Heimspiel']));
        $this->post('/app/buchungen', $this->buchung($kasse, ['datum' => ($this->jahr - 1) . '-06-01', 'kategorie' => (string) $spenden, 'zweck' => 'Spende Vorjahr']));
        $this->importierteBuchung($bank, $heute, -4990, 'Trikots Jugend', 'Sport Shop');
        $this->importierteBuchung($bank, $heute, 2000, 'Mitgliedsbeitrag', 'Max Muster');

        $zwecke = fn(array $query): array => $this->zweckeIn($this->get('/app/buchungen', $query)->body);

        self::assertSame(['Mitgliedsbeitrag', 'Trikots Jugend', 'Getränkeverkauf Heimspiel'], $zwecke([]), 'the current year by default, newest first');
        self::assertSame(
            ['Mitgliedsbeitrag', 'Trikots Jugend', 'Getränkeverkauf Heimspiel', 'Spende Vorjahr'],
            $zwecke(['von' => '', 'bis' => '']),
            'empty dates lift the year',
        );
        self::assertSame(['Spende Vorjahr'], $zwecke(['von' => ($this->jahr - 1) . '-01-01', 'bis' => ($this->jahr - 1) . '-12-31']));
        self::assertSame(['Getränkeverkauf Heimspiel'], $zwecke(['konto' => (string) $kasse]));
        self::assertSame(['Trikots Jugend'], $zwecke(['richtung' => 'ausgabe']));
        self::assertSame(['Trikots Jugend'], $zwecke(['beleg' => 'fehlt']));
        self::assertSame(['Mitgliedsbeitrag', 'Getränkeverkauf Heimspiel'], $zwecke(['beleg' => 'nicht_noetig']));
        self::assertSame(['Getränkeverkauf Heimspiel'], $zwecke(['quelle' => 'manuell']));
        self::assertSame(['Mitgliedsbeitrag', 'Trikots Jugend'], $zwecke(['quelle' => 'import']));
        self::assertSame(['Getränkeverkauf Heimspiel'], $zwecke(['kategorie' => (string) $getraenke]));
        self::assertSame(['Mitgliedsbeitrag', 'Trikots Jugend'], $zwecke(['kategorie' => 'ohne']));
        self::assertSame(['Trikots Jugend'], $zwecke(['suche' => 'sport shop']), 'searches the counterparty, case-insensitive');
        self::assertSame(['Mitgliedsbeitrag'], $zwecke(['suche' => 'beitrag']));
        self::assertSame([], $zwecke(['suche' => 'gibt es nicht']));

        $leer = $this->get('/app/buchungen', ['suche' => 'gibt es nicht'])->body;
        self::assertStringContainsString('Keine passenden Buchungen.', $leer);
        self::assertStringContainsString('Filter zurücksetzen', $leer);
        self::assertStringNotContainsString('Filter zurücksetzen', $this->get('/app/buchungen')->body);
    }

    /**
     * The period scope of external roles (docs/spec/01-sicherheit.md
     * section 4) narrows the list in SQL and hides a single booking outside
     * it - the same for the booking date as for every other dated figure.
     */
    public function testThePeriodScopeHidesOlderBookings(): void
    {
        $kasse = $this->kasse('Barkasse', '0,00', stichtag: ($this->jahr - 1) . '-01-01');
        $spenden = (string) $this->kategorie('Spenden');
        $this->post('/app/buchungen', $this->buchung($kasse, ['datum' => new \DateTimeImmutable()->format('Y-m-d'), 'kategorie' => $spenden, 'zweck' => 'Spende neu']));
        $this->post('/app/buchungen', $this->buchung($kasse, ['datum' => ($this->jahr - 1) . '-02-01', 'kategorie' => $spenden, 'zweck' => 'Spende alt']));
        [$neu, $alt] = array_map(static fn(BankTransaction $b): int => $b->id, $this->alle());

        $this->rolle(SystemRole::Steuerberater);
        new UserAccessRepository($this->pdo())->setScope($this->userId, new \DateTimeImmutable('today -30 days'), null);

        self::assertSame(['Spende neu'], $this->zweckeIn($this->get('/app/buchungen', ['von' => '', 'bis' => ''])->body));
        self::assertSame(200, $this->get('/app/buchungen/' . $neu)->status);
        self::assertSame(404, $this->get('/app/buchungen/' . $alt)->status);
        $seite = $this->get('/app/buchungen/' . $neu)->body;
        self::assertStringNotContainsString('name="betrag"', $seite, 'readers without bank.book get no form');
        self::assertStringContainsString('Manuell erfasste Buchung', $seite);
    }

    // ------------------------------------------------------- cash box

    /**
     * The expected cash of a cash count now includes the cash bookings from
     * the opening date up to the day counted.
     */
    public function testTheExpectedCashIncludesTheCashBookings(): void
    {
        $kasse = $this->kasse('Barkasse', '100,00', stichtag: ($this->jahr - 1) . '-01-01');
        $bank = $this->bankkonto('Girokonto', stichtag: ($this->jahr - 1) . '-01-01');
        $heute = new \DateTimeImmutable()->format('Y-m-d');
        $this->post('/app/buchungen', $this->buchung($kasse, ['datum' => ($this->jahr - 1) . '-03-01', 'betrag' => '40,00', 'kategorie' => (string) $this->kategorie('Spenden')]));
        $this->post('/app/buchungen', $this->buchung($kasse, ['datum' => $heute, 'betrag' => '15,50', 'richtung' => 'ausgabe', 'kategorie' => (string) $this->kategorie('Verpflegung & Bewirtung')]));
        $this->post('/app/buchungen', $this->buchung($bank, ['datum' => $heute, 'betrag' => '999,00', 'kategorie' => (string) $this->kategorie('Spenden')]));

        $formular = $this->get('/app/konten/' . $kasse . '/kassensturz')->body;
        self::assertStringContainsString('<strong>124,50 €</strong>', $formular, '100 + 40 - 15,50; the bank account does not count');

        $vorher = $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'berechnen', 'datum' => ($this->jahr - 1) . '-12-31', 'ist' => '140,00', 'notiz' => '']);
        self::assertStringContainsString('<th scope="row">Soll-Bestand</th><td class="zahl">140,00 €</td>', $vorher->body, 'bookings after the day do not count');
        self::assertStringContainsString('Kasse stimmt', $vorher->body);
    }

    /**
     * The mandatory test "Kassensturz-Differenz": saving a count that does
     * not match leads to the suggested booking "Kassendifferenz" - on the day
     * of the count, the difference as amount, no receipt. Once booked, the
     * cash box matches and the suggestion is gone.
     */
    public function testACashCountDifferenceIsSuggestedAsBooking(): void
    {
        $kasse = $this->kasse('Barkasse', '150,00');
        $heute = new \DateTimeImmutable()->format('Y-m-d');
        $sonstiges = $this->kategorie('Sonstiges');

        $antwort = $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'speichern', 'datum' => $heute, 'ist' => '142,50', 'notiz' => '']);
        $zaehlung = (int) $this->pdo()->query('SELECT MAX(id) FROM cash_count')->fetchColumn();
        self::assertSame('/app/buchungen/neu?kassensturz=' . $zaehlung, $antwort->headers['Location'] ?? null);

        $vorschlag = $this->get('/app/buchungen/neu', ['kassensturz' => (string) $zaehlung]);
        self::assertSame(200, $vorschlag->status);
        self::assertStringContainsString('Vorschlag aus dem Kassensturz', $vorschlag->body);
        self::assertStringContainsString('name="kassensturz" value="' . $zaehlung . '"', $vorschlag->body);
        self::assertStringContainsString('value="7,50"', $vorschlag->body);
        self::assertStringContainsString('<option value="ausgabe" selected>', $vorschlag->body);
        self::assertStringContainsString('value="' . $heute . '"', $vorschlag->body);
        self::assertStringContainsString('value="Kassendifferenz"', $vorschlag->body);
        self::assertStringContainsString('<option value="' . $sonstiges . '" selected>', $vorschlag->body);
        self::assertStringContainsString('<option value="0" selected>kein Beleg nötig</option>', $vorschlag->body);

        // Not booked yet: the cash box page keeps the suggestion.
        self::assertStringContainsString('Als Kassendifferenz buchen', $this->get('/app/konten/' . $kasse)->body);

        $buchen = $this->post('/app/buchungen', [
            'kassensturz' => (string) $zaehlung,
            ...$this->buchung($kasse, ['datum' => $heute, 'betrag' => '7,50', 'richtung' => 'ausgabe', 'kategorie' => (string) $sonstiges, 'zweck' => 'Kassendifferenz', 'beleg' => '0']),
        ]);
        self::assertSame('/app/konten/' . $kasse, $buchen->headers['Location'] ?? null, 'back to the cash box');

        $buchung = $this->alle()[0];
        self::assertSame(-750, $buchung->amount);
        self::assertSame(BankTransactionDocStatus::NichtNoetig, $buchung->docStatus);

        $kassenSeite = $this->get('/app/konten/' . $kasse)->body;
        self::assertStringNotContainsString('Als Kassendifferenz buchen', $kassenSeite);
        self::assertStringContainsString('<strong>142,50 €</strong>', $this->get('/app/konten/' . $kasse . '/kassensturz')->body);

        // Asking again: nothing left to book.
        $nochmal = $this->get('/app/buchungen/neu', ['kassensturz' => (string) $zaehlung]);
        self::assertSame('/app/konten/' . $kasse, $nochmal->headers['Location'] ?? null);

        // A matching count goes straight back to the cash box.
        $stimmt = $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'speichern', 'datum' => $heute, 'ist' => '142,50', 'notiz' => '']);
        self::assertSame('/app/konten/' . $kasse, $stimmt->headers['Location'] ?? null);
    }

    public function testASurplusIsSuggestedAsIncome(): void
    {
        $kasse = $this->kasse('Barkasse', '10,00');
        $this->post('/app/konten/' . $kasse . '/kassensturz', ['aktion' => 'speichern', 'datum' => new \DateTimeImmutable()->format('Y-m-d'), 'ist' => '12,00', 'notiz' => '']);
        $zaehlung = (int) $this->pdo()->query('SELECT MAX(id) FROM cash_count')->fetchColumn();

        $vorschlag = $this->get('/app/buchungen/neu', ['kassensturz' => (string) $zaehlung])->body;
        self::assertStringContainsString('value="2,00"', $vorschlag);
        self::assertStringContainsString('<option value="einnahme" selected>', $vorschlag);
        self::assertStringContainsString('<option value="' . $this->kategorie('Sonstige Einnahmen') . '" selected>', $vorschlag);

        self::assertSame(404, $this->get('/app/buchungen/neu', ['kassensturz' => '999'])->status);
    }

    // ------------------------------------------- usage, rights, vault

    /** A booking is a use: the account and the category are deactivated instead of deleted. */
    public function testABookingKeepsItsAccountAndCategory(): void
    {
        $kasse = $this->kasse('Barkasse', '0,00');
        $spenden = $this->kategorie('Spenden');
        $this->post('/app/buchungen', $this->buchung($kasse, ['kategorie' => (string) $spenden]));

        self::assertSame(1, new BankAccountRepository($this->pdo())->usageCount($kasse));
        self::assertGreaterThan(0, new CategoryRepository($this->pdo())->usageCount($spenden));
        $this->post('/app/konten/' . $kasse . '/loeschen', []);
        self::assertNotNull(new BankAccountRepository($this->pdo())->find($kasse));

        // A deactivated account keeps its bookings and they stay editable.
        $this->pdo()->exec('UPDATE bank_account SET active = 0 WHERE id = ' . $kasse);
        $id = $this->einzigeBuchung();
        $antwort = $this->post('/app/buchungen/' . $id, $this->buchung($kasse, ['kategorie' => (string) $spenden, 'zweck' => 'Korrigiert']));
        self::assertSame(302, $antwort->status);
        self::assertSame('Korrigiert', $this->buchungAus($id)->purpose);
    }

    public function testReadersWithoutBankBookSeeButDoNotWrite(): void
    {
        $kasse = $this->kasse('Barkasse', '0,00');
        $this->post('/app/buchungen', $this->buchung($kasse, ['kategorie' => (string) $this->kategorie('Spenden')]));
        $id = $this->einzigeBuchung();

        $this->rolle(SystemRole::Kassenpruefer);
        $liste = $this->get('/app/buchungen');
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('Spende', $liste->body);
        self::assertStringNotContainsString('/app/buchungen/neu', $liste->body);
        self::assertSame(403, $this->get('/app/buchungen/neu')->status);
        self::assertSame(403, $this->post('/app/buchungen', $this->buchung($kasse, []))->status);
        self::assertSame(403, $this->post('/app/buchungen/' . $id, $this->buchung($kasse, []))->status);
        self::assertSame(403, $this->post('/app/buchungen/' . $id . '/loeschen', [])->status);
        self::assertCount(1, $this->alle());
    }

    public function testWithoutTheVaultThePagesShowAndWriteNothing(): void
    {
        $kasse = $this->kasse('Barkasse', '0,00');
        $this->post('/app/buchungen', $this->buchung($kasse, ['kategorie' => (string) $this->kategorie('Spenden'), 'zweck' => 'Geheime Spende']));
        $id = $this->einzigeBuchung();

        $liste = $this->get('/app/buchungen', entsperrt: false);
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('nur mit entsperrtem Tresor lesbar', $liste->body);
        self::assertStringNotContainsString('Geheime Spende', $liste->body);
        self::assertSame('/app/buchungen', $this->get('/app/buchungen/' . $id, entsperrt: false)->headers['Location'] ?? null);
        self::assertSame('/app/buchungen', $this->get('/app/buchungen/neu', entsperrt: false)->headers['Location'] ?? null);

        $this->post('/app/buchungen', $this->buchung($kasse, ['kategorie' => (string) $this->kategorie('Spenden')]), entsperrt: false);
        $this->post('/app/buchungen/' . $id . '/loeschen', [], entsperrt: false);
        self::assertCount(1, $this->alle());
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $kasse = $this->kasse('Barkasse', '0,00');
        $felder = $this->buchung($kasse, ['kategorie' => (string) $this->kategorie('Spenden')]);
        $ohne = $this->dispatch(new Request(HttpMethod::Post, '/app/buchungen', cookies: $this->tresorCookie(), post: $felder));
        self::assertSame(302, $ohne->status);
        self::assertSame('/app/buchungen/neu', $ohne->headers['Location'] ?? null);
        self::assertSame([], $this->alle());

        $this->post('/app/buchungen', $felder);
        $id = $this->einzigeBuchung();
        $loeschen = $this->dispatch(new Request(HttpMethod::Post, '/app/buchungen/' . $id . '/loeschen', cookies: $this->tresorCookie(), post: []));
        self::assertSame('/app/buchungen/' . $id, $loeschen->headers['Location'] ?? null);
        self::assertCount(1, $this->alle());
    }

    public function testAnUnknownBookingIsNotFound(): void
    {
        self::assertSame(404, $this->get('/app/buchungen/999')->status);
        self::assertSame(404, $this->post('/app/buchungen/999', [])->status);
        self::assertSame(404, $this->post('/app/buchungen/999/loeschen', [])->status);
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
    private function buchung(int $konto, array $felder): array
    {
        return [...[
            'konto' => (string) $konto,
            'datum' => new \DateTimeImmutable()->format('Y-m-d'),
            'betrag' => '10,00',
            'richtung' => 'einnahme',
            'kategorie' => '',
            'zweck' => 'Spende',
            'gegenseite' => '',
            'beleg' => '',
        ], ...$felder];
    }

    private function kasse(string $name, string $bestand, string $stichtag = '2026-01-01'): int
    {
        return $this->konto(['art' => 'kasse', 'name' => $name, 'opening_balance' => $bestand, 'opening_date' => $stichtag]);
    }

    private function bankkonto(string $name, string $stichtag = '2026-01-01'): int
    {
        return $this->konto(['art' => 'bank', 'name' => $name, 'opening_balance' => '0,00', 'opening_date' => $stichtag]);
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
    private function importierteBuchung(int $konto, string $datum, int $cent, string $zweck, string $name): int
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
            BankTransactionDocStatus::fuerNeueBuchung($richtung->belegNoetigStandard()),
            $richtung->belegNoetigStandard(),
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => $name, 'counterparty_iban' => '',
            'purpose' => $zweck, 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    /**
     * @return list<string> the purposes in the list, in order
     */
    private function zweckeIn(string $html): array
    {
        preg_match_all('/<td data-label="Zweck">\s*(.*?)\s*(?:<br>|<span|<\/td>)/s', $html, $treffer);

        return array_map(static fn(string $z): string => html_entity_decode($z), $treffer[1]);
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

    /**
     * @return list<BankTransaction> every booking, newest first
     */
    private function alle(): array
    {
        return $this->buchungen()->liste($this->tresor, new BuchungFilter(), new UserAccessRepository($this->pdo())->berechtigungen($this->userId)->zugriffsbereich(Permission::BankView));
    }

    private function buchungAus(int $id): BankTransaction
    {
        return $this->buchungen()->finde($this->tresor, $id, Zugriffsbereich::unbeschraenkt()) ?? self::fail('No booking ' . $id);
    }

    private function einzigeBuchung(): int
    {
        $ids = $this->pdo()->query('SELECT id FROM bank_transaction')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $ids);

        return (int) $ids[0];
    }

    /**
     * @return list<AuditEntry> newest first
     */
    private function protokoll(int $id): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'bank_transaction', entityId: $id), null, 10);
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
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the account pages 28th, the bookings last.
        $controller = array_fill(0, 34, $unerreichbar);
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
