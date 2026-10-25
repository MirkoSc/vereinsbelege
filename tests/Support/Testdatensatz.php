<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\BankAccountKind;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSource;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\Erstattungsart;
use App\Domain\InvoiceData;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceStructure;
use App\Domain\InvoiceType;
use App\Domain\OcrStatus;
use App\Domain\SupplierRole;
use App\Domain\Zahlungsweg;
use App\Domain\ZuordnungMethode;
use App\Repository\BankAccountRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\CategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SubmissionRepository;
use App\Repository\SupplierRepository;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\Import\Dedupschluessel;
use App\Service\Bank\Import\KontoauszugImport;
use App\Service\Bank\Zuordnung;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Invoice\Pruefung;
use App\Service\MasterData\SupplierService;
use App\Service\Submission\EinreichungsAngaben;
use App\Service\Submission\Referenzvergabe;

/**
 * A generated, anonymous club data set for the performance test (issue
 * #82/M13-5): several years of receipts and bookings, distributed over
 * categories, cost centers and suppliers the way a club's books are -
 * a few suppliers and categories carry most of the receipts, most bookings
 * are small, a few are large.
 *
 * Every row goes through the same encryption as in production: a data key
 * per row sealed to the vault's public key, AEAD columns with the
 * table|id|column context, the blind indexes the application keeps
 * (receipt number, booking dedup key and counterparty, supplier name and
 * IBAN), the public submission's payload through Referenzvergabe, and the
 * allocations through the real Zuordnung (status recalculation, audit
 * chain). Nothing here is real: names are numbered placeholders, IBANs are
 * empty, and the random generator is seeded, so two runs build the same
 * data.
 *
 * Bookings are written the way KontoauszugImport writes them (same columns,
 * keys and payload), only without the upload/preview step chain around it -
 * that chain is not what a report reads.
 */
final class Testdatensatz
{
    /** Receipts in total: checked ones plus those still in the inbox or in review. */
    public const int BELEGE = 3000;

    /** Bookings in total, on the bank account and the cash box. */
    public const int BUCHUNGEN = 5000;

    /** Of BELEGE: still in the inbox (submissions), in review, rejected. */
    public const int EINGEGANGEN = 100;

    public const int IN_PRUEFUNG = 30;

    public const int ABGELEHNT = 20;

    public const int LIEFERANTEN = 150;

    public const array KOSTENSTELLEN = [
        'Herren' => 30, 'Damen' => 12, 'Jugend' => 25, 'Senioren' => 6,
        'Tennis' => 8, 'Turnen' => 7, 'Platz & Halle' => 20, 'Geschäftsstelle' => 15,
    ];

    /** Share of checked receipts without a cost center. */
    private const float OHNE_KOSTENSTELLE = 0.2;

    /** Share of bank receipts that got their booking allocated. */
    private const float ZUGEORDNET = 0.85;

    public const string IP = '192.0.2.82';

    private readonly \Random\Randomizer $zufall;

    /** @var array{einnahme: list<int>, ausgabe: list<int>} */
    private array $kategorien = ['einnahme' => [], 'ausgabe' => []];

    /** @var list<int> */
    private array $kostenstellen = [];

    /** @var list<int> */
    private array $kostenstellenGewicht = [];

    /** @var array{lieferant: list<int>, zahler: list<int>} */
    private array $lieferanten = ['lieferant' => [], 'zahler' => []];

    private int $girokonto = 0;

    private int $kasse = 0;

    private int $buchungen = 0;

