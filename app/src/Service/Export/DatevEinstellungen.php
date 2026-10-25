<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Repository\SettingRepository;

/**
 * The settings of the DATEV export (issue #93, E-49,
 * docs/spec/05-auswertung-und-export.md section 4), maintained on
 * /admin/datev: advisor and client number, length of a ledger account, the
 * chart of accounts, and which ledger account each category and each bank
 * account or cash box is booked on. One plaintext setting (JSON) - account
 * numbers and ids are configuration, not business data, and need no vault.
 *
 * Read tolerantly like App\Service\Export\ExportEinstellungen: a value in
 * the database that does not fit (edited by hand, a category deleted
 * since) is dropped instead of failing the page; the export then reports
 * what is missing.
 */
final readonly class DatevEinstellungen
{
    public const string SETTING = 'datev_einstellungen';

    public const int SACHKONTENLAENGE_STANDARD = 4;

    public const int SACHKONTENLAENGE_MIN = 4;

    public const int SACHKONTENLAENGE_MAX = 8;

    /** Charts of accounts offered on the page; any two digits are accepted. */
    public const array KONTENRAHMEN = [
        '42' => 'SKR 42',
        '49' => 'SKR 49',
        '03' => 'SKR 03',
        '04' => 'SKR 04',
    ];

    /**
     * @param array<int, string> $kategorien category id => ledger account
     * @param array<int, string> $konten bank account / cash box id => ledger account
     */
    public function __construct(
        public string $beraternummer = '',
        public string $mandantennummer = '',
        public int $sachkontenlaenge = self::SACHKONTENLAENGE_STANDARD,
        public string $kontenrahmen = '',
        public string $klaerungskonto = '',
        public array $kategorien = [],
        public array $konten = [],
        public string $geldtransitkonto = '',
    ) {
    }

    public static function fromSettings(SettingRepository $settings): self
    {
        try {
            $daten = json_decode($settings->get(self::SETTING, '{}'), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self();
        }
        if (!is_array($daten)) {
            return new self();
        }

        $text = static fn(string $feld): string => is_string($daten[$feld] ?? null) ? $daten[$feld] : '';
        $laenge = is_int($daten['sachkontenlaenge'] ?? null)
            && $daten['sachkontenlaenge'] >= self::SACHKONTENLAENGE_MIN
            && $daten['sachkontenlaenge'] <= self::SACHKONTENLAENGE_MAX
                ? $daten['sachkontenlaenge'] : self::SACHKONTENLAENGE_STANDARD;
        $konto = static fn(string $wert): string => self::istKonto($wert, $laenge) ? $wert : '';
        $zuordnung = static function (mixed $liste) use ($konto): array {
            $ergebnis = [];
            foreach (is_array($liste) ? $liste : [] as $id => $wert) {
                if (is_int($id) && $id > 0 && is_string($wert) && $konto($wert) !== '') {
                    $ergebnis[$id] = $wert;
                }
            }

            return $ergebnis;
        };

        return new self(
            beraternummer: self::istBeraternummer($text('beraternummer')) ? $text('beraternummer') : '',
            mandantennummer: self::istMandantennummer($text('mandantennummer')) ? $text('mandantennummer') : '',
            sachkontenlaenge: $laenge,
            kontenrahmen: self::istKontenrahmen($text('kontenrahmen')) ? $text('kontenrahmen') : '',
            klaerungskonto: $konto($text('klaerungskonto')),
            kategorien: $zuordnung($daten['kategorien'] ?? null),
            konten: $zuordnung($daten['konten'] ?? null),
            geldtransitkonto: $konto($text('geldtransitkonto')),
        );
    }

    /**
     * Reads and checks the form of /admin/datev. Only ids of existing
     * categories and accounts are kept; an empty field means "not mapped".
     *
     * @param array<string, mixed> $felder
     * @param list<int> $kategorieIds
     * @param list<int> $kontoIds
     *
     * @throws UngueltigeDatevEinstellung
     */
    public static function ausFormular(array $felder, array $kategorieIds, array $kontoIds): self
    {
        $text = static fn(mixed $wert): string => is_string($wert) ? trim($wert) : '';

        $berater = $text($felder['beraternummer'] ?? null);
        if ($berater !== '' && !self::istBeraternummer($berater)) {
            throw new UngueltigeDatevEinstellung('Die Beraternummer hat 4 bis 7 Ziffern und ist mindestens 1001.', 'beraternummer');
        }
        $mandant = $text($felder['mandantennummer'] ?? null);
        if ($mandant !== '' && !self::istMandantennummer($mandant)) {
            throw new UngueltigeDatevEinstellung('Die Mandantennummer hat 1 bis 5 Ziffern und ist mindestens 1.', 'mandantennummer');
        }
        $laengeText = $text($felder['sachkontenlaenge'] ?? null);
        $laenge = ctype_digit($laengeText) ? (int) $laengeText : 0;
        if ($laenge < self::SACHKONTENLAENGE_MIN || $laenge > self::SACHKONTENLAENGE_MAX) {
            throw new UngueltigeDatevEinstellung(sprintf('Die Sachkontenlänge liegt zwischen %d und %d.', self::SACHKONTENLAENGE_MIN, self::SACHKONTENLAENGE_MAX), 'sachkontenlaenge');
        }
        $rahmen = $text($felder['kontenrahmen'] ?? null);
        if ($rahmen !== '' && !self::istKontenrahmen($rahmen)) {
            throw new UngueltigeDatevEinstellung('Der Kontenrahmen besteht aus zwei Ziffern, z. B. 49.', 'kontenrahmen');
        }

        $konto = static function (string $wert, string $feld) use ($laenge): string {
            if ($wert !== '' && !self::istKonto($wert, $laenge)) {
                throw new UngueltigeDatevEinstellung(sprintf('Ein Sachkonto besteht aus 1 bis %d Ziffern und ist nicht 0.', $laenge), $feld);
            }

            return $wert;
        };
        $zuordnung = static function (mixed $liste, array $erlaubt, string $name) use ($text, $konto): array {
            $ergebnis = [];
            foreach (is_array($liste) ? $liste : [] as $id => $wert) {
                // An id deleted meanwhile (or made up) is dropped before its
                // value is looked at - it has no field on the page to fix.
                $id = (int) $id;
                if (!in_array($id, $erlaubt, true)) {
                    continue;
                }
                $wert = $konto($text($wert), $name . '[' . $id . ']');
                if ($wert !== '') {
                    $ergebnis[$id] = $wert;
                }
            }
            ksort($ergebnis);

            return $ergebnis;
        };

        return new self(
            beraternummer: $berater,
            mandantennummer: $mandant,
            sachkontenlaenge: $laenge,
            kontenrahmen: $rahmen,
            klaerungskonto: $konto($text($felder['klaerungskonto'] ?? null), 'klaerungskonto'),
            kategorien: $zuordnung($felder['kategorie'] ?? null, $kategorieIds, 'kategorie'),
            konten: $zuordnung($felder['konto'] ?? null, $kontoIds, 'konto'),
            geldtransitkonto: $konto($text($felder['geldtransitkonto'] ?? null), 'geldtransitkonto'),
        );
    }

    public function speichern(SettingRepository $settings): void
    {
        $settings->set(self::SETTING, json_encode([
            'beraternummer' => $this->beraternummer,
            'mandantennummer' => $this->mandantennummer,
            'sachkontenlaenge' => $this->sachkontenlaenge,
            'kontenrahmen' => $this->kontenrahmen,
            'klaerungskonto' => $this->klaerungskonto,
            // Objects, so ids stay keys after a round trip.
            'kategorien' => (object) $this->kategorien,
            'konten' => (object) $this->konten,
            'geldtransitkonto' => $this->geldtransitkonto,
        ], JSON_THROW_ON_ERROR));
    }

    /** Advisor and client number are what DATEV needs to take the file at all. */
    public function istVollstaendig(): bool
    {
        return $this->beraternummer !== '' && $this->mandantennummer !== '';
    }

    /** Null while the advisor or client number is missing. */
    public function kopf(\DateTimeImmutable $von, \DateTimeImmutable $bis, \DateTimeImmutable $erzeugt): ?DatevKopf
    {
        return $this->istVollstaendig()
            ? new DatevKopf($this->beraternummer, $this->mandantennummer, $this->sachkontenlaenge, $this->kontenrahmen, $von, $bis, $erzeugt)
            : null;
    }

    /**
     * The ledger account a booking of this category goes to; a booking
     * without category goes to the clearing account, if one is set.
     */
    public function gegenkonto(?int $kategorieId): ?string
    {
        if ($kategorieId === null) {
            return $this->klaerungskonto === '' ? null : $this->klaerungskonto;
        }

        return $this->kategorien[$kategorieId] ?? null;
    }

    /**
     * The money-in-transit account: with it, each half of a transfer
     * between own accounts is booked on its own day against it; without
     * it, a transfer is one row (see App\Service\Export\DatevExport).
     */
    public function geldtransit(): ?string
    {
        return $this->geldtransitkonto === '' ? null : $this->geldtransitkonto;
    }

    public function finanzkonto(int $kontoId): ?string
    {
        return $this->konten[$kontoId] ?? null;
    }

    private static function istBeraternummer(string $wert): bool
    {
        return preg_match('/^\d{4,7}$/', $wert) === 1 && (int) $wert >= 1001;
    }

    private static function istMandantennummer(string $wert): bool
    {
        return preg_match('/^\d{1,5}$/', $wert) === 1 && (int) $wert >= 1;
    }

    private static function istKontenrahmen(string $wert): bool
    {
        return preg_match('/^\d{2}$/', $wert) === 1;
    }

    /** 1 to $laenge digits, not all zero - a ledger account (Sachkonto). */
    private static function istKonto(string $wert, int $laenge): bool
    {
        return preg_match('/^\d{1,' . $laenge . '}$/', $wert) === 1 && (int) $wert > 0;
    }
}
