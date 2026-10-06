<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\AccountController;
use App\App\CsvFormatController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\SystemRole;
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
use App\Repository\CashCountRepository;
use App\Repository\CsvProfileRepository;
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
use App\Service\Bank\Csv\CsvDatumsformat;
use App\Service\Bank\Csv\CsvDezimaltrenner;
use App\Service\Bank\Csv\CsvProfil;
use App\Service\Bank\Csv\CsvStandardprofile;
use App\Service\Bank\Csv\CsvTrennzeichen;
use App\Service\Bank\Csv\CsvZeichensatz;
use App\Service\Bank\Kassensturz;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * CSV formats and the mapping assistant end to end (M9-3, issue #61,
 * docs/spec/04-bank-und-abgleich.md section 3): the real route table and
 * guard, the real controller, repository and schema with its seed.
 */
final class CsvProfileFlowTest extends DatabaseTestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures/bank/';

    private Vault $tresor;
    private AuditLog $audit;
    private CsvProfileRepository $profile;
    private int $userId;

    /** @var list<string> temp files standing in for PHP's uploads */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), new ServerCrypto(random_bytes(32)));
        $this->profile = new CsvProfileRepository($this->pdo());

        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $this->rolle(SystemRole::Finanzen);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach ($this->uploads as $pfad) {
            if (is_file($pfad)) {
                unlink($pfad);
            }
        }
    }

    /**
     * Acceptance criterion "Mitgelieferte Profile für Sparkasse und VR
     * Bank": the seed is exactly CsvStandardprofile, read-only.
     */
    public function testTheShippedProfilesAreSeeded(): void
    {
        $alle = $this->profile->all();

        self::assertCount(2, $alle);
        foreach (CsvStandardprofile::alle() as $index => $erwartet) {
            $profil = $alle[$index];
            self::assertNotNull($profil->id);
            self::assertTrue($profil->mitgeliefert);
            self::assertEquals(
                new CsvProfil($profil->id, $erwartet->name, true, $erwartet->trennzeichen, $erwartet->zeichensatz,
                    $erwartet->datumsformat, $erwartet->dezimaltrenner, $erwartet->zuordnung, $erwartet->kopfSignatur),
                $profil,
                $erwartet->name . ': seed and CsvStandardprofile differ',
            );
        }

        $liste = $this->get('/app/konten/csv-formate');
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('Sparkasse CSV-CAMT', $liste->body);
        self::assertStringContainsString('VR Bank CSV-CAMT', $liste->body);
        self::assertStringContainsString('mitgeliefert', $liste->body);

        $id = $alle[0]->id;
        $seite = $this->get('/app/konten/csv-formate/' . $id);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Kundenreferenz (End-to-End)', $seite->body);
        self::assertStringContainsString('Glaeubiger ID oder Glaeubiger-ID', $seite->body);
        self::assertStringNotContainsString('/loeschen', $seite->body);
        self::assertStringNotContainsString('/bearbeiten', $seite->body);
    }

    public function testShippedProfilesCannotBeChangedOrDeleted(): void
    {
        $id = $this->profile->all()[0]->id ?? self::fail();

        $bearbeiten = $this->get('/app/konten/csv-formate/' . $id . '/bearbeiten');
        self::assertSame(302, $bearbeiten->status);
        self::assertSame('/app/konten/csv-formate/' . $id, $bearbeiten->headers['Location'] ?? null);

        $speichern = $this->post('/app/konten/csv-formate/' . $id, $this->felder('Umbenannt'));
        self::assertSame(302, $speichern->status);
        $loeschen = $this->post('/app/konten/csv-formate/' . $id . '/loeschen', []);
        self::assertSame(302, $loeschen->status);

        self::assertSame(CsvStandardprofile::SPARKASSE, $this->profile->find($id)?->name);
        self::assertFalse($this->profile->delete($id), 'the repository refuses too');
        self::assertFalse($this->profile->update($id, CsvStandardprofile::vrBank(), new \DateTimeImmutable()));
        self::assertSame(CsvStandardprofile::SPARKASSE, $this->profile->find($id)?->name);
    }

    public function testTheAssistantPageUsesHtmxWithoutInlineScript(): void
    {
        $seite = $this->get('/app/konten/csv-formate/neu');

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('hx-post="/app/konten/csv-formate/vorschau"', $seite->body);
        self::assertStringContainsString('hx-trigger="change"', $seite->body);
        self::assertStringContainsString('enctype="multipart/form-data"', $seite->body);
        // On a div inside the form, not on the form: htmx validates a form
        // before each request and would send nothing while the required
        // name is still empty.
        self::assertStringContainsString('<form method="post" action="/app/konten/csv-formate" enctype="multipart/form-data" class="formular">', $seite->body);
        self::assertStringContainsString('hx-include="closest form"', $seite->body);
        self::assertStringContainsString('Wählen Sie oben einen CSV-Export', $seite->body);
        self::assertStringNotContainsString('hx-on', $seite->body);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/', $seite->body, 'no inline script');
    }

    /**
     * Acceptance criteria "Mapping-Assistent für unbekannte
     * Spaltenbelegungen" and "Erkennung von Trennzeichen, Zeichensatz und
     * Zahlenformat": a new file is detected, the suggestion preselected and
     * the first rows previewed - as a fragment, without the layout.
     */
    public function testTheFirstPreviewDetectsTheFileAndSuggestsAMapping(): void
    {
        $antwort = $this->vorschau('unbekannt.csv', []);

        self::assertSame(200, $antwort->status);
        self::assertStringNotContainsString('<html', $antwort->body, 'a fragment, not a page');
        self::assertMatchesRegularExpression('/Erkannt: UTF-8, getrennt durch Komma \( , \),\s+Kopfzeile in Zeile 4\./', $antwort->body);
        self::assertMatchesRegularExpression('/name="trennzeichen">.*?<option value="komma" selected>/s', $antwort->body);
        self::assertMatchesRegularExpression('/name="datumsformat">.*?<option value="Y-m-d" selected>/s', $antwort->body);
        self::assertMatchesRegularExpression('/name="dezimaltrenner">.*?<option value="\." selected>/s', $antwort->body);
        self::assertMatchesRegularExpression('/id="csv-feld-buchungstag"[^>]*>.*?<option value="Datum" selected>/s', $antwort->body);
        self::assertMatchesRegularExpression('/id="csv-feld-soll"[^>]*>.*?<option value="Soll" selected>/s', $antwort->body);
        self::assertStringContainsString('value="Verwendungszweck 2" checked', $antwort->body);
        self::assertMatchesRegularExpression('/name="datei_kennung" value="[0-9a-f]{16}"/', $antwort->body);

        self::assertStringContainsString('3 Buchungen lesbar, 1 Zeile nicht lesbar.', $antwort->body);
        self::assertStringContainsString('Bahnmiete Training Maerz', $antwort->body);
        self::assertStringContainsString('-1.020,00 EUR', $antwort->body);
        self::assertStringContainsString('750,50 EUR', $antwort->body);
        self::assertStringContainsString('Zeile 8, Spalte „Soll“: weder Soll noch Haben gefüllt.', $antwort->body);
    }

    /**
     * The same file again keeps the person's choices - here a wrong
     * decimal separator, which the preview then shows as unreadable rows.
     */
    public function testTheSameFileKeepsWhatThePersonChose(): void
    {
        $erste = $this->vorschau('unbekannt.csv', []);
        preg_match('/name="datei_kennung" value="([0-9a-f]{16})"/', $erste->body, $kennung);

        $antwort = $this->vorschau('unbekannt.csv', [...$this->felder(''), 'dezimaltrenner' => ',', 'datei_kennung' => $kennung[1] ?? '']);

        self::assertStringNotContainsString('Erkannt:', $antwort->body);
        self::assertMatchesRegularExpression('/name="dezimaltrenner">.*?<option value="," selected>/s', $antwort->body);
        self::assertStringContainsString('0 Buchungen lesbar, 4 Zeilen nicht lesbar.', $antwort->body);
        self::assertStringContainsString('keine Zahl im Format Komma (1.234,56)', $antwort->body);
    }

    public function testThePreviewWaitsForTheRequiredColumns(): void
    {
        $erste = $this->vorschau('unbekannt.csv', []);
        preg_match('/name="datei_kennung" value="([0-9a-f]{16})"/', $erste->body, $kennung);
        $felder = $this->felder('');
        unset($felder['zuordnung']['soll']);

        $antwort = $this->vorschau('unbekannt.csv', [...$felder, 'datei_kennung' => $kennung[1] ?? '']);

        self::assertStringContainsString('Bitte auch die Spalte für Soll zuordnen.', $antwort->body);
        self::assertStringContainsString('Die Vorschau erscheint, sobald die Pflichtspalten zugeordnet sind.', $antwort->body);
        self::assertMatchesRegularExpression('/id="csv-feld-soll"[^>]*aria-invalid="true"/', $antwort->body);
    }

    public function testAFileAnExistingProfileReadsIsPointedOut(): void
    {
        $antwort = $this->vorschau('sparkasse-csv-camt-v8.csv', []);

        self::assertStringContainsString('Diese Datei liest bereits das Format', $antwort->body);
        self::assertStringContainsString('„Sparkasse CSV-CAMT“', $antwort->body);
        self::assertStringContainsString('Erkannt: Windows-1252 / ISO-8859-1', $antwort->body);
        self::assertStringContainsString('1 vorgemerkt (nicht gebucht, werden übersprungen)', $antwort->body);
        self::assertStringContainsString('Jörg Müßig', $antwort->body);
    }

    public function testSavingANewProfileStoresOnlyNamesAndFormats(): void
    {
        $antwort = $this->post('/app/konten/csv-formate', $this->felder('Testbank CSV'), 'unbekannt.csv');

        self::assertSame(302, $antwort->status);
        $neu = $this->eigene()[0] ?? self::fail('not saved');
        self::assertSame('/app/konten/csv-formate/' . $neu->id, $antwort->headers['Location'] ?? null);
        self::assertSame('Testbank CSV', $neu->name);
        self::assertFalse($neu->mitgeliefert);
        self::assertSame(CsvTrennzeichen::Komma, $neu->trennzeichen);
        self::assertSame(CsvZeichensatz::Automatisch, $neu->zeichensatz);
        self::assertSame(CsvDatumsformat::Iso, $neu->datumsformat);
        self::assertSame(CsvDezimaltrenner::Punkt, $neu->dezimaltrenner);
        self::assertSame([
            'buchungstag' => ['Datum'],
            'valuta' => ['Wertstellung'],
            'soll' => ['Soll'],
            'haben' => ['Haben'],
            'name' => ['Empfaenger/Auftraggeber'],
            'verwendungszweck' => ['Verwendungszweck 1', 'Verwendungszweck 2'],
        ], $neu->zuordnung);
        self::assertSame(
            CsvProfil::signatur(['Datum', 'Wertstellung', 'Empfaenger/Auftraggeber', 'Verwendungszweck 1', 'Verwendungszweck 2', 'Soll', 'Haben']),
            $neu->kopfSignatur,
        );

        $zeilen = $this->protokoll($neu->id ?? 0);
        self::assertSame([AuditAction::CsvFormatAngelegt->value], array_map(static fn(AuditEntry $e): string => $e->action, $zeilen));
        self::assertSame(['name' => 'Testbank CSV'], $this->audit->details($zeilen[0], $this->tresor));

        $roh = $this->rohTabelle('csv_profile') . $this->rohTabelle('audit_log');
        foreach (['Hallenbad', 'Bahnmiete', 'Kreissportbund', '1,020.00', '750.50', 'Kontoumsaetze'] as $inhalt) {
            self::assertStringNotContainsString($inhalt, $roh, $inhalt . ' from the sample file is stored nowhere');
        }
        self::assertSame(CsvTrennzeichen::Komma, $this->profile->find($neu->id ?? 0)?->trennzeichen);
        self::assertStringContainsString('Testbank CSV', $this->get('/app/konten/csv-formate')->body);
    }

    public function testSavingRefusesWhatCannotBeAProfile(): void
    {
        $ohneDatei = $this->post('/app/konten/csv-formate', $this->felder('Testbank'));
        self::assertSame(422, $ohneDatei->status);
        self::assertStringContainsString('Bitte eine Beispieldatei des Exports wählen', $ohneDatei->body);

        $ohneName = $this->post('/app/konten/csv-formate', $this->felder('  '), 'unbekannt.csv');
        self::assertSame(422, $ohneName->status);
        self::assertStringContainsString('Bitte einen Namen für das Format angeben.', $ohneName->body);
        self::assertMatchesRegularExpression('/id="csv-name"[^>]*aria-invalid="true"/', $ohneName->body);
        self::assertStringContainsString('Bitte die Datei zum Speichern erneut wählen.', $ohneName->body);

        $doppelt = $this->post('/app/konten/csv-formate', $this->felder(CsvStandardprofile::SPARKASSE), 'unbekannt.csv');
        self::assertSame(422, $doppelt->status);
        self::assertStringContainsString('Ein CSV-Format mit diesem Namen gibt es schon.', $doppelt->body);

        $felder = $this->felder('Testbank');
        $felder['zuordnung']['buchungstag'] = ['Gibt es nicht'];
        $passtNicht = $this->post('/app/konten/csv-formate', $felder, 'unbekannt.csv');
        self::assertSame(422, $passtNicht->status);
        self::assertStringContainsString('Keine Kopfzeile mit einer Spalte für „Buchungstag“ gefunden.', $passtNicht->body);

        $binaer = $this->post('/app/konten/csv-formate', $this->felder('Testbank'), null, "%PDF\0\0binary");
        self::assertSame(422, $binaer->status);
        self::assertStringContainsString('keine Textdatei', $binaer->body);

        self::assertSame([], $this->eigene());
    }

    public function testTooLargeAndForgedUploadsAreRefused(): void
    {
        $zuGross = $this->vorschau(null, [], str_repeat("a;b\n", (int) (CsvFormatController::MAX_BYTES / 4) + 1));
        self::assertStringContainsString('Die Datei ist größer als 2 MB', $zuGross->body);

        $gefaelscht = $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/konten/csv-formate/vorschau',
            post: ['_csrf' => new Session()->csrfToken()],
            files: ['datei' => ['name' => 'x.csv', 'type' => 'text/csv', 'tmp_name' => '/etc/hostname', 'error' => \UPLOAD_ERR_OK, 'size' => 10]],
        ));
        self::assertStringContainsString('Die Datei konnte nicht hochgeladen werden', $gefaelscht->body, 'a path PHP did not receive is not read');
    }

    public function testAClubProfileIsEditedAndDeleted(): void
    {
        $this->post('/app/konten/csv-formate', $this->felder('Testbank'), 'unbekannt.csv');
        $profil = $this->eigene()[0] ?? self::fail();
        $id = $profil->id ?? 0;

        $seite = $this->get('/app/konten/csv-formate/' . $id . '/bearbeiten');
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('name="id" value="' . $id . '"', $seite->body);
        self::assertMatchesRegularExpression('/id="csv-feld-haben"[^>]*>.*?<option value="Haben" selected>/s', $seite->body);
        self::assertStringContainsString('Für eine Vorschau oben eine Beispieldatei wählen.', $seite->body);

        // Without a new file: the mapping from the form, the signature kept.
        $felder = $this->felder('Testbank neu');
        $felder['zuordnung']['verwendungszweck'] = ['Verwendungszweck 1'];
        $antwort = $this->post('/app/konten/csv-formate/' . $id, $felder);
        self::assertSame(302, $antwort->status);
        $geaendert = $this->profile->find($id) ?? self::fail();
        self::assertSame('Testbank neu', $geaendert->name);
        self::assertSame(['Verwendungszweck 1'], $geaendert->zuordnung['verwendungszweck'] ?? null);
        self::assertSame($profil->kopfSignatur, $geaendert->kopfSignatur);

        // Editing never re-detects: a preview with the file keeps the form.
        $vorschau = $this->vorschau('unbekannt.csv', [...$felder, 'id' => (string) $id]);
        self::assertStringNotContainsString('Erkannt:', $vorschau->body);
        self::assertStringContainsString('Bahnmiete Training', $vorschau->body);
        self::assertStringNotContainsString('Diese Datei liest bereits das Format', $vorschau->body, 'not pointed at itself');

        $loeschen = $this->post('/app/konten/csv-formate/' . $id . '/loeschen', []);
        self::assertSame(302, $loeschen->status);
        self::assertSame('/app/konten/csv-formate', $loeschen->headers['Location'] ?? null);
        self::assertNull($this->profile->find($id));

        self::assertSame(
            [AuditAction::CsvFormatGeloescht->value, AuditAction::CsvFormatGeaendert->value, AuditAction::CsvFormatAngelegt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $this->protokoll($id)),
        );
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $ohne = $this->dispatch(new Request(HttpMethod::Post, '/app/konten/csv-formate', post: $this->felder('Testbank'), files: $this->datei('unbekannt.csv')));
        self::assertSame(302, $ohne->status);
        self::assertSame([], $this->eigene());

        $vorschau = $this->dispatch(new Request(HttpMethod::Post, '/app/konten/csv-formate/vorschau', files: $this->datei('unbekannt.csv')));
        self::assertSame(200, $vorschau->status);
        self::assertStringContainsString('Die Sitzung ist abgelaufen', $vorschau->body);
        self::assertStringNotContainsString('Bahnmiete', $vorschau->body);
    }

    public function testTheAccountsPageLinksTheFormatsForImporters(): void
    {
        self::assertStringContainsString('href="/app/konten/csv-formate"', $this->get('/app/konten')->body);

        $_SESSION = [];
        $this->userId = new UserRepository($this->pdo())->insert('enc2', random_bytes(32), 'enc2', 'hash', mfaRequired: false);
        $this->rolle(SystemRole::Vorstand);
        new Session()->start();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => time(), 'last_seen_at' => time(), 'session_epoch' => 0];

        $seite = $this->get('/app/konten');
        self::assertSame(200, $seite->status);
        self::assertStringNotContainsString('csv-formate', $seite->body, 'Vorstand reads accounts but does not import');
    }

    public function testAnUnknownProfileIsNotFound(): void
    {
        self::assertSame(404, $this->get('/app/konten/csv-formate/999')->status);
        self::assertSame(404, $this->get('/app/konten/csv-formate/999/bearbeiten')->status);
        self::assertSame(404, $this->post('/app/konten/csv-formate/999/loeschen', [])->status);
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
     * The form as the assistant sends it for tests/fixtures/bank/unbekannt.csv.
     *
     * @return array<string, mixed>
     */
    private function felder(string $name): array
    {
        return [
            'name' => $name,
            'trennzeichen' => 'komma',
            'zeichensatz' => 'auto',
            'datumsformat' => 'Y-m-d',
            'dezimaltrenner' => '.',
            'zuordnung' => [
                'buchungstag' => ['Datum'],
                'valuta' => ['Wertstellung'],
                'betrag' => [''],
                'soll' => ['Soll'],
                'haben' => ['Haben'],
                'name' => ['Empfaenger/Auftraggeber'],
                'verwendungszweck' => ['Verwendungszweck 1', 'Verwendungszweck 2'],
                'iban' => [''],
            ],
        ];
    }

    /**
     * @return list<CsvProfil>
     */
    private function eigene(): array
    {
        return array_values(array_filter($this->profile->all(), static fn(CsvProfil $p): bool => !$p->mitgeliefert));
    }

    /**
     * @return list<AuditEntry> newest first
     */
    private function protokoll(int $id): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'csv_profile', entityId: $id), null, 10);
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
     * A file as PHP hands it over in $_FILES, written to a temp file.
     *
     * @return array<string, array<string, mixed>>
     */
    private function datei(?string $fixture, ?string $inhalt = null): array
    {
        $pfad = tempnam(sys_get_temp_dir(), 'csvtest');
        assert(is_string($pfad));
        $this->uploads[] = $pfad;
        $bytes = $inhalt ?? (string) file_get_contents(self::FIXTURES . $fixture);
        file_put_contents($pfad, $bytes);

        return ['datei' => ['name' => 'export.csv', 'type' => 'text/csv', 'tmp_name' => $pfad, 'error' => \UPLOAD_ERR_OK, 'size' => strlen($bytes)]];
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function vorschau(?string $fixture, array $felder, ?string $inhalt = null): Response
    {
        return $this->post('/app/konten/csv-formate/vorschau', $felder, $fixture, $inhalt);
    }

    private function get(string $pfad): Response
    {
        return $this->dispatch(new Request(HttpMethod::Get, $pfad));
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function post(string $pfad, array $felder, ?string $fixture = null, ?string $inhalt = null): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            $pfad,
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
            files: $fixture !== null || $inhalt !== null ? $this->datei($fixture, $inhalt) : [],
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
            new BankAccountService($pdo, new BankAccountRepository($pdo)),
            new Kassensturz($pdo, new CashCountRepository($pdo)),
            $this->audit,
        );
        $csvFormate = fn(): CsvFormatController => new CsvFormatController(
            $view,
            new Session(),
            $this->profile,
            $this->audit,
            fn(string $pfad): bool => in_array($pfad, $this->uploads, true),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the account pages 28th, the CSV formats 30th, the
        // export pattern page 31st, the AI provider pages 32nd.
        $controller = array_fill(0, 32, $unerreichbar);
        $controller[1] = $guard;
        $controller[27] = $konten;
        $controller[29] = $csvFormate;

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
