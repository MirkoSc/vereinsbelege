<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\SucheController;
use App\Config\Paths;
use App\Domain\ArtifactKind;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\JobExecutor;
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
use App\Repository\BlobRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\Texterkennung;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Suche\ERechnungQuelle;
use App\Service\Suche\KiQuelle;
use App\Service\Suche\SitzungsSuchindex;
use App\Service\Suche\SuchindexSpeicher;
use App\Service\Suche\TextlayerQuelle;
use App\Service\Suche\Volltextsuche;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\KiAuslesungFabrik;
use App\View\View;

/**
 * The full-text search page end to end (issue #94, docs/spec/
 * 03-erfassung-und-ki.md section 10): the real route table, guard,
 * controller, service, repositories and schema.
 */
final class SucheFlowTest extends DatabaseTestCase
{
    private ServerCrypto $crypto;
    private RoleRepository $rollen;
    private Vault $tresor;
    private int $userId;
    private string $verzeichnis;
    private string $blobDir;
    private int $jobId = 500;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->verzeichnis = sys_get_temp_dir() . '/vb_suche_flow_' . bin2hex(random_bytes(6));
        $this->blobDir = sys_get_temp_dir() . '/vb_suche_flow_blobs_' . bin2hex(random_bytes(6));
        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());

        $this->rollen = new RoleRepository($this->pdo());
        $this->userId = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt('vorstand@example.org'),
            random_bytes(32),
            $this->crypto->encrypt('Vera Vorstand'),
            'hash',
            mfaRequired: false,
        );
        $this->alsRolle(SystemRole::Vorstand);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach ([$this->verzeichnis, $this->blobDir] as $verzeichnis) {
            if (!is_dir($verzeichnis)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($verzeichnis, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $eintrag) {
                $eintrag->isDir() ? rmdir($eintrag->getPathname()) : unlink($eintrag->getPathname());
            }
            rmdir($verzeichnis);
        }
        parent::tearDown();
    }

    public function testASmallArchiveIsIndexedOnTheWayAndSearchedByPost(): void
    {
        $id = $this->dokument();
        $this->textlayer($id, ['Sporthaus Müller – Trikots E-Jugend']);
        $anderer = $this->dokument();
        $this->textlayer($anderer, ['Getränke Becker']);

        $seite = $this->get('/app/suche');
        self::assertSame(200, $seite->status);
        self::assertSame('no-store, private', $seite->headers['Cache-Control'] ?? null);
        self::assertStringContainsString('action="/app/suche"', $seite->body);
        self::assertStringContainsString('method="post"', $seite->body);
        self::assertStringNotContainsString('id="suche-index"', $seite->body, 'two documents: built right away, no chain');
        self::assertStringNotContainsString('/js/suche.js', $seite->body);

        $antwort = $this->post('/app/suche', ['q' => 'TRIKOTS müller']);
        self::assertSame(200, $antwort->status);
        self::assertSame('no-store, private', $antwort->headers['Cache-Control'] ?? null);
        self::assertStringContainsString('href="/app/posteingang/' . $id . '"', $antwort->body);
        self::assertStringNotContainsString('href="/app/posteingang/' . $anderer . '"', $antwort->body);
        self::assertStringContainsString('<mark>trikots</mark>', $antwort->body);
        self::assertStringContainsString('<mark>müller</mark>', $antwort->body);
        self::assertStringContainsString('value="TRIKOTS müller"', $antwort->body, 'the form keeps the terms');

        self::assertStringContainsString('Keine Belege gefunden', $this->post('/app/suche', ['q' => 'fußball'])->body);
        self::assertStringContainsString('mindestens einen Suchbegriff', $this->post('/app/suche', ['q' => 'x'])->body);
    }

    public function testTheExcerptIsEscaped(): void
    {
        $id = $this->dokument();
        $this->textlayer($id, ['Rechnung <script>alert(1)</script> Trikots']);

        $antwort = $this->post('/app/suche', ['q' => 'trikots']);

        self::assertStringContainsString('href="/app/posteingang/' . $id . '"', $antwort->body);
        self::assertStringNotContainsString('<script>alert', $antwort->body);
        self::assertStringContainsString('&lt;script&gt;', $antwort->body);
    }

    public function testALargeArchiveRunsTheStepChain(): void
    {
        for ($i = 0; $i < Volltextsuche::SCHRITT_DOKUMENTE + 3; $i++) {
            $this->textlayer($this->dokument(), ['Vereinsheim Heizöl ' . $i]);
        }

        $seite = $this->post('/app/suche', ['q' => 'heizöl']);
        self::assertStringContainsString('id="suche-index"', $seite->body);
        self::assertStringContainsString('data-offen="' . (Volltextsuche::SCHRITT_DOKUMENTE + 3) . '"', $seite->body);
        self::assertStringContainsString('data-erneut="1"', $seite->body);
        self::assertStringContainsString('/js/suche.js', $seite->body);
        self::assertStringNotContainsString('<mark>', $seite->body, 'no hits from a half index');

        $schritt = $this->post('/app/suche/index', [], json: true);
        self::assertSame(200, $schritt->status);
        self::assertSame(['fertig' => false, 'offen' => 3], json_decode($schritt->body, true, flags: JSON_THROW_ON_ERROR));
        $schritt = $this->post('/app/suche/index', [], json: true);
        self::assertSame(['fertig' => true, 'offen' => 0], json_decode($schritt->body, true, flags: JSON_THROW_ON_ERROR));

        self::assertStringContainsString('<mark>heizöl</mark>', $this->post('/app/suche', ['q' => 'heizöl'])->body);
    }

    public function testWithoutTheVaultThereIsNothingToSearch(): void
    {
        $this->textlayer($this->dokument(), ['Trikots']);

        $seite = $this->get('/app/suche', entsperrt: false);
        self::assertStringContainsString('nur mit entsperrtem Tresor durchsuchbar', $seite->body);
        self::assertStringNotContainsString('name="q"', $seite->body);

        $schritt = $this->post('/app/suche/index', [], entsperrt: false, json: true);
        self::assertSame(403, $schritt->status);
        self::assertArrayNotHasKey('suchindex', $_SESSION);
    }

    public function testEveryWriteNeedsTheCsrfToken(): void
    {
        $this->textlayer($this->dokument(), ['Trikots']);

        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/app/suche', post: ['q' => 'trikots'], cookies: $this->tresorCookie()));
        self::assertSame(302, $antwort->status);
        self::assertSame('/app/suche', $antwort->headers['Location'] ?? null);

        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/app/suche/index', cookies: $this->tresorCookie()));
        self::assertSame(403, $antwort->status);
        self::assertArrayNotHasKey('suchindex', $_SESSION);
    }

    public function testRebuildingThrowsTheIndexAway(): void
    {
        $this->textlayer($this->dokument(), ['Trikots']);
        $this->get('/app/suche');
        $id = (string) new SitzungsSuchindex()->lies()['id'];
        self::assertDirectoryExists($this->verzeichnis . '/' . $id);

        $antwort = $this->post('/app/suche/neu');

        self::assertSame(302, $antwort->status);
        self::assertArrayNotHasKey('suchindex', $_SESSION);
        self::assertDirectoryDoesNotExist($this->verzeichnis . '/' . $id);
    }

    public function testTheCostCenterScopeHoldsForSearchToo(): void
    {
        $kostenstellen = new CostCenterRepository($this->pdo());
        $jugend = $kostenstellen->create('Jugend');
        $herren = $kostenstellen->create('Herren');
        $eigene = $this->dokument($jugend);
        $this->textlayer($eigene, ['Trikots Jugend']);
        $fremde = $this->dokument($herren);
        $this->textlayer($fremde, ['Trikots Herren']);

        $this->alsRolle(SystemRole::Vereinsverantwortlicher);
        new UserAccessRepository($this->pdo())->setCostCenters($this->userId, [$jugend]);

        $antwort = $this->post('/app/suche', ['q' => 'trikots']);

        self::assertStringContainsString('href="/app/posteingang/' . $eigene . '"', $antwort->body);
        self::assertStringNotContainsString('href="/app/posteingang/' . $fremde . '"', $antwort->body);
        self::assertStringNotContainsString('herren', $antwort->body);
    }

    // ------------------------------------------------------------ helpers

    private function alsRolle(SystemRole $rolle): void
    {
        $id = $this->rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $this->rollen->assignToUser($this->userId, [$id]);
    }

    private function dokument(?int $kostenstelle = null): int
    {
        return new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
            DocumentStatus::InPruefung,
            costCenterId: $kostenstelle,
        );
    }

    /**
     * @param list<string> $seiten
     */
    private function textlayer(int $documentId, array $seiten): void
    {
        $repository = new DocumentArtifactRepository($this->pdo());
        $schluessel = DataKey::generate();
        $id = $repository->insert(
            $documentId,
            ArtifactKind::Text,
            0,
            null,
            $this->tresor->sealDataKey($schluessel),
            null,
            JobExecutor::Session,
            ++$this->jobId,
            new \DateTimeImmutable(),
        );
        $repository->setzeDaten($id, FieldCipher::encrypt(
            $schluessel,
            json_encode(['befund' => 'gelesen', 'brauchbar' => true, 'seiten' => $seiten], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            new FieldContext('document_artifact', $id, 'data_enc'),
        ));
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
        return $this->dispatch(new Request(HttpMethod::Get, $pfad, cookies: $entsperrt ? $this->tresorCookie() : []));
    }

    /**
     * @param array<string, string> $felder
     */
    private function post(string $pfad, array $felder = [], bool $entsperrt = true, bool $json = false): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            $pfad,
            post: $json ? $felder : [...$felder, '_csrf' => new Session()->csrfToken()],
            headers: $json ? ['x-csrf-token' => new Session()->csrfToken()] : [],
            cookies: $entsperrt ? $this->tresorCookie() : [],
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

        $guard = fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null
                    ? null
                    : new SessionUser($user, $this->crypto->decrypt($user->displayNameEnc), new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $suche = function () use ($view, $pdo): SucheController {
            $blobs = new BlobRepository($pdo);
            $blobService = new BlobService($blobs, new DbBlobBackend($blobs), new FsBlobBackend($this->blobDir));
            $texterkennung = new Texterkennung(new DocumentRepository($pdo), new DocumentArtifactRepository($pdo), $blobService);

            return new SucheController(
                $view,
                new Session(),
                new SessionVault(),
                new Volltextsuche(
                    new DocumentRepository($pdo),
                    new DocumentArtifactRepository($pdo),
                    new SuchindexSpeicher($this->verzeichnis),
                    new SitzungsSuchindex(),
                    [
                        new TextlayerQuelle($texterkennung),
                        new ERechnungQuelle($texterkennung),
                        new KiQuelle(KiAuslesungFabrik::bauen($pdo, $blobService, $this->crypto)),
                    ],
                ),
                new CostCenterRepository($pdo),
            );
        };
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the full-text search last (38th).
        $controller = array_fill(0, 38, $unerreichbar);
        $controller[1] = $guard;
        $controller[37] = $suche;

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
