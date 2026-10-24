<?php

declare(strict_types=1);

namespace App\Tests\Integration;

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
use App\Domain\ZuordnungMethode;
use App\Repository\AuditLogRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BankRuleViolation;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;

/**
 * Two requests at once on the matching (M10-1/M10-3, issues #65/#67): a
 * second connection plays the other request. Inside a transaction MariaDB
 * (REPEATABLE READ) answers plain reads from the snapshot of the first
 * one - sums checked under the row locks, and the audit log's head on a
 * retry, must come from locking reads, or the second request does not see
 * what the first committed meanwhile.
 */
final class ZuordnungNebenlaeufigkeitTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.10';

    private Vault $tresor;
    private ServerCrypto $crypto;
    private ?\PDO $zweite = null;
    private int $laufnummer = 0;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
    }

    protected function tearDown(): void
    {
        // An open transaction would hold locks the next test's DROP waits for.
        if ($this->zweite?->inTransaction() === true) {
            $this->zweite->rollBack();
        }
        $this->zweite = null;
        parent::tearDown();
    }

    public function testASecondRequestSeesTheAllocationTheFirstCommittedMeanwhile(): void
    {
        $konto = new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen(
            $this->tresor,
            BankAccountKind::Bank,
            ['name' => 'Girokonto', 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '0,00', 'opening_date' => '2026-01-01'],
            new \DateTimeImmutable(),
        )->id;
        $erster = $this->beleg(6000);
        $zweiter = $this->beleg(6000);
        $buchung = $this->buchung($konto, -10000);

        // The other request has begun and read something: its snapshot is fixed.
        $andere = $this->zweiteVerbindung();
        $andere->beginTransaction();
        $andere->query('SELECT COUNT(*) FROM allocation, audit_log')->fetchColumn();

        // This request allocates and commits in between.
        ZuordnungFabrik::bauen($this->pdo(), $this->audit($this->pdo()))
            ->zuordnen($this->tresor, $erster, $buchung, 6000, ZuordnungMethode::Manuell, null, null, null, self::IP, new \DateTimeImmutable());

        // The other request, in its open transaction: only 4 000 are left.
        $dort = ZuordnungFabrik::bauen($andere, $this->audit($andere));
        try {
            $dort->zuordnen($this->tresor, $zweiter, $buchung, 6000, ZuordnungMethode::Manuell, null, null, null, self::IP, new \DateTimeImmutable());
            self::fail('The booking was allocated beyond its amount.');
        } catch (BankRuleViolation $e) {
            self::assertSame('betrag', $e->feld);
        }

        // What is left goes - and its audit entry chains onto the one
        // committed meanwhile instead of failing on the taken id.
        $dort->zuordnen($this->tresor, $zweiter, $buchung, 4000, ZuordnungMethode::Manuell, null, null, null, self::IP, new \DateTimeImmutable());
        $andere->commit();

        $stmt = $this->pdo()->prepare('SELECT doc_status FROM bank_transaction WHERE id = ?');
        $stmt->execute([$buchung]);
        self::assertSame(BankTransactionDocStatus::Zugeordnet->value, $stmt->fetchColumn());
        $zuordnungen = ZuordnungFabrik::bauen($this->pdo(), $this->audit($this->pdo()))->fuerBuchung($this->tresor, $buchung);
        self::assertSame([6000, 4000], array_map(static fn($z): int => $z->amount, $zuordnungen));

        $pruefung = $this->audit($this->pdo())->pruefeAbschnitt(0);
        self::assertTrue($pruefung->intakt(), 'the hash chain has no branch');
    }

    private function audit(\PDO $pdo): AuditLog
    {
        return new AuditLog(new AuditLogRepository($pdo), new VaultRepository($pdo), $this->crypto);
    }

    private function zweiteVerbindung(): \PDO
    {
        $db = self::configData()['db'];
        $this->zweite = new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], self::dbName()),
            $db['user'],
            $db['password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 3,
            ],
        );
        // Short, so a lock that is never released fails the test instead of hanging it.
        $this->zweite->exec('SET SESSION innodb_lock_wait_timeout = 5');

        return $this->zweite;
    }

    /** A checked receipt paid from an account; returns the `invoice` id. */
    private function beleg(int $brutto): int
    {
        ++$this->laufnummer;
        $dokument = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('+' . $this->laufnummer . ' seconds'),
            DocumentStatus::Geprueft,
        );
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, null, new \DateTimeImmutable('2026-03-14'), null, null, null, null, null),
            $this->tresor->sealDataKey($schluessel),
            null,
            null,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData('', $brutto, null, [], 'EUR', 'Zweck', '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    private function buchung(int $konto, int $cent): int
    {
        $repository = new BankTransactionRepository($this->pdo());
        $richtung = BankTransactionDirection::ausDemBetrag($cent);
        $key = DataKey::generate();
        $id = $repository->insert(
            $konto,
            null,
            new \DateTimeImmutable('2026-03-20'),
            null,
            $richtung,
            $this->tresor->sealDataKey($key),
            random_bytes(32),
            null,
            BankTransactionDocStatus::fuerNeueBuchung(true),
            true,
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => 'Bauhaus', 'counterparty_iban' => '',
            'purpose' => 'RE-1 RE-2', 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }
}
