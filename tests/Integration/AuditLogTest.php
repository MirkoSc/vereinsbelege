<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Repository\AuditLogRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditChain;
use App\Service\Audit\AuditChainBreak;
use App\Service\Audit\AuditConflict;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * The audit log against the real schema (migrations/011_audit_log.sql,
 * docs/spec/01-sicherheit.md section 6, issue #21/M3-8): the chain as
 * written, what a manipulation in SQL looks like to the check, the
 * encrypted details, and that nothing readable lands in the table.
 */
final class AuditLogTest extends DatabaseTestCase
{
    private const string IP = '198.51.100.7';

    private Vault $tresor;
    private AuditLogRepository $repository;
    private AuditLog $audit;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());

        $this->repository = new AuditLogRepository($this->pdo());
        $this->audit = new AuditLog(
            $this->repository,
            new VaultRepository($this->pdo()),
            new ServerCrypto((string) base64_decode(self::configData()['server_key'], true)),
        );
    }

    public function testRowsAreChainedFromGenesis(): void
    {
        $erste = $this->audit->record(AuditAction::LoginErfolg, 1, self::IP, 1);
        $zweite = $this->audit->record(AuditAction::Logout, 1, self::IP, 1);

        self::assertSame(1, $erste->id);
        self::assertSame(AuditChain::GENESIS, $erste->prevHash);
        self::assertSame(2, $zweite->id);
        self::assertSame($erste->hash, $zweite->prevHash);
        self::assertEquals($zweite, $this->repository->head(), 'what was written is what is stored');
        self::assertSame('user', $zweite->entity, 'the entity comes from the action');
    }

    public function testTheCheckPassesAnUntouchedLog(): void
    {
        $this->schreibe(4);

        $ergebnis = $this->audit->pruefeAbschnitt(0);

        self::assertTrue($ergebnis->intakt());
        self::assertSame(4, $ergebnis->geprueft);
        self::assertSame($this->repository->head()?->hash, $ergebnis->letzterHash);
    }

    public function testAnUpdateInSqlIsFound(): void
    {
        $this->schreibe(4);
        $this->pdo()->exec("UPDATE audit_log SET action = 'logout' WHERE id = 3");

        $ergebnis = $this->audit->pruefeAbschnitt(0);

        self::assertSame(3, $ergebnis->bruchId);
        self::assertSame(AuditChainBreak::Inhalt, $ergebnis->bruch);
    }

    public function testADeleteInSqlIsFound(): void
    {
        $this->schreibe(4);
        $this->pdo()->exec('DELETE FROM audit_log WHERE id = 2');

        $ergebnis = $this->audit->pruefeAbschnitt(0);

        self::assertSame(3, $ergebnis->bruchId);
        self::assertSame(AuditChainBreak::Luecke, $ergebnis->bruch);
    }

    public function testAChangedTimestampIsFound(): void
    {
        $this->schreibe(3);
        $this->pdo()->exec("UPDATE audit_log SET ts = '2020-01-01 00:00:00' WHERE id = 1");

        self::assertSame(1, $this->audit->pruefeAbschnitt(0)->bruchId);
    }

    /**
     * The check runs in steps; each step starts from the row the previous
     * one ended on, read back from the table.
     */
    public function testTheCheckContinuesAfterAnEarlierStep(): void
    {
        $this->schreibe(5);

        $ergebnis = $this->audit->pruefeAbschnitt(3);

        self::assertTrue($ergebnis->intakt());
        self::assertSame(2, $ergebnis->geprueft);
        self::assertSame(5, $ergebnis->letzteId);
    }

    public function testAStepWhoseStartingRowVanishedReportsTheGap(): void
    {
        $this->schreibe(5);
        $this->pdo()->exec('DELETE FROM audit_log WHERE id = 3');

        $ergebnis = $this->audit->pruefeAbschnitt(3);

        self::assertSame(3, $ergebnis->bruchId);
        self::assertSame(AuditChainBreak::Luecke, $ergebnis->bruch);
    }

    /**
     * A writer that lost the race for an id re-reads the head and chains to
     * the row that won. Simulated with a second connection that lets
     * another request's row in right before this writer's first insert.
     */
    public function testALostRaceIsRetriedOnTheNewHead(): void
    {
        $this->schreibe(1);
        $db = self::configData()['db'];
        $konkurrent = $this->audit;
        $verbindung = new class (
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
            $db['user'],
            $db['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC],
        ) extends \PDO {
            public ?AuditLog $konkurrent = null;

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if ($this->konkurrent !== null && str_starts_with($query, 'INSERT INTO audit_log')) {
                    $wer = $this->konkurrent;
                    $this->konkurrent = null;
                    $wer->record(AuditAction::Logout, 2, '');
                }

                return parent::prepare($query, $options);
            }
        };
        $verbindung->konkurrent = $konkurrent;
        $audit = new AuditLog(new AuditLogRepository($verbindung), new VaultRepository($verbindung), new ServerCrypto(random_bytes(32)));

        $eintrag = $audit->record(AuditAction::RolleGeaendert, 1, self::IP, 5, ['name' => 'Kasse']);

        self::assertSame(3, $eintrag->id);
        self::assertSame($this->repository->find(2)?->hash, $eintrag->prevHash);
        self::assertSame('logout', $this->repository->find(2)?->action, 'the other writer won id 2');
        self::assertTrue($this->audit->pruefeAbschnitt(0)->intakt());
        self::assertSame(['name' => 'Kasse'], $this->audit->details($eintrag, $this->tresor), 'the retry re-encrypted for the new id');
    }

    public function testTheInsertRefusesATakenId(): void
    {
        $eintrag = $this->audit->record(AuditAction::Logout, 1, self::IP, 1);

        $this->expectException(AuditConflict::class);
        $this->repository->insert($eintrag);
    }

    public function testDetailsOpenOnlyWithTheVault(): void
    {
        $eintrag = $this->audit->record(AuditAction::BenutzerGeaendert, 1, self::IP, 2, [
            'rollen' => [3, 4],
            'zugang_bis' => '2026-12-31',
            'name_geaendert' => false,
        ]);

        self::assertNotNull($eintrag->detailsEnc);
        self::assertNotNull($eintrag->dekSealed);
        self::assertSame(
            ['rollen' => [3, 4], 'zugang_bis' => '2026-12-31', 'name_geaendert' => false],
            $this->audit->details($eintrag, $this->tresor),
        );
        self::assertNull($this->audit->details($eintrag, Vault::create()), 'a different vault cannot open them');
    }

    /**
     * The AAD names the row: ciphertext and key moved to another row do
     * not open there (and break the chain as well).
     */
    public function testDetailsMovedToAnotherRowDoNotOpen(): void
    {
        $quelle = $this->audit->record(AuditAction::RolleGeloescht, 1, self::IP, 9, ['name' => 'Jugend']);
        $ziel = $this->audit->record(AuditAction::RolleGeloescht, 1, self::IP, 10, ['name' => 'Senioren']);

        $vertauscht = new AuditEntry(...[
            ...get_object_vars($ziel),
            'detailsEnc' => $quelle->detailsEnc,
            'dekSealed' => $quelle->dekSealed,
        ]);

        self::assertNull($this->audit->details($vertauscht, $this->tresor));
    }

    public function testAnEventWithoutDetailsStoresNoCiphertext(): void
    {
        $eintrag = $this->audit->record(AuditAction::Logout, 1, self::IP, 1);

        self::assertNull($eintrag->detailsEnc);
        self::assertNull($eintrag->dekSealed);
        self::assertNull($this->audit->details($eintrag, $this->tresor));
    }

    /**
     * CLAUDE.md section 4: no plaintext in the log. The table holds
     * neither the IP, nor anything the details carried, in any column.
     */
    public function testNothingReadableEndsUpInTheTable(): void
    {
        $this->audit->record(AuditAction::BenutzerGeaendert, 1, self::IP, 2, [
            'geheimnis' => 'Vereinskasse-Sommerfest',
        ]);

        $zeile = $this->pdo()->query('SELECT * FROM audit_log')->fetch();
        $alles = implode("\n", array_map(static fn(mixed $wert): string => (string) $wert, (array) $zeile));

        self::assertStringNotContainsString(self::IP, $alles);
        self::assertStringNotContainsString(bin2hex((string) inet_pton(self::IP)), bin2hex($alles));
        self::assertStringNotContainsString('Vereinskasse', $alles);
        self::assertStringNotContainsString('geheimnis', $alles);
    }

    /**
     * The same address gives the same short identifier, so the list can say
     * "same as that row" - but the hash is keyed, not a bare SHA-256.
     */
    public function testTheIpHashIsKeyedAndStable(): void
    {
        $eins = $this->audit->record(AuditAction::LoginFehlgeschlagen, null, self::IP);
        $zwei = $this->audit->record(AuditAction::LoginFehlgeschlagen, null, self::IP);
        $andere = $this->audit->record(AuditAction::LoginFehlgeschlagen, null, '203.0.113.9');
        $ohne = $this->audit->record(AuditAction::LoginFehlgeschlagen, null, '');

        self::assertSame($eins->ipHash, $zwei->ipHash);
        self::assertNotSame($eins->ipHash, $andere->ipHash);
        self::assertNotSame(hash('sha256', self::IP, true), $eins->ipHash);
        self::assertNull($ohne->ipHash);
        self::assertNull($eins->entity, 'a failed login names no account');
    }

    public function testFiltersRunInSql(): void
    {
        $montag = new \DateTimeImmutable('2026-09-21 09:00:00');
        $dienstag = new \DateTimeImmutable('2026-09-22 23:59:59');
        $this->audit->record(AuditAction::LoginErfolg, 1, self::IP, 1, now: $montag);
        $this->audit->record(AuditAction::LoginErfolg, 2, self::IP, 2, now: $dienstag);
        $this->audit->record(AuditAction::RolleGeaendert, 1, self::IP, 7, now: $dienstag);
        $this->audit->record(AuditAction::Logout, 1, self::IP, 1, now: new \DateTimeImmutable('2026-09-23 00:00:00'));

        $ids = fn(AuditFilter $filter): array => array_map(
            static fn(AuditEntry $e): int => $e->id,
            $this->repository->page($filter, null, 50),
        );

        self::assertSame([2, 1], $ids(new AuditFilter(aktion: AuditAction::LoginErfolg)));
        self::assertSame([4, 3, 1], $ids(new AuditFilter(userId: 1)));
        self::assertSame([3, 2], $ids(new AuditFilter(von: new \DateTimeImmutable('2026-09-22'), bis: new \DateTimeImmutable('2026-09-22'))), 'bis covers the whole day');
        self::assertSame([3], $ids(new AuditFilter(entity: 'role', entityId: 7)));
        self::assertSame([4, 3, 2, 1], $ids(new AuditFilter()));
    }

    public function testPagingIsStableWhileRowsArrive(): void
    {
        $this->schreibe(5);

        $erste = $this->repository->page(new AuditFilter(), null, 2);
        $this->schreibe(1);
        $zweite = $this->repository->page(new AuditFilter(), end($erste)->id, 2);

        self::assertSame([5, 4], array_map(static fn(AuditEntry $e): int => $e->id, $erste));
        self::assertSame([3, 2], array_map(static fn(AuditEntry $e): int => $e->id, $zweite));
    }

    public function testTheFilterReadsOnlyWhatParses(): void
    {
        $filter = AuditFilter::fromQuery([
            'aktion' => 'gibt.es.nicht',
            'benutzer' => '12',
            'von' => '2026-02-30',
            'bis' => '2026-09-23',
            'entity' => 'user; DROP TABLE',
            'entity_id' => '-3',
        ]);

        self::assertNull($filter->aktion);
        self::assertSame(12, $filter->userId);
        self::assertNull($filter->von);
        self::assertSame('2026-09-23', $filter->bis?->format('Y-m-d'));
        self::assertNull($filter->entity);
        self::assertNull($filter->entityId);
        self::assertSame(['benutzer' => '12', 'bis' => '2026-09-23'], $filter->toQuery());
    }

    private function schreibe(int $anzahl): void
    {
        for ($i = 0; $i < $anzahl; ++$i) {
            $this->audit->record(AuditAction::LoginErfolg, 1, self::IP, 1, ['nr' => $i]);
        }
    }
}
