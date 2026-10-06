<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\KiAnbieterController;
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
use App\Repository\AiProviderRepository;
use App\Repository\AuditLogRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Ki\KiAnbieter;
use App\Service\Ki\KiAnbieterService;
use App\Service\Ki\KiVorlage;
use App\Service\Ki\Verbindungstest;
use App\Service\Migration\Migrator;
use App\Support\FileLogger;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeChatHttp;
use App\View\Flash;
use App\View\FlashArt;
use App\View\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The AI provider pages end to end (M7-1, issue #41, docs/spec/
 * 03-erfassung-und-ki.md section 6 "Client"): the seed of
 * migrations/024_ai_provider.sql, the real route table, guard, controller
 * and schema - with a recorded provider instead of a real one.
 */
final class KiAnbieterFlowTest extends DatabaseTestCase
{
    private const string KEY = 'sk-test-1111-nicht-echt-QrStUvWx';

    private AiProviderRepository $anbieter;

    private ServerCrypto $crypto;

    private FakeChatHttp $http;

    private ?FileLogger $logger = null;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->crypto = new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES));
        $this->anbieter = new AiProviderRepository($this->pdo(), $this->crypto);
        $this->http = new FakeChatHttp([]);

        $rollen = new RoleRepository($this->pdo());
        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $adminRolle = $rollen->findSystem(SystemRole::Admin)?->id;
        assert($adminRolle !== null);
        $rollen->assignToUser($this->userId, [$adminRolle]);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Acceptance criterion "Mitgelieferte Vorlage OpenAI als Standard": the
     * migration seeds exactly App\Service\Ki\KiVorlage::OpenAi, as default,
     * without a key - so AI stays off until the admin enters one.
     */
    public function testTheMigrationSeedsTheOpenAiTemplateAsDefaultWithoutKey(): void
    {
        $profile = $this->anbieter->all();
        self::assertCount(1, $profile);
        $openai = $profile[0];
        $vorlage = KiVorlage::OpenAi;

        self::assertSame($vorlage->label(), $openai->name);
        self::assertSame($vorlage->baseUrl(), $openai->baseUrl);
        self::assertSame($vorlage->modell(), $openai->model);
        self::assertEquals($vorlage->faehigkeiten(), $openai->faehigkeiten);
        self::assertSame($vorlage->timeoutS(), $openai->timeoutS);
        self::assertTrue($openai->active);
        self::assertTrue($openai->isDefault);
        self::assertFalse($openai->keyGesetzt);
        self::assertFalse($openai->nutzbar(), 'no key = AI switched off');
        self::assertSame($openai->id, $this->anbieter->standard()?->id);
        self::assertNull($this->anbieter->apiKey($openai->id));
    }

    public function testTheListSaysThatAiIsOffWithoutAKey(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Get, '/admin/ki-anbieter'));

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('<h2>KI-Anbieter</h2>', $antwort->body);
        self::assertStringContainsString('KI-Funktionen sind ausgeschaltet', $antwort->body);
        self::assertStringContainsString('Kein API-Key', $antwort->body);
        self::assertStringContainsString('marke marke-ok">Standard', $antwort->body);
        self::assertStringContainsString('Auftragsverarbeitungsvertrag', $antwort->body);
        self::assertStringContainsString('<option value="openai">OpenAI</option>', $antwort->body);
        self::assertStringContainsString('href="/admin/ki-anbieter" aria-current="page"', $antwort->body, 'the navigation entry is live');
    }

    public function testANewProfileStartsFromTheTemplate(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Get, '/admin/ki-anbieter/neu', query: ['vorlage' => 'openai']));

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('value="https://api.openai.com/v1"', $antwort->body);
        self::assertStringContainsString('value="gpt-6-luna"', $antwort->body);
        self::assertStringContainsString('name="vision" value="1" checked', $antwort->body);
        self::assertStringContainsString('name="api_key" value=""', $antwort->body);
    }

    /**
     * Acceptance criterion "API-Key mit dem Server-Schlüssel verschlüsselt":
     * the column holds ServerCrypto ciphertext, the plaintext appears in no
     * column and on no page, and empty means "unchanged".
     */
    public function testTheKeyIsStoredEncryptedAndNeverShownAgain(): void
    {
        $antwort = $this->post('/admin/ki-anbieter', self::formular(['name' => 'Zweitprofil', 'api_key' => '  ' . self::KEY . ' ']));
        self::assertSame(302, $antwort->status, (string) ($antwort->body));
        self::assertSame('/admin/ki-anbieter', $antwort->headers['Location'] ?? null);

        $profil = $this->profil('Zweitprofil');
        self::assertTrue($profil->keyGesetzt);
        self::assertFalse($profil->isDefault, 'a new profile is not the default by itself');

        $stmt = $this->pdo()->prepare('SELECT * FROM ai_provider WHERE id = ?');
        $stmt->execute([$profil->id]);
        $zeile = $stmt->fetch();
        self::assertIsArray($zeile);
        foreach ($zeile as $spalte => $wert) {
            self::assertStringNotContainsString(self::KEY, (string) $wert, 'no plaintext key in ' . $spalte);
        }
        self::assertSame(self::KEY, $this->crypto->decrypt((string) $zeile['api_key_enc']), 'trimmed, then server-key ciphertext');
        self::assertSame(self::KEY, $this->anbieter->apiKey($profil->id));

        foreach (['/admin/ki-anbieter', '/admin/ki-anbieter/' . $profil->id] as $seite) {
            $html = $this->dispatch(new Request(HttpMethod::Get, $seite))->body;
            self::assertStringNotContainsString(self::KEY, $html, $seite);
            self::assertStringNotContainsString('QrStUvWx', $html, $seite);
        }
        self::assertStringContainsString('Ein Key ist hinterlegt und wird nicht angezeigt', $this->dispatch(new Request(HttpMethod::Get, '/admin/ki-anbieter/' . $profil->id))->body);

        // Empty field: unchanged, also across other changes.
        $this->post('/admin/ki-anbieter/' . $profil->id, self::formular(['name' => 'Zweitprofil', 'model' => 'gpt-6-luna-mini', 'api_key' => '']));
        self::assertSame('gpt-6-luna-mini', $this->profil('Zweitprofil')->model);
        self::assertSame(self::KEY, $this->anbieter->apiKey($profil->id));

        // A new one replaces it.
        $this->post('/admin/ki-anbieter/' . $profil->id, self::formular(['name' => 'Zweitprofil', 'api_key' => 'sk-test-neu-2222']));
        self::assertSame('sk-test-neu-2222', $this->anbieter->apiKey($profil->id));

        // The checkbox removes it.
        $this->post('/admin/ki-anbieter/' . $profil->id, self::formular(['name' => 'Zweitprofil', 'api_key_entfernen' => '1']));
        self::assertNull($this->anbieter->apiKey($profil->id));
        self::assertFalse($this->profil('Zweitprofil')->keyGesetzt);

        // Every change is audited with the profile as its object (M3-8).
        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'ai_provider', entityId: $profil->id), null, 10);
        self::assertSame(
            [AuditAction::KiAnbieterGeaendert->value, AuditAction::KiAnbieterGeaendert->value, AuditAction::KiAnbieterGeaendert->value, AuditAction::KiAnbieterAngelegt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $zeilen),
        );
    }

    public function testAKeyFromAnotherServerKeyCountsAsMissing(): void
    {
        $id = $this->openai()->id;
        $this->pdo()->prepare('UPDATE ai_provider SET api_key_enc = ? WHERE id = ?')
            ->execute([new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES))->encrypt(self::KEY), $id]);

        self::assertTrue($this->openai()->keyGesetzt);
        self::assertNull($this->anbieter->apiKey($id), 'no crash');

        $this->post('/admin/ki-anbieter/' . $id . '/testen', []);
        $flash = $this->flash();
        self::assertSame(FlashArt::Fehler, $flash->art);
        self::assertStringContainsString('lässt sich nicht entschlüsseln', $flash->text);
        self::assertSame([], $this->http->anfragen, 'nothing sent');
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function ungueltig(): iterable
    {
        yield 'plain http' => [['base_url' => 'http://ki.example.org/v1'], 'muss mit https:// beginnen'];
        yield 'full endpoint' => [['base_url' => 'https://api.openai.com/v1/chat/completions'], '„/chat/completions“'];
        yield 'no name' => [['name' => '  '], 'Bitte einen Namen angeben.'];
        yield 'taken name' => [['name' => 'OpenAI'], 'Ein Profil „OpenAI“ gibt es schon.'];
        yield 'no model' => [['model' => ''], 'Bitte ein Modell angeben.'];
        yield 'vision without pictures' => [['vision' => '1', 'max_images' => '0'], 'mindestens ein Bild je Aufruf'];
        yield 'too many pictures' => [['max_images' => '21'], '„Bilder je Aufruf“ muss eine ganze Zahl von 0 bis 20 sein.'];
        yield 'tokens not a number' => [['max_tokens' => 'viel'], '„Max. Tokens“ muss eine ganze Zahl'];
        yield 'timeout too short' => [['timeout_s' => '1'], '„Zeitlimit“ muss eine ganze Zahl von 5 bis 300 sein.'];
        yield 'key with a line break' => [['api_key' => "sk-abc\r\nX-Evil: 1"], 'nur sichtbare ASCII-Zeichen'];
        yield 'key with a space' => [['api_key' => 'sk abc'], 'nur sichtbare ASCII-Zeichen'];
    }

    /**
     * @param array<string, string> $aenderung
     */
    #[DataProvider('ungueltig')]
    public function testInvalidInputIsRejectedWithAMessageAndNothingStored(array $aenderung, string $meldung): void
    {
        $antwort = $this->post('/admin/ki-anbieter', self::formular(['name' => 'Neu', ...$aenderung]));

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString(htmlspecialchars($meldung, ENT_QUOTES), $antwort->body);
        self::assertCount(1, $this->anbieter->all(), 'nothing stored');
        self::assertStringNotContainsString('X-Evil', $antwort->body, 'the key is not echoed back');
    }

    public function testExactlyOneDefaultAndItCannotBeDeleted(): void
    {
        $openai = $this->openai();
        $this->post('/admin/ki-anbieter', self::formular(['name' => 'Eigener Server', 'base_url' => 'https://ki.verein.example/v1']));
        $eigener = $this->profil('Eigener Server');

        // The default stays.
        $this->post('/admin/ki-anbieter/' . $openai->id . '/loeschen', []);
        self::assertSame(FlashArt::Fehler, $this->flash()->art);
        self::assertNotNull($this->anbieter->find($openai->id));

        // Switching makes the other one the only default.
        $this->post('/admin/ki-anbieter/' . $eigener->id . '/standard', []);
        self::assertStringContainsString('„Eigener Server“ ist jetzt das Standardprofil.', $this->flash()->text);
        $standard = array_values(array_filter($this->anbieter->all(), static fn(KiAnbieter $p): bool => $p->isDefault));
        self::assertCount(1, $standard);
        self::assertSame($eigener->id, $standard[0]->id);
        self::assertSame($eigener->id, $this->anbieter->standard()?->id);

        // Now the former default can go.
        $this->post('/admin/ki-anbieter/' . $openai->id . '/loeschen', []);
        self::assertNull($this->anbieter->find($openai->id));

        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'ai_provider'), null, 10);
        self::assertSame(
            [AuditAction::KiAnbieterGeloescht->value, AuditAction::KiAnbieterStandard->value, AuditAction::KiAnbieterAngelegt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $zeilen),
        );
    }

    public function testAnInactiveProfileCannotBecomeTheDefault(): void
    {
        $this->post('/admin/ki-anbieter', self::formular(['name' => 'Ruhend', 'active' => '']));
        $ruhend = $this->profil('Ruhend');
        self::assertFalse($ruhend->active);

        $this->post('/admin/ki-anbieter/' . $ruhend->id . '/standard', []);

        self::assertSame('Ein deaktiviertes Profil kann nicht Standard werden.', $this->flash()->text);
        self::assertTrue($this->openai()->isDefault);
    }

    /**
     * Acceptance criterion "Verbindungstest im Admin": the decrypted key
     * goes to the provider, the outcome comes back as flash message.
     */
    public function testTheConnectionTestTalksToTheProviderWithTheStoredKey(): void
    {
        $id = $this->openai()->id;
        $this->post('/admin/ki-anbieter/' . $id, self::formular(['name' => 'OpenAI', 'api_key' => self::KEY]));
        $this->http = new FakeChatHttp([FakeChatHttp::fixture('verbindungstest/ok-bild-schema.json')]);

        $antwort = $this->post('/admin/ki-anbieter/' . $id . '/testen', []);

        self::assertSame(302, $antwort->status);
        self::assertSame('/admin/ki-anbieter', $antwort->headers['Location'] ?? null);
        $flash = $this->flash();
        self::assertSame(FlashArt::Ok, $flash->art);
        self::assertStringStartsWith('„OpenAI“: Verbindung erfolgreich.', $flash->text);
        self::assertStringContainsString('Testbild erkannt', $flash->text);

        self::assertCount(1, $this->http->anfragen);
        self::assertSame('https://api.openai.com/v1/chat/completions', $this->http->anfragen[0]['url']);
        self::assertSame(self::KEY, $this->http->anfragen[0]['apiKey']);
        self::assertSame('gpt-6-luna', $this->http->anfragen[0]['body']['model']);

        // The list now has a usable default.
        $liste = $this->dispatch(new Request(HttpMethod::Get, '/admin/ki-anbieter'))->body;
        self::assertStringNotContainsString('KI-Funktionen sind ausgeschaltet', $liste);
        self::assertStringContainsString('API-Key hinterlegt', $liste);
    }

    public function testAFailedConnectionTestIsAnErrorWithoutTheKey(): void
    {
        $id = $this->openai()->id;
        $this->post('/admin/ki-anbieter/' . $id, self::formular(['name' => 'OpenAI', 'api_key' => self::KEY]));
        $this->http = new FakeChatHttp([FakeChatHttp::fixture('verbindungstest/401-key-falsch.json', 401)]);

        $this->post('/admin/ki-anbieter/' . $id . '/testen', []);

        $flash = $this->flash();
        self::assertSame(FlashArt::Fehler, $flash->art);
        self::assertStringContainsString('Zugriff verweigert (HTTP 401)', $flash->text);
        self::assertStringNotContainsString(self::KEY, $flash->text);
    }

    public function testWithoutKeyTheTestSendsNothing(): void
    {
        $this->post('/admin/ki-anbieter/' . $this->openai()->id . '/testen', []);

        self::assertStringContainsString('kein API-Key hinterlegt', $this->flash()->text);
        self::assertSame([], $this->http->anfragen);
    }

    /**
     * "Nie im Klartext geloggt": even an unexpected error in the middle of
     * a call - the key on the stack as an argument, arguments in traces
     * switched ON - leaves no key in the error log or the app log.
     */
    public function testAnErrorDuringTheCallNeverLogsTheKey(): void
    {
        $id = $this->openai()->id;
        $this->post('/admin/ki-anbieter/' . $id, self::formular(['name' => 'OpenAI', 'api_key' => self::KEY]));
        $this->http = new FakeChatHttp([new \RuntimeException('Provider explodiert')]);

        $appLog = tempnam(sys_get_temp_dir(), 'ki_applog_');
        $errorLog = tempnam(sys_get_temp_dir(), 'ki_errlog_');
        self::assertIsString($appLog);
        self::assertIsString($errorLog);
        $vorherIgnore = ini_set('zend.exception_ignore_args', '0');
        $vorherLog = ini_set('error_log', $errorLog);
        $this->logger = new FileLogger($appLog);

        try {
            $antwort = $this->post('/admin/ki-anbieter/' . $id . '/testen', []);

            self::assertSame(500, $antwort->status);
            self::assertStringNotContainsString(self::KEY, $antwort->body);
            $geloggt = (string) file_get_contents($appLog) . (string) file_get_contents($errorLog);
            self::assertStringContainsString('Provider explodiert', $geloggt, 'the error was logged');
            self::assertStringNotContainsString(self::KEY, $geloggt);
            self::assertStringNotContainsString('QrStUvWx', $geloggt);
        } finally {
            ini_set('error_log', $vorherLog === false ? '' : $vorherLog);
            ini_set('zend.exception_ignore_args', $vorherIgnore === false ? '1' : $vorherIgnore);
            $this->logger = null;
            @unlink($appLog);
            @unlink($errorLog);
        }
    }

    public function testWritesWithoutCsrfTokenChangeNothing(): void
    {
        $openai = $this->openai();

        $antworten = [
            $this->dispatch(new Request(HttpMethod::Post, '/admin/ki-anbieter', post: self::formular(['name' => 'Ohne Token']))),
            $this->dispatch(new Request(HttpMethod::Post, '/admin/ki-anbieter/' . $openai->id, post: self::formular(['name' => 'Umbenannt', 'api_key' => self::KEY]))),
            $this->dispatch(new Request(HttpMethod::Post, '/admin/ki-anbieter/' . $openai->id . '/testen')),
        ];

        foreach ($antworten as $antwort) {
            self::assertSame(302, $antwort->status);
        }
        self::assertCount(1, $this->anbieter->all());
        self::assertSame('OpenAI', $this->openai()->name);
        self::assertNull($this->anbieter->apiKey($openai->id));
        self::assertSame([], $this->http->anfragen);
    }

    public function testAnUnknownProfileIsNotFound(): void
    {
        self::assertSame(404, $this->dispatch(new Request(HttpMethod::Get, '/admin/ki-anbieter/999'))->status);
        self::assertSame(404, $this->post('/admin/ki-anbieter/999/testen', [])->status);
    }

    // ----------------------------------------------------------- scaffolding

    /**
     * A valid form, the OpenAI template's values unless overridden.
     *
     * @param array<string, string> $aenderung
     *
     * @return array<string, string>
     */
    private static function formular(array $aenderung = []): array
    {
        return [
            'name' => 'Profil',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-6-luna',
            'vision' => '1',
            'json_schema' => '1',
            'max_images' => '4',
            'max_tokens' => '4000',
            'timeout_s' => '60',
            'active' => '1',
            ...$aenderung,
        ];
    }

    private function openai(): KiAnbieter
    {
        return $this->profil('OpenAI');
    }

    private function profil(string $name): KiAnbieter
    {
        return $this->anbieter->findByName($name) ?? self::fail('Profile not found: ' . $name);
    }

    private function flash(): Flash
    {
        return new Session()->pullFlash() ?? self::fail('No flash message.');
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function post(string $pfad, array $felder): Response
    {
        return $this->dispatch(new Request(HttpMethod::Post, $pfad, post: [...$felder, '_csrf' => new Session()->csrfToken()]));
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
        $kiAnbieter = fn(): KiAnbieterController => new KiAnbieterController(
            $view,
            new Session(),
            $this->anbieter,
            new KiAnbieterService($this->anbieter),
            new Verbindungstest($this->http),
            new AuditLog(new AuditLogRepository($pdo), new VaultRepository($pdo), $this->crypto),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the AI provider pages 32nd.
        $controller = array_fill(0, 33, $unerreichbar);
        $controller[1] = $guard;
        $controller[31] = $kiAnbieter;

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')($router, $view, ...$controller);

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: false,
            logger: $this->logger,
        );
    }
}