    private int $zuordnungen = 0;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Vault $tresor,
        private readonly Zuordnung $zuordnung,
        private readonly int $userId,
        /** @var list<int> the calendar years the data covers, oldest first */
        private readonly array $jahre,
        int $seed = 82,
    ) {
        $this->zufall = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
    }

    /**
     * Builds the whole data set and returns what it wrote.
     *
     * @return array{belege: int, buchungen: int, zuordnungen: int, lieferanten: int, kostenstellen: int, jahre: list<int>}
     */
    public function erzeugen(): array
    {
        $this->stammdaten();

        $geprueft = self::BELEGE - self::EINGEGANGEN - self::IN_PRUEFUNG - self::ABGELEHNT;
        for ($i = 0; $i < $geprueft; ++$i) {
            $this->gepruefterBeleg($i);
        }
        for ($i = 0; $i < self::IN_PRUEFUNG; ++$i) {
            $this->belegOhneBuchung($i, DocumentStatus::InPruefung);
        }
        for ($i = 0; $i < self::ABGELEHNT; ++$i) {
            $this->belegOhneBuchung($i, DocumentStatus::Abgelehnt);
        }
        for ($i = 0; $i < self::EINGEGANGEN; ++$i) {
            $this->einreichung($i);
        }

        // The rest of the bookings carry no receipt: membership fees,
        // bank charges, cash in and out.
        for ($i = 0; $this->buchungen < self::BUCHUNGEN; ++$i) {
            $this->buchungOhneBeleg($i);
        }

        return [
            'belege' => (int) $this->pdo->query('SELECT COUNT(*) FROM document')->fetchColumn(),
            'buchungen' => (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transaction')->fetchColumn(),
            'zuordnungen' => $this->zuordnungen,
            'lieferanten' => count($this->lieferanten['lieferant']) + count($this->lieferanten['zahler']),
            'kostenstellen' => count($this->kostenstellen),
            'jahre' => $this->jahre,
        ];
    }

    private function stammdaten(): void
    {
        foreach ($this->pdo->query("SELECT id, direction FROM category WHERE active = 1 ORDER BY sort, id")->fetchAll() as $zeile) {
            $richtung = (string) $zeile['direction'];
            foreach ($richtung === 'beide' ? ['einnahme', 'ausgabe'] : [$richtung] as $r) {
                $this->kategorien[$r][] = (int) $zeile['id'];
            }
        }
        if ($this->kategorien['einnahme'] === [] || $this->kategorien['ausgabe'] === []) {
            throw new \LogicException('The migrations seed categories in both directions.');
        }

        foreach (self::KOSTENSTELLEN as $name => $gewicht) {
            $this->pdo->prepare('INSERT INTO cost_center (name) VALUES (?)')->execute([$name]);
            $this->kostenstellen[] = (int) $this->pdo->lastInsertId();
            $this->kostenstellenGewicht[] = $gewicht;
        }

        $lieferanten = new SupplierService($this->pdo, new SupplierRepository($this->pdo), new CategoryRepository($this->pdo));
        $jetzt = new \DateTimeImmutable();
        for ($i = 1; $i <= self::LIEFERANTEN; ++$i) {
            // Four in five sell to the club, the rest pay it (sponsors, members).
            $rolle = $i % 5 === 0 ? SupplierRole::Zahler : SupplierRole::Lieferant;
            $id = $lieferanten->anlegen($this->tresor, ['name' => sprintf('Testfirma %03d', $i), 'rolle' => $rolle->value], $jetzt)->id;
            $this->lieferanten[$rolle === SupplierRole::Zahler ? 'zahler' : 'lieferant'][] = $id;
        }

        $konten = new BankAccountService($this->pdo, new BankAccountRepository($this->pdo));
        $start = sprintf('%d-01-01', $this->jahre[0]);
        $this->girokonto = $konten->anlegen($this->tresor, BankAccountKind::Bank, ['name' => 'Girokonto', 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '25000,00', 'opening_date' => $start], $jetzt)->id;
        $this->kasse = $konten->anlegen($this->tresor, BankAccountKind::Kasse, ['name' => 'Kasse', 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '500,00', 'opening_date' => $start], $jetzt)->id;
    }

    private function gepruefterBeleg(int $nr): void
    {
        $einnahme = $this->zufall->getFloat(0, 1) < 0.25;
        $richtung = $einnahme ? InvoiceDirection::Einnahme : InvoiceDirection::Ausgabe;
        $weg = $einnahme ? Zahlungsweg::Konto : $this->gewichtet([Zahlungsweg::Konto, Zahlungsweg::Bar, Zahlungsweg::Erstattung], [80, 12, 8]);
        $datum = $this->tagIn($this->jahre[$nr % count($this->jahre)]);
        $brutto = $this->betrag($einnahme ? 150.0 : 60.0);
        $kostenstelle = $this->zufall->getFloat(0, 1) < self::OHNE_KOSTENSTELLE ? null : $this->gewichtet($this->kostenstellen, $this->kostenstellenGewicht);

        $invoiceId = $this->beleg($nr, DocumentStatus::Geprueft, $richtung, $weg, $datum, $brutto, $kostenstelle);

        $mitBuchung = match ($weg) {
            Zahlungsweg::Konto => $this->zufall->getFloat(0, 1) < self::ZUGEORDNET,
            Zahlungsweg::Erstattung => $this->zufall->getFloat(0, 1) < 0.5,
            default => false,
        };
        if (!$mitBuchung) {
            return;
        }

        $tage = $this->zufall->getInt(0, 20);
        $buchung = $this->buchung(
            $this->girokonto,
            min($datum->modify('+' . $tage . ' days'), new \DateTimeImmutable('yesterday')),
            $einnahme ? $brutto : -$brutto,
            sprintf('RE-%05d', $nr),
            sprintf('Testfirma %03d', $nr % self::LIEFERANTEN + 1),
            null,
        );
        $methode = $this->gewichtet([ZuordnungMethode::Auto, ZuordnungMethode::VorschlagBestaetigt, ZuordnungMethode::Manuell], [50, 30, 20]);
        $this->zuordnung->zuordnen($this->tresor, $invoiceId, $buchung, $brutto, $methode, $methode === ZuordnungMethode::Manuell ? null : 90, null, $this->userId, self::IP, new \DateTimeImmutable());
        ++$this->zuordnungen;
    }

    private function belegOhneBuchung(int $nr, DocumentStatus $status): void
    {
        $jahr = $this->jahre[count($this->jahre) - 1];
        $this->beleg(100000 + $nr + ($status === DocumentStatus::Abgelehnt ? 1000 : 0), $status, InvoiceDirection::Ausgabe, Zahlungsweg::Konto, $this->tagIn($jahr), $this->betrag(60.0), null);
    }

    private function beleg(int $nr, DocumentStatus $status, InvoiceDirection $richtung, Zahlungsweg $weg, \DateTimeImmutable $datum, int $brutto, ?int $kostenstelle): int
    {
        $einnahme = $richtung === InvoiceDirection::Einnahme;
        $dokument = new DocumentRepository($this->pdo)->insert(
            DocumentSource::Intern,
            null,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            $datum->setTime(10, 0),
            $status,
            costCenterId: $kostenstelle,
            createdBy: $this->userId,
        );

        $lieferanten = $this->lieferanten[$einnahme ? 'zahler' : 'lieferant'];
        $lieferant = $this->zufall->getFloat(0, 1) < 0.1 ? null : $lieferanten[$this->zipf(count($lieferanten))];
        $kategorien = $this->kategorien[$einnahme ? 'einnahme' : 'ausgabe'];
        $kategorie = $kategorien[$this->zipf(count($kategorien))];

        $nummer = sprintf('RE-%05d', $nr);
        $schluessel = DataKey::generate();
        $rechnungen = new InvoiceRepository($this->pdo);
        $id = $rechnungen->insert(
            $dokument,
            new InvoiceStructure(InvoiceType::Rechnung, $richtung, $lieferant, $datum, $datum->modify('+14 days'), null, null, $kategorie, $kostenstelle, $weg),
            $this->tresor->sealDataKey($schluessel),
            $this->tresor->blindIndex()->forValue(Pruefung::NUMMER_ZWECK, Pruefung::nummerFuerIndex($nummer)),
            $this->userId,
            $datum,
        );
        $netto = intdiv($brutto * 100, 119);
        $daten = new InvoiceData($nummer, $brutto, $netto, [['rate' => '19', 'amount' => $brutto - $netto]], 'EUR', 'Testbeleg ' . $nr, '');
        $rechnungen->setData($id, FieldCipher::encrypt($schluessel, json_encode($daten->toPayload(), JSON_THROW_ON_ERROR), new FieldContext('invoice', $id, 'data_enc')));
        if ($status === DocumentStatus::Geprueft) {
            $rechnungen->setzeGeprueft($id, $this->userId, $datum);
        }

        return $id;
    }

    /** A public submission in the inbox: payload sealed like /einreichen does it, no pages. */
    private function einreichung(int $nr): void
    {
        $eingang = new \DateTimeImmutable('today')->modify(sprintf('-%d hours', 2 * self::EINGEGANGEN - $nr));
        $kostenstelle = $nr % 3 === 0 ? null : $this->kostenstellen[$nr % count($this->kostenstellen)];
        $angaben = new EinreichungsAngaben(
            sprintf('Testperson %03d', $nr),
            null,
            $nr % 4 === 0 ? Erstattungsart::Bar : Erstattungsart::Keine,
            null,
            null,
            'Testeinreichung ' . $nr,
            $kostenstelle,
        );
        [$submission] = new Referenzvergabe(new SubmissionRepository($this->pdo))->anlegen(bin2hex(random_bytes(16)), $angaben, $this->tresor, $eingang);
        new DocumentRepository($this->pdo)->insert(
            DocumentSource::Einreichung,
            $submission,
            [],
            $this->tresor->sealDataKey(DataKey::generate()),
            $eingang,
            ocrStatus: OcrStatus::Keine,
            costCenterId: $kostenstelle,
        );
    }

    private function buchungOhneBeleg(int $nr): void
    {
        $jahr = $this->jahre[$nr % count($this->jahre)];
        $art = $this->gewichtet(['beitrag', 'spende', 'gebuehr', 'kasse_ein', 'kasse_aus'], [55, 8, 15, 12, 10]);
        [$konto, $cent, $zweck, $kategorie] = match ($art) {
            'beitrag' => [$this->girokonto, 100 * $this->gewichtet([5, 8, 12, 15, 25, 60], [10, 20, 30, 20, 15, 5]), 'Mitgliedsbeitrag', $this->kategorien['einnahme'][0]],
            'spende' => [$this->girokonto, $this->betrag(80.0), 'Spende', $this->kategorien['einnahme'][1] ?? null],
            'gebuehr' => [$this->girokonto, -$this->zufall->getInt(50, 1500), 'Kontoführung', null],
            'kasse_ein' => [$this->kasse, $this->betrag(40.0), 'Kasseneinnahme', $this->kategorien['einnahme'][$this->zipf(count($this->kategorien['einnahme']))]],
            'kasse_aus' => [$this->kasse, -$this->betrag(25.0), 'Barauslage', null],
        };

        $this->buchung($konto, $this->tagIn($jahr), $cent, $zweck . ' ' . $nr, sprintf('Testperson %04d', $nr % 400), $kategorie, belegNoetig: $cent < 0 && $art !== 'gebuehr');
    }

    /** Writes one booking exactly as KontoauszugImport::buchung() does. */
    private function buchung(int $konto, \DateTimeImmutable $datum, int $cent, string $zweck, string $gegenseite, ?int $kategorie, ?bool $belegNoetig = null): int
    {
        $repository = new BankTransactionRepository($this->pdo);
        $richtung = BankTransactionDirection::ausDemBetrag($cent);
        $belegNoetig ??= $richtung === BankTransactionDirection::Ausgabe;
        $index = $this->tresor->blindIndex();
        $key = DataKey::generate();
        $id = $repository->insert(
            $konto,
            null,
            $datum,
            $datum,
            $richtung,
            $this->tresor->sealDataKey($key),
            Dedupschluessel::fuerBuchung($index, $konto, $datum, $cent, $zweck, '', ++$this->buchungen),
            $index->forValue(KontoauszugImport::COUNTERPARTY_PURPOSE, $gegenseite),
            BankTransactionDocStatus::fuerNeueBuchung($belegNoetig),
            $belegNoetig,
            BankTransactionSource::Import,
            $datum,
            categoryId: $kategorie,
        ) ?? throw new \LogicException('Every generated booking has its own dedup key.');
        $repository->setCiphertext($id, FieldCipher::encrypt($key, json_encode([
            'amount' => $cent, 'currency' => 'EUR', 'counterparty_name' => $gegenseite, 'counterparty_iban' => '',
            'purpose' => $zweck, 'eref' => '', 'mref' => '', 'cred' => '', 'gvc' => '166', 'booking_text' => 'SEPA-Überweisung',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), new FieldContext('bank_transaction', $id, 'data_enc')));

        return $id;
    }

    /** A day of $jahr, never after yesterday. */
    private function tagIn(int $jahr): \DateTimeImmutable
    {
        $tag = new \DateTimeImmutable(sprintf('%d-01-01', $jahr))->modify('+' . $this->zufall->getInt(0, 364) . ' days');
        $gestern = new \DateTimeImmutable('yesterday');

        return $tag > $gestern ? $gestern : $tag;
    }

    /**
     * A log-normal amount in cents around $median euros: most small, a few
     * large - between 1 and 20 000 euros.
     */
    private function betrag(float $median): int
    {
        $u1 = max($this->zufall->getFloat(0, 1), 1e-9);
        $u2 = $this->zufall->getFloat(0, 1);
        $normal = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);

        return (int) max(100, min(2_000_000, round($median * 100 * exp(1.1 * $normal))));
    }

    /** An index 0..$n-1, the first ones much more often (Zipf-like, s = 1). */
    private function zipf(int $n): int
    {
        return $this->gewichtet(range(0, $n - 1), array_map(static fn(int $rang): int => intdiv(1000, $rang), range(1, $n)));
    }

    /**
     * @template T
     *
     * @param list<T>   $werte
     * @param list<int> $gewichte
     *
     * @return T
     */
    private function gewichtet(array $werte, array $gewichte): mixed
    {
        $wurf = $this->zufall->getInt(1, array_sum($gewichte));
        foreach ($werte as $i => $wert) {
            $wurf -= $gewichte[$i];
            if ($wurf <= 0) {
                return $wert;
            }
        }

        return $werte[array_key_last($werte)];
    }
}
