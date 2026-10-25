<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\BankAccount;
use App\Domain\BankTransactionRecord;
use App\Domain\DocumentStatus;
use App\Domain\Zugriffsbereich;
use App\Repository\BankTransactionRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SettingRepository;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Zuordnung;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Invoice\Pruefung;
use App\Service\MasterData\Sphaereneinstellung;

/**
 * Loads and decrypts what ReportService calculates on (issue #71, E-44) -
 * only in a session with an unlocked vault. SQL narrows by plaintext
 * structure and the reader's Zugriffsbereich; amounts are vault data and
 * are decrypted here, one row at a time. A row that cannot be decrypted is
 * left out and counted, a row in another currency than EUR as well.
 *
 * Volume: a club has a few thousand receipts and bookings a year. Each row
 * costs one sealed-box open plus one AEAD decrypt (well under a
 * millisecond), so a year on either basis stays far below the request time
 * limit; pages read it through AuswertungsCache, which keeps the result
 * encrypted in the session (05 section 1, issue #72).
 */
final readonly class Auswertungsdaten implements Auswertungsquelle
{
    /** Only receipts a human has checked count (KI results are suggestions until then). */
    public const array BELEG_STATUS = [DocumentStatus::Geprueft, DocumentStatus::Festgeschrieben];

    public const string SETTING_SPHAEREN = Sphaereneinstellung::SETTING;

    private const string WAEHRUNG = 'EUR';

    public function __construct(
        private InvoiceRepository $belege,
        private BankTransactionRepository $buchungen,
        private Zuordnung $zuordnung,
        private BankAccountService $konten,
        private SettingRepository $settings,
    ) {
    }

    /** E-15: the tax spheres are off unless the setting says '1'. */
    public function sphaerenAktiv(): bool
    {
        return new Sphaereneinstellung($this->settings)->aktiv();
    }

    /** A ReportService for this installation's settings. */
    public function rechner(): ReportService
    {
        return new ReportService($this->sphaerenAktiv());
    }

    public function grundlage(Vault $vault, Grundlage $grundlage, Zeitraum $zeitraum, Zugriffsbereich $bereich): Auswertungsgrundlage
    {
        $begrenzt = $zeitraum->begrenztAuf($bereich);
        if ($begrenzt === null) {
            return new Auswertungsgrundlage($grundlage, $zeitraum, []);
        }

        $ergebnis = match ($grundlage) {
            Grundlage::Belege => $this->ausBelegen($vault, $zeitraum, $begrenzt, $bereich),
            Grundlage::Buchungen => $this->ausBuchungen($vault, $zeitraum, $begrenzt, $bereich),
        };

        // A booking loaded for one in-scope allocation is split into all its
        // parts: only the parts the reader may see leave the loader (01 §4).
        return new Auswertungsgrundlage(
            $ergebnis->grundlage,
            $ergebnis->zeitraum,
            array_values(array_filter(
                $ergebnis->posten,
                static fn(Posten $p): bool => $begrenzt->enthaelt($p->datum) && $bereich->erlaubt($p->kostenstelleId, $p->datum),
            )),
            $ergebnis->unlesbar,
            $ergebnis->fremdwaehrung,
        );
    }

    /**
     * The accounts and every booking up to the end of $zeitraum - what
     * ReportService::kontostaende()/kontosalden() need. Transfers included.
     * Bookings carry no cost center: nothing for a cost-center scope. A
     * balance at the start of a period needs the bookings before it; for a
     * period-scoped reader they are summed per account into one carry-over
     * movement on the day before the period, so no single booking outside
     * the released period leaves the loader.
     *
     * A booking in another currency than its account's is counted as
     * unreadable rather than added to the balance.
     *
     * @return array{konten: list<BankAccount>, bewegungen: list<Kontobewegung>, unlesbar: int}
     */
    public function kontobewegungen(Vault $vault, Zeitraum $zeitraum, Zugriffsbereich $bereich): array
    {
        $begrenzt = $zeitraum->begrenztAuf($bereich);
        if ($bereich->kostenstellen !== null || $begrenzt === null) {
            return ['konten' => [], 'bewegungen' => [], 'unlesbar' => 0];
        }

        ['konten' => $konten, 'unlesbar' => $unlesbar] = $this->konten->lesbare($vault);
        $bewegungen = [];
        $ab = $bereich->von === null ? null : $bereich->von->format('Y-m-d');
        foreach ($konten as $konto) {
            $uebertrag = null;
            foreach ($this->buchungen->between($konto->id, $konto->openingDate, $begrenzt->bis->modify('+1 day')) as $record) {
                try {
                    $buchung = Buchungen::entschluesseln($vault, $record);
                } catch (CryptoException | \JsonException) {
                    ++$unlesbar;
                    continue;
                }
                if (strtoupper($buchung->currency) !== strtoupper($konto->currency)) {
                    ++$unlesbar;
                    continue;
                }
                if ($ab !== null && $buchung->bookingDate->format('Y-m-d') < $ab) {
                    $uebertrag = ($uebertrag ?? 0) + $buchung->amount;
                    continue;
                }
                $bewegungen[] = new Kontobewegung($konto->id, $buchung->bookingDate, $buchung->amount);
            }
            if ($uebertrag !== null && $bereich->von !== null) {
                $bewegungen[] = new Kontobewegung($konto->id, (new \DateTimeImmutable($ab))->modify('-1 day'), $uebertrag);
            }
        }

        return ['konten' => $konten, 'bewegungen' => $bewegungen, 'unlesbar' => $unlesbar];
    }

    private function ausBelegen(Vault $vault, Zeitraum $zeitraum, Zeitraum $begrenzt, Zugriffsbereich $bereich): Auswertungsgrundlage
    {
        $posten = [];
        $unlesbar = 0;
        $fremd = 0;
        foreach ($this->belege->fuerAuswertung($begrenzt->von, $begrenzt->bis, self::BELEG_STATUS, $bereich) as $zeile) {
            try {
                $daten = Pruefung::entschluesseln($vault, $zeile['record']);
            } catch (CryptoException | \JsonException) {
                ++$unlesbar;
                continue;
            }
            if (strtoupper($daten->currency) !== self::WAEHRUNG) {
                ++$fremd;
                continue;
            }
            $posten[] = Postenbildung::ausBeleg(Belegklasse::ausRecord($zeile['record'], $zeile['sphaere']), $daten->gross);
        }

        return new Auswertungsgrundlage(Grundlage::Belege, $zeitraum, $posten, $unlesbar, $fremd);
    }

    private function ausBuchungen(Vault $vault, Zeitraum $zeitraum, Zeitraum $begrenzt, Zugriffsbereich $bereich): Auswertungsgrundlage
    {
        $records = $this->buchungen->fuerAuswertung($begrenzt->von, $begrenzt->bis, self::BELEG_STATUS, $bereich);
        // A booking with an unreadable allocation (null) would be split
        // wrongly - it is left out and counted.
        $zuordnungen = $this->zuordnung->fuerBuchungen($vault, array_map(static fn(BankTransactionRecord $r): int => $r->id, $records));
        $belege = [];
        $unlesbar = 0;

        $ids = [];
        foreach ($zuordnungen as $liste) {
            foreach ($liste ?? [] as $z) {
                $ids[] = $z->invoiceId;
            }
        }
        foreach ($this->belege->auswertungNachIds($ids, self::BELEG_STATUS) as $id => $zeile) {
            $belege[$id] = Belegklasse::ausRecord($zeile['record'], $zeile['sphaere']);
        }

        $posten = [];
        $fremd = 0;
        foreach ($records as $record) {
            if (array_key_exists($record->id, $zuordnungen) && $zuordnungen[$record->id] === null) {
                ++$unlesbar;
                continue;
            }
            try {
                $buchung = Buchungen::entschluesseln($vault, $record);
            } catch (CryptoException | \JsonException) {
                ++$unlesbar;
                continue;
            }
            if (strtoupper($buchung->currency) !== self::WAEHRUNG) {
                ++$fremd;
                continue;
            }
            array_push($posten, ...Postenbildung::ausBuchung($buchung, $zuordnungen[$record->id] ?? [], $belege));
        }

        return new Auswertungsgrundlage(Grundlage::Buchungen, $zeitraum, $posten, $unlesbar, $fremd);
    }
}
