<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\ZahllaufController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\BankAccountKind;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSource;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InvoiceData;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
use App\Domain\SystemRole;
use App\Domain\Zahlungsweg;
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
use App\Repository\CategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\ErstattungRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRunRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
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
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\BelegStandard;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Erstattungen;
use App\Service\Bank\Import\KontoauszugImport;
use App\Service\Bank\Uebersichten;
use App\Service\Bank\Zahllaeufe;
use App\Service\Bank\ZahllaufGruppe;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\MasterData\SupplierService;
use App\Service\Migration\Migrator;
use App\Tests\Service\Bank\Sepa\Pain001Test;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;
use App\View\View;

/**
 * SEPA payment runs for reimbursements (issue #85, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand SEPA-Zahllauf", E-42): open
 * reimbursements per payee become one pain.001 file, each receipt at most
 * once, everything encrypted, the matching settles the run.
 */
final class ZahllaufFlowTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.10';

    private const string IBAN_ANNA = 'DE89370400440532013000';

    private const string IBAN_BERT = 'DE02120300000000202051';

    private const string IBAN_VEREIN = 'DE12500105170648489890';

    private const string IBAN_FREMD = 'DE75512108001245126199';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $konto;
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
        $this->userId = new UserRepository($this->pdo())->insert($crypto->encrypt('finanzen@example.org'), random_bytes(32), $crypto->encrypt('Fritz Finanzen'), 'hash', mfaRequired: false);
        $this->konto = new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen(
            $this->tresor,
            BankAccountKind::Bank,
            ['name' => 'Girokonto', 'iban' => self::IBAN_VEREIN, 'bic' => 'INGDDEFFXXX', 'bank' => '', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01'],
            new \DateTimeImmutable(),
        )->id;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testARunPaysEachPayeeOnceWithTheOpenSumOfTheChosenReceipts(): void
    {
        $anna1 = $this->erstattung(2500, 'Anna Müller', self::IBAN_ANNA, nummer: 'K-1');
        $anna2 = $this->erstattung(1730, 'anna  müller', 'de89 3704 0044 0532 0130 00', datum: '2026-02-20');
        $bert = $this->erstattung(999, 'Bert Beispiel', self::IBAN_BERT);
        $nichtGewaehlt = $this->erstattung(500, 'Bert Beispiel', self::IBAN_BERT);

        $id = $this->anlegen([$anna2, $anna1, $bert]);

        $lauf = $this->zahllaeufe()->finde($this->tresor, $id, Zugriffsbereich::unbeschraenkt()) ?? self::fail();
        self::assertSame('angewiesen', $lauf->statusText());
        self::assertSame(5229, $lauf->summe());
        self::assertCount(2, $lauf->ueberweisungen);
        [$an, $be] = $lauf->ueberweisungen;
        self::assertSame('Anna Mueller', $an->name, 'SEPA characters');
        self::assertSame(self::IBAN_ANNA, $an->iban);
        self::assertSame(4230, $an->cent);
        self::assertSame([$anna1, $anna2], $an->belege);
        self::assertSame('Auslagenerstattung Belege K-1, vom 20.02.2026', $an->zweck);
        self::assertSame(999, $be->cent);
        self::assertSame([$bert], $be->belege);
        self::assertSame('Girokonto', $lauf->kontoName);
        self::assertSame('TSV Beispiel e.V.', $lauf->auftraggeber);

        $datei = $this->zahllaeufe()->datei($this->tresor, $id, Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable()) ?? self::fail();
        self::assertSame('zahllauf-' . $id . '.xml', $datei['name']);
        self::assertTrue(Pain001Test::gueltig($datei['xml']), implode("\n", Pain001Test::fehler($datei['xml'])));
        self::assertStringContainsString('<CtrlSum>52.29</CtrlSum>', $datei['xml']);
        self::assertStringContainsString('<IBAN>' . self::IBAN_VEREIN . '</IBAN>', $datei['xml']);
        self::assertStringContainsString('<BICFI>INGDDEFFXXX</BICFI>', $datei['xml']);
        self::assertStringContainsString('<EndToEndId>VB-Z' . $id . '-1</EndToEndId>', $datei['xml']);

        // The receipt not chosen stays open for a later run.
        $gruppe = $this->gruppe('Bert Beispiel');
        self::assertNull($gruppe->sperrgrund);
        self::assertSame([null, 'Bereits in Zahllauf ' . $id . ' angewiesen.'], array_map(
            static fn($k): ?string => $k->sperrgrund,
            self::sortiert($gruppe, [$nichtGewaehlt, $bert]),
        ));

        // Nothing readable in the database or the log.
        $roh = $this->rohTabelle('payment_run') . $this->rohTabelle('payment_run_item') . $this->rohTabelle('audit_log');
        foreach (['Anna', 'Mueller', self::IBAN_ANNA, self::IBAN_VEREIN, '42.30', '4230', '52.29', '5229', 'K-1'] as $klartext) {
            self::assertStringNotContainsString($klartext, $roh);
        }
        $protokoll = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'payment_run', entityId: $id), null, 10);
        self::assertSame(
            [AuditAction::ZahllaufHeruntergeladen->value, AuditAction::ZahllaufAngelegt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $protokoll),
        );
    }

    public function testAReceiptIsNeverOrderedTwiceUntilItsRunIsDiscarded(): void
    {
        $beleg = $this->erstattung(2500, 'Anna Auslage', self::IBAN_ANNA);
        $erster = $this->anlegen([$beleg]);

        try {
            $this->anlegen([$beleg]);
            self::fail('ordered twice');
        } catch (BankRuleViolation $e) {
            self::assertStringContainsString('Bereits in Zahllauf ' . $erster, $e->getMessage());
        }
        // Even past the check: the UNIQUE key refuses a second active item.
        self::assertFalse(new PaymentRunRepository($this->pdo())->insertItem($erster, $beleg));
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM payment_run')->fetchColumn());

        $this->zahllaeufe()->verwerfen($erster, Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('verworfen', $this->zahllaeufe()->finde($this->tresor, $erster, Zugriffsbereich::unbeschraenkt())?->statusText());
        self::assertNull($this->zahllaeufe()->datei($this->tresor, $erster, Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable()), 'no file of a discarded run');
        try {
            $this->zahllaeufe()->verwerfen($erster, Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('discarded twice');
        } catch (BankRuleViolation) {
        }

        $zweiter = $this->anlegen([$beleg]);
        self::assertNotSame($erster, $zweiter);
    }

    public function testPayeesThatNeedAHumanCheckAreNotOffered(): void
    {
        // The same name with two accounts: the public form lets anybody use any name.
        $echt = $this->erstattung(2500, 'Anna Auslage', self::IBAN_ANNA);
        $fremd = $this->erstattung(9900, 'Anna Auslage', self::IBAN_FREMD);
        $verein = $this->erstattung(1000, 'Kassenwart', self::IBAN_VEREIN);
        $bar = $this->erstattung(1200, 'Bert Beispiel', self::IBAN_BERT, art: 'bar');
        $ungeprueft = $this->erstattung(1300, 'Bert Beispiel', self::IBAN_BERT, status: DocumentStatus::InPruefung);
        $ok = $this->erstattung(1400, 'Bert Beispiel', self::IBAN_BERT);

        $gruppen = $this->zahllaeufe()->vorbereitung($this->tresor, Zugriffsbereich::unbeschraenkt(), new \DateTimeImmutable())->gruppen;
        $nachIban = [];
        foreach ($gruppen as $g) {
            $nachIban[$g->iban] = $g;
        }
        self::assertStringContainsString('anderen oder ohne IBAN', (string) $nachIban[self::IBAN_ANNA]->sperrgrund);
        self::assertStringContainsString('anderen oder ohne IBAN', (string) $nachIban[self::IBAN_FREMD]->sperrgrund);
        self::assertSame('Die IBAN ist ein Konto des Vereins.', $nachIban[self::IBAN_VEREIN]->sperrgrund);
        self::assertFalse($nachIban[self::IBAN_ANNA]->hatWaehlbare());
        self::assertSame(
            ['Laut Einreichung nicht per Überweisung zu erstatten.', 'Der Beleg ist noch nicht geprüft.', null],
            array_map(static fn($k): ?string => $k->sperrgrund, self::sortiert($nachIban[self::IBAN_BERT], [$bar, $ungeprueft, $ok])),
        );
        self::assertSame(1400, $nachIban[self::IBAN_BERT]->waehlbareSumme());

        foreach ([$echt, $fremd, $verein, $bar, $ungeprueft] as $gesperrt) {
            try {
                $this->anlegen([$ok, $gesperrt]);
                self::fail('offered a receipt that needs a check');
            } catch (BankRuleViolation $e) {
                self::assertStringContainsString('Die Auswahl ist nicht mehr aktuell', $e->getMessage());
            }
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM payment_run')->fetchColumn(), 'nothing written');
    }

    public function testTheOrderItselfIsChecked(): void
    {
        $beleg = $this->erstattung(2500, 'Anna Auslage', self::IBAN_ANNA);
        $kasse = new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen(
            $this->tresor,
            BankAccountKind::Kasse,
            ['name' => 'Kasse', 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01'],
            new \DateTimeImmutable(),
        )->id;

        $faelle = [
            'no receipt' => [[], $this->konto, 'TSV', 'today', 'mindestens einen Beleg'],
            'cash box' => [[$beleg], $kasse, 'TSV', 'today', 'Bankkonto'],
            'no payer name' => [[$beleg], $this->konto, '日本', 'today', 'Kontoinhabers'],
            'in the past' => [[$beleg], $this->konto, 'TSV', 'yesterday', 'Vergangenheit'],
            'too far' => [[$beleg], $this->konto, 'TSV', '+2 years', 'ein Jahr'],
        ];
        foreach ($faelle as $fall => [$belege, $konto, $name, $datum, $meldung]) {
            try {
                $this->zahllaeufe()->anlegen($this->tresor, Zugriffsbereich::unbeschraenkt(), $belege, $konto, $name, new \DateTimeImmutable($datum), $this->userId, self::IP, new \DateTimeImmutable());
                self::fail($fall);
            } catch (BankRuleViolation $e) {
                self::assertStringContainsString($meldung, $e->getMessage(), $fall);
            }
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM payment_run')->fetchColumn());
    }

    public function testTheMatchingSettlesTheRun(): void
    {
        $anna1 = $this->erstattung(2500, 'Anna Auslage', self::IBAN_ANNA);
        $anna2 = $this->erstattung(1500, 'Anna Auslage', self::IBAN_ANNA);
        $bert = $this->erstattung(999, 'Bert Beispiel', self::IBAN_BERT);
        $id = $this->anlegen([$anna1, $anna2, $bert]);

        // The bank books every transfer on its own (BtchBookg false): the
        // reimbursement matching finds Anna's by her IBAN.
        $buchung = $this->buchung(-4000, self::IBAN_ANNA);
        $erstattungen = $this->erstattungen();
        $vorschlag = $erstattungen->vorschlag($this->tresor, $buchung, Zugriffsbereich::unbeschraenkt()) ?? self::fail();
        self::assertSame([[$anna1, $anna2]], array_map(static fn(array $k): array => array_map(static fn($e): int => $e->invoiceId, $k), $vorschlag->kombinationen));
        $erstattungen->zuordnen($this->tresor, $buchung, [$anna1, $anna2], Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable());

        $lauf = $this->zahllaeufe()->finde($this->tresor, $id, Zugriffsbereich::unbeschraenkt()) ?? self::fail();
        self::assertSame('angewiesen', $lauf->statusText(), "Bert's transfer is not matched yet");
        self::assertTrue($lauf->ueberweisungAusgeglichen($lauf->ueberweisungen[0]));
        self::assertFalse($lauf->ueberweisungAusgeglichen($lauf->ueberweisungen[1]));

        $erstattungen->zuordnen($this->tresor, $this->buchung(-999, self::IBAN_BERT), [$bert], Zugriffsbereich::unbeschraenkt(), $this->userId, self::IP, new \DateTimeImmutable());
        self::assertSame('ausgeglichen', $this->zahllaeufe()->finde($this->tresor, $id, Zugriffsbereich::unbeschraenkt())?->statusText());
        // A settled receipt is not offered again.
        self::assertSame([], $this->zahllaeufe()->vorbereitung($this->tresor, Zugriffsbereich::unbeschraenkt(), new \DateTimeImmutable())->gruppen);
    }

    public function testTheScopeLimitsReceiptsAndRuns(): void
    {
        $alt = $this->erstattung(2500, 'Anna Auslage', self::IBAN_ANNA, datum: '2026-02-14');
        $id = $this->anlegen([$alt]);
        $neu = $this->erstattung(1500, 'Bert Beispiel', self::IBAN_BERT, datum: '2026-03-10');
        $ab = new Zugriffsbereich(von: new \DateTimeImmutable('2026-03-01'));

        $gruppen = $this->zahllaeufe()->vorbereitung($this->tresor, $ab, new \DateTimeImmutable())->gruppen;
        self::assertSame(['Bert Beispiel'], array_map(static fn(ZahllaufGruppe $g): string => $g->name, $gruppen));
        self::assertNull($this->zahllaeufe()->finde($this->tresor, $id, $ab), 'a run with a receipt outside the scope is hidden');
        self::assertSame([], $this->zahllaeufe()->liste($this->tresor, $ab)['laeufe']);
        try {
            $this->zahllaeufe()->verwerfen($id, $ab, $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('discarded outside the scope');
        } catch (BankRuleViolation) {
        }
        try {
            $this->zahllaeufe()->anlegen($this->tresor, $ab, [$alt, $neu], $this->konto, 'TSV', new \DateTimeImmutable('today'), $this->userId, self::IP, new \DateTimeImmutable());
            self::fail('ordered outside the scope');
        } catch (BankRuleViolation) {
        }
        self::assertCount(1, $this->zahllaeufe()->liste($this->tresor, Zugriffsbereich::unbeschraenkt())['laeufe']);
    }

    public function testThePagesAndTheDownload(): void
    {
        $this->rolle(SystemRole::Finanzen);
        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
        new SettingRepository($this->pdo())->set('mail_vereinsname', 'TSV Beispiel e.V.');
        $beleg = $this->erstattung(2500, 'Anna Auslage', self::IBAN_ANNA, nummer: 'K-9');

        $neu = $this->get('/app/zahllaeufe/neu');
        self::assertSame(200, $neu->status);
        self::assertStringContainsString('name="belege[]" value="' . $beleg . '" checked', $neu->body);
        self::assertStringContainsString('value="TSV Beispiel e.V."', $neu->body);

        $angelegt = $this->post('/app/zahllaeufe', ['belege' => [(string) $beleg], 'konto' => (string) $this->konto, 'auftraggeber' => 'TSV Beispiel e.V.', 'ausfuehrung' => new \DateTimeImmutable('today')->format('Y-m-d')]);
        self::assertSame(302, $angelegt->status);
        $ort = $angelegt->headers['Location'] ?? '';
        self::assertMatchesRegularExpression('~^/app/zahllaeufe/\d+$~', $ort);

        $seite = $this->get($ort);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Auslagenerstattung Beleg K-9', $seite->body);
        self::assertStringContainsString('SEPA-Datei herunterladen', $seite->body);

        $datei = $this->get($ort . '/datei');
        self::assertSame(200, $datei->status);
        self::assertSame('application/xml; charset=utf-8', $datei->headers['Content-Type'] ?? null);
        self::assertSame('attachment; filename="zahllauf-' . basename($ort) . '.xml"', $datei->headers['Content-Disposition'] ?? null);
        self::assertSame('no-store, private', $datei->headers['Cache-Control'] ?? null);
        self::assertTrue(Pain001Test::gueltig($datei->body));

        self::assertStringContainsString('Zahllauf', $this->get('/app/zahllaeufe')->body);
        // Without the unlocked vault: nothing.
        self::assertSame(302, $this->get($ort . '/datei', entsperrt: false)->status);
        self::assertSame(404, $this->get('/app/zahllaeufe/999999')->status);

        // Without the CSRF token nothing is discarded.
        $ohne = $this->kernel()->handle(new Request(HttpMethod::Post, $ort . '/verwerfen', cookies: $this->tresorCookie()));
        self::assertInstanceOf(Response::class, $ohne);
        self::assertSame('angewiesen', $this->zeile('payment_run', (int) basename($ort))['status']);
        $this->post($ort . '/verwerfen', []);
        self::assertSame('verworfen', $this->zeile('payment_run', (int) basename($ort))['status']);
    }

    /**
     * @param list<int> $belege
     */
    private function anlegen(array $belege): int
    {
        return $this->zahllaeufe()->anlegen($this->tresor, Zugriffsbereich::unbeschraenkt(), $belege, $this->konto, 'TSV Beispiel e.V.', new \DateTimeImmutable('today'), $this->userId, self::IP, new \DateTimeImmutable());
    }

    private function gruppe(string $name): ZahllaufGruppe
    {
        foreach ($this->zahllaeufe()->vorbereitung($this->tresor, Zugriffsbereich::unbeschraenkt(), new \DateTimeImmutable())->gruppen as $g) {
            if ($g->name === $name) {
                return $g;
            }
        }
        self::fail('no group ' . $name);
    }

    /**
     * The group's candidates in the order of $ids.
     *
     * @param list<int> $ids
     *
     * @return list<\App\Service\Bank\ZahllaufKandidat>
     */
    private static function sortiert(ZahllaufGruppe $gruppe, array $ids): array
    {
        $nachId = [];
        foreach ($gruppe->kandidaten as $k) {
            $nachId[$k->posten->record->id] = $k;
        }

        return array_map(static fn(int $id) => $nachId[$id] ?? self::fail('missing ' . $id), $ids);
    }

    private function zahllaeufe(): Zahllaeufe
    {
        $pdo = $this->pdo();
        $kategorien = new CategoryRepository($pdo);
        $konten = new BankAccountService($pdo, new BankAccountRepository($pdo));

        return new Zahllaeufe(
            $pdo,
            new PaymentRunRepository($pdo),
            new Uebersichten(
                new InvoiceRepository($pdo),
                new SupplierService($pdo, new SupplierRepository($pdo), $kategorien),
                ZuordnungFabrik::bauen($pdo, $this->audit),
                new Buchungen($pdo, new BankTransactionRepository($pdo), $konten, $kategorien, BelegStandard::fromSettings(new SettingRepository($pdo))),
            ),
            $konten,
            $this->audit,
        );
    }

    private function erstattungen(): Erstattungen
    {
        return new Erstattungen(
            $this->pdo(),
            new ErstattungRepository($this->pdo()),
            new InvoiceRepository($this->pdo()),
            new BankTransactionRepository($this->pdo()),
            ZuordnungFabrik::bauen($this->pdo(), $this->audit),
        );
    }

    private function erstattung(
        int $brutto,
        string $name,
        string $iban,
        string $art = 'ueberweisung',
        DocumentStatus $status = DocumentStatus::Geprueft,
        string $datum = '2026-02-14',
        string $nummer = '',
    ): int {
        ++$this->laufnummer;
        $einreichungen = new SubmissionRepository($this->pdo());
        $submission = $einreichungen->insertDraft(random_bytes(32), new \DateTimeImmutable());
        $schluessel = DataKey::generate();
        $payload = json_encode([
            'name' => $name,
            'email' => null,
            'erstattung' => ['art' => $art, 'iban' => $iban, 'kontoinhaber' => $name],
            'freitext' => '',
            'kostenstelle_hinweis' => null,
        ], JSON_THROW_ON_ERROR);
        $einreichungen->complete(
            $submission,
            sprintf('R-2026-%04d', $this->laufnummer),
            $this->tresor->sealDataKey($schluessel),
            FieldCipher::encrypt($schluessel, $payload, new FieldContext('submission', $submission, 'payload_enc')),
        );
        $dokument = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Einreichung,
            $submission,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('+' . $this->laufnummer . ' seconds'),
            $status,
        );
        $key = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, null, new \DateTimeImmutable($datum), null, null, null, null, null, Zahlungsweg::Erstattung),
            $this->tresor->sealDataKey($key),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData($nummer, $brutto, null, [], 'EUR', 'Auslage', '');
        $rechnungen->setData($id, FieldCipher::encrypt($key, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    /** A booking as the statement import writes it - with `counterparty_bi`. */
    private function buchung(int $cent, string $iban): int
    {
        $repository = new BankTransactionRepository($this->pdo());
        $richtung = BankTransactionDirection::ausDemBetrag($cent);
        $key = DataKey::generate();
        $id = $repository->insert(
            $this->konto,
            null,
            new \DateTimeImmutable('2026-03-02'),
            null,
            $richtung,
            $this->tresor->sealDataKey($key),
            random_bytes(32),
            $this->tresor->blindIndex()->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, $iban),
            BankTransactionDocStatus::fuerNeueBuchung($richtung === BankTransactionDirection::Ausgabe),
            $richtung === BankTransactionDirection::Ausgabe,
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => 'Empfaenger', 'counterparty_iban' => $iban,
            'purpose' => 'Auslagenerstattung', 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    private function rolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    /**
     * @return array<string, string>
     */
    private function tresorCookie(): array
    {
        return [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => new SessionVault()->store($this->tresor)];
    }

    private function get(string $pfad, bool $entsperrt = true): Response
    {
        $antwort = $this->kernel()->handle(new Request(HttpMethod::Get, $pfad, cookies: $entsperrt ? $this->tresorCookie() : []));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function post(string $pfad, array $felder): Response
    {
        $antwort = $this->kernel()->handle(new Request(
            HttpMethod::Post,
            $pfad,
            cookies: $this->tresorCookie(),
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
        ));
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
        $zahllaeufe = fn(): ZahllaufController => new ZahllaufController($view, new Session(), new SessionVault(), $this->zahllaeufe(), new SettingRepository($pdo));
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $controller = array_fill(0, 38, $unerreichbar);
        $controller[1] = $guard;

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')($router, $view, ...$controller, zahllaeufe: $zahllaeufe);

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function zeile(string $tabelle, int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM ' . $tabelle . ' WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);

        return $zeile;
    }

    private function rohTabelle(string $tabelle): string
    {
        $roh = '';
        foreach ($this->pdo()->query('SELECT * FROM ' . $tabelle)->fetchAll() as $zeile) {
            $roh .= implode('|', array_map(static fn(mixed $w): string => (string) $w, $zeile)) . "\n";
        }

        return $roh;
    }
}
