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
use App\Domain\Zugriffsbereich;
use App\Domain\ZuordnungMethode;
use App\Repository\AuditLogRepository;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\Report\Auswertungsdaten;
use App\Service\Report\Dimension;
use App\Service\Report\Grundlage;
use App\Service\Report\Posten;
use App\Service\Report\Zeitraum;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\ZuordnungFabrik;

/**
 * App\Service\Report\Auswertungsdaten against the real schema and vault
 * (issue #71, E-44): which receipts and bookings a report loads, the
 * cost-center scope in SQL, transfers left out, unreadable and foreign
 * currency rows counted, the balances' input. Made-up data only.
 */
final class AuswertungsdatenTest extends DatabaseTestCase
{
    private const string IP = '192.0.2.10';

    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;
    private int $jugend;
    private int $herren;
    private int $laufnummer = 0;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $crypto);
        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $this->jugend = $this->kostenstelle('Jugend');
        $this->herren = $this->kostenstelle('Herren');
    }

    public function testTheReceiptBasisLoadsCheckedReceiptsOfThePeriodWithinTheScope(): void
    {
        $geprueft = $this->beleg(10000, '2026-02-01', kostenstelle: $this->jugend);
        $fest = $this->beleg(2500, '2026-03-01', status: DocumentStatus::Festgeschrieben, kostenstelle: $this->herren);
        $this->beleg(4000, '2026-03-01', status: DocumentStatus::InPruefung, kostenstelle: $this->jugend);
        $this->beleg(4000, '2026-03-01', status: DocumentStatus::Abgelehnt, kostenstelle: $this->jugend);
        $this->beleg(4000, '2025-12-31', kostenstelle: $this->jugend);
        $this->beleg(4000, '2026-04-01', waehrung: 'CHF');
        $kaputt = $this->beleg(4000, '2026-04-02');
        new InvoiceRepository($this->pdo())->setData($kaputt, 'kaputt');
        $this->pdo()->prepare('UPDATE invoice SET sphere = ? WHERE id = ?')->execute(['ideell', $fest]);

        $daten = $this->daten();
        $alles = $daten->grundlage($this->tresor, Grundlage::Belege, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        self::assertSame([$geprueft, $fest], array_map(static fn(Posten $p) => $p->belegId, $alles->posten));
        self::assertSame([10000, 2500], array_map(static fn(Posten $p) => $p->betrag, $alles->posten));
        self::assertSame([null, 'ideell'], array_map(static fn(Posten $p) => $p->sphaere, $alles->posten));
        self::assertSame(1, $alles->unlesbar);
        self::assertSame(1, $alles->fremdwaehrung);
        self::assertSame(12500, $daten->rechner()->summen($alles, Zugriffsbereich::unbeschraenkt())->ausgaben);

        $jugend = new Zugriffsbereich([$this->jugend]);
        $nurJugend = $daten->grundlage($this->tresor, Grundlage::Belege, Zeitraum::jahr(2026), $jugend);
        self::assertSame([$geprueft], array_map(static fn(Posten $p) => $p->belegId, $nurJugend->posten));
        self::assertSame([], $daten->grundlage($this->tresor, Grundlage::Belege, Zeitraum::jahr(2026), new Zugriffsbereich([]))->posten);
        $extern = new Zugriffsbereich(null, new \DateTimeImmutable('2026-03-01'));
        self::assertSame([$fest], array_map(static fn(Posten $p) => $p->belegId, $daten->grundlage($this->tresor, Grundlage::Belege, Zeitraum::jahr(2026), $extern)->posten));
    }

    public function testTheBookingBasisSplitsByAllocationsLeavesOutTransfersAndHonoursTheScope(): void
    {
        $giro = $this->konto('Girokonto', '2025-01-01', '1.000,00');
        $kasse = $this->konto('Kasse', '2025-01-01', '0,00');
        $jugendBeleg = $this->beleg(6000, '2025-12-15', kostenstelle: $this->jugend);
        $offenerBeleg = $this->beleg(1000, '2026-01-02', status: DocumentStatus::InPruefung, kostenstelle: $this->jugend);
        $sammel = $this->buchung($giro, '2026-01-10', -10000);
        $this->zuordnen($jugendBeleg, $sammel, 6000);
        $this->zuordnen($offenerBeleg, $sammel, 1000);
        $beitrag = $this->buchung($giro, '2026-01-20', 25000);
        $aus = $this->buchung($giro, '2026-02-01', -5000);
        $ein = $this->buchung($kasse, '2026-02-01', 5000);
        $this->pdo()->prepare("UPDATE bank_transaction SET doc_source = 'umbuchung' WHERE id IN (?, ?)")->execute([$aus, $ein]);
        $this->buchung($giro, '2027-01-01', -999);

        $daten = $this->daten();
        $alles = $daten->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        self::assertSame(
            [[$sammel, $jugendBeleg, $this->jugend, 6000], [$sammel, null, null, 4000], [$beitrag, null, null, 25000]],
            array_map(static fn(Posten $p) => [$p->buchungId, $p->belegId, $p->kostenstelleId, $p->betrag], $alles->posten),
        );
        $summen = $daten->rechner()->summen($alles, Zugriffsbereich::unbeschraenkt());
        self::assertSame([25000, 10000], [$summen->einnahmen, $summen->ausgaben]);

        $jugend = new Zugriffsbereich([$this->jugend]);
        $nurJugend = $daten->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), $jugend);
        // Only the one part in scope leaves the loader - not the rest of the booking.
        self::assertSame(
            [[$sammel, $jugendBeleg, $this->jugend, 6000]],
            array_map(static fn(Posten $p) => [$p->buchungId, $p->belegId, $p->kostenstelleId, $p->betrag], $nurJugend->posten),
        );
        self::assertSame(6000, $daten->rechner()->summen($nurJugend, $jugend)->ausgaben);
        self::assertSame([$this->jugend], array_map(static fn($g) => $g->schluessel, $daten->rechner()->gruppiert($nurJugend, $jugend, Dimension::Kostenstelle)));
        self::assertSame([], $daten->grundlage($this->tresor, Grundlage::Buchungen, Zeitraum::jahr(2026), new Zugriffsbereich([$this->herren]))->posten);

        // Balances: transfers included, nothing for a cost-center scope.
        $bewegungen = $daten->kontobewegungen($this->tresor, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        self::assertCount(4, $bewegungen['bewegungen']);
        $stand = $daten->rechner()->kontostaende($bewegungen['konten'], $bewegungen['bewegungen'], new \DateTimeImmutable('2026-12-31'), Zugriffsbereich::unbeschraenkt());
        self::assertSame([$giro => 100000 - 10000 + 25000 - 5000, $kasse => 5000], $stand);
        self::assertSame(['konten' => [], 'bewegungen' => [], 'unlesbar' => 0], $daten->kontobewegungen($this->tresor, Zeitraum::jahr(2026), $jugend));

        // Period scope: bookings before the released period only as one carry-over per account.
        $extern = new Zugriffsbereich(null, new \DateTimeImmutable('2026-01-15'), new \DateTimeImmutable('2026-12-31'));
        $begrenzt = $daten->kontobewegungen($this->tresor, Zeitraum::jahr(2026), $extern);
        self::assertSame(
            [[$giro, '2026-01-14', -10000], [$giro, '2026-01-20', 25000], [$giro, '2026-02-01', -5000], [$kasse, '2026-02-01', 5000]],
            self::bewegungen($begrenzt['bewegungen']),
        );
        $salden = $daten->rechner()->kontosalden($begrenzt['konten'], $begrenzt['bewegungen'], Zeitraum::jahr(2026), $extern);
        self::assertSame([90000, 110000], [$salden[0]->anfang, $salden[0]->ende]);
    }

    public function testSpheresFollowTheSetting(): void
    {
        $daten = $this->daten();
        self::assertFalse($daten->sphaerenAktiv());
        new SettingRepository($this->pdo())->set(Auswertungsdaten::SETTING_SPHAEREN, '1');
        self::assertTrue($daten->sphaerenAktiv());
    }

    /**
     * @param list<\App\Service\Report\Kontobewegung> $bewegungen
     *
     * @return list<array{0: int, 1: string, 2: int}> sorted by account, date
     */
    private static function bewegungen(array $bewegungen): array
    {
        $liste = array_map(static fn($b) => [$b->kontoId, $b->datum->format('Y-m-d'), $b->betrag], $bewegungen);
        sort($liste);

        return $liste;
    }

    private function daten(): Auswertungsdaten
    {
        return new Auswertungsdaten(
            new InvoiceRepository($this->pdo()),
            new BankTransactionRepository($this->pdo()),
            ZuordnungFabrik::bauen($this->pdo(), $this->audit),
            new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo())),
            new SettingRepository($this->pdo()),
        );
    }

    private function kostenstelle(string $name): int
    {
        $this->pdo()->prepare('INSERT INTO cost_center (name) VALUES (?)')->execute([$name]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function beleg(int $brutto, string $datum, DocumentStatus $status = DocumentStatus::Geprueft, ?int $kostenstelle = null, string $waehrung = 'EUR'): int
    {
        ++$this->laufnummer;
        $dokument = new DocumentRepository($this->pdo())->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable()->modify('+' . $this->laufnummer . ' seconds'),
            $status,
            costCenterId: $kostenstelle,
            createdBy: $this->userId,
        );
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo());
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, InvoiceDirection::Ausgabe, null, new \DateTimeImmutable($datum), null, null, null, null, $kostenstelle),
            $this->tresor->sealDataKey($schluessel),
            null,
            $this->userId,
            new \DateTimeImmutable(),
        );
        $daten = new InvoiceData('', $brutto, null, [], $waehrung, '', '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));

        return $id;
    }

    private function konto(string $name, string $eroeffnet, string $bestand): int
    {
        return new BankAccountService($this->pdo(), new BankAccountRepository($this->pdo()))->anlegen(
            $this->tresor,
            $name === 'Kasse' ? BankAccountKind::Kasse : BankAccountKind::Bank,
            ['name' => $name, 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => $bestand, 'opening_date' => $eroeffnet],
            new \DateTimeImmutable(),
        )->id;
    }

    private function buchung(int $konto, string $datum, int $cent): int
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
            BankTransactionDocStatus::fuerNeueBuchung($richtung === BankTransactionDirection::Ausgabe),
            $richtung === BankTransactionDirection::Ausgabe,
            BankTransactionSource::Import,
            new \DateTimeImmutable(),
        ) ?? self::fail();
        $repository->setCiphertext($id, FieldCipher::encrypt($key, (string) json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => 'Gegenseite', 'counterparty_iban' => '',
            'purpose' => 'Zweck', 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '', 'booking_text' => 'SEPA-Überweisung',
        ]), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    private function zuordnen(int $beleg, int $buchung, int $betrag): void
    {
        ZuordnungFabrik::bauen($this->pdo(), $this->audit)->zuordnen($this->tresor, $beleg, $buchung, $betrag, ZuordnungMethode::Manuell, null, null, $this->userId, self::IP, new \DateTimeImmutable());
    }
}
