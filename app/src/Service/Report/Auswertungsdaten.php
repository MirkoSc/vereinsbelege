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
 * limit; the page caches the result in the session (05 section 1).
 */
final readonly class Auswertungsdaten
{
    /** Only receipts a human has checked count (KI results are suggestions until then). */
    public const array BELEG_STATUS = [DocumentStatus::Geprueft, DocumentStatus::Festgeschrieben];

    public const string SETTING_SPHAEREN = 'sphaeren_aktiv';

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
        return $this->settings->get(self::SETTING_SPHAEREN, '0') === '1';
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

        return match ($grundlage) {
            Grundlage::Belege => $this->ausBelegen($vault, $zeitraum, $begrenzt, $bereich),
            Grundlage::Buchungen => $this->ausBuchungen($vault, $zeitraum, $begrenzt, $bereich),
        };
    }

    /**
     * The accounts and every booking up to the end of $zeitraum - what
     * ReportService::kontostaende()/kontosalden() need. Transfers included.
     * Bookings carry no cost center: nothing for a cost-center scope. A
     * balance at the start of a period needs the bookings before it, also
     * for a period-scoped reader; only sums of them are shown.
     *
     * @return array{konten: list<BankAccount>, bewegungen: list<Kontobewegung>, unlesbar: int}
     */
    public function kontobewegungen(Vault $vault, Zeitraum $zeitraum, Zugriffsbereich $bereich): array
    {
        $begrenzt = $zeitraum->begrenztAuf($bereich);
        if ($bereich->kostenstellen !== null || $begrenzt === null) {
            return ['konten' => [], 'bewegungen' => [], 'unlesbar' => 0];
        }

        $konten = $this->konten->liste($vault);
        $bewegungen = [];
        $unlesbar = 0;
        foreach ($konten as $konto) {
            foreach ($this->buchungen->between($konto->id, $konto->openingDate, $begrenzt->bis->modify('+1 day')) as $record) {
                try {
                    $buchung = Buchungen::entschluesseln($vault, $record);
                } catch (CryptoException | \JsonException) {
                    ++$unlesbar;
                    continue;
                }
                $bewegungen[] = new Kontobewegung($konto->id, $buchung->bookingDate, $buchung->amount);
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
        $zuordnungen = [];
        $belege = [];
        $unlesbar = 0;
        try {
            $zuordnungen = $this->zuordnung->fuerBuchungen($vault, array_map(static fn(BankTransactionRecord $r): int => $r->id, $records));
        } catch (CryptoException) {
            // One unreadable allocation would split a booking wrongly: then
            // read them booking by booking and leave out only the affected ones.
            foreach ($records as $record) {
                try {
                    $zuordnungen += $this->zuordnung->fuerBuchungen($vault, [$record->id]);
                } catch (CryptoException) {
                    $zuordnungen[$record->id] = null;
                }
            }
        }

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
