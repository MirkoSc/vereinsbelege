<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Repository\SettingRepository;

/**
 * The knobs of the scoring receipt <-> booking (M10-2, issue #66, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Punktebewertung", E-35): points per
 * signal, the tolerances and the thresholds. Stored as one setting (JSON,
 * `abgleich_parameter`); whatever is missing or out of range there falls
 * back to the start value of the spec, so an old or broken setting never
 * makes the matching guess.
 */
final readonly class Abgleichsparameter
{
    public const string SETTING = 'abgleich_parameter';

    /** Points per signal - the start values of the spec table. */
    public const array PUNKTE_START = [
        Signal::BetragExakt->value => 45,
        Signal::BetragSkonto->value => 20,
        Signal::Rechnungsnummer->value => 40,
        Signal::Iban->value => 30,
        Signal::Mandat->value => 30,
        Signal::Name->value => 15,
        Signal::Kundennummer->value => 15,
        Signal::DatumImFenster->value => 10,
        Signal::DatumAusserhalb->value => -20,
        Signal::Erstattung->value => 60,
    ];

    /**
     * The other values: name => [start, min, max]. `vorschlag_ab` is not in
     * the spec table - below it a pair is not worth showing (E-35).
     */
    public const array WERTE = [
        'skonto_prozent' => [3, 0, 20],
        'name_aehnlichkeit_prozent' => [80, 50, 100],
        'fenster_vor_tage' => [5, 0, 365],
        'fenster_nach_tage' => [45, 0, 365],
        'auto_schwelle' => [85, 1, 500],
        'auto_abstand' => [20, 0, 500],
        'vorschlag_ab' => [40, 1, 500],
    ];

    public const int PUNKTE_MIN = -200;

    public const int PUNKTE_MAX = 200;

    /**
     * @param array<string, int> $punkte signal value => points, every signal present
     */
    public function __construct(
        public array $punkte = self::PUNKTE_START,
        public int $skontoProzent = 3,
        public int $nameAehnlichkeitProzent = 80,
        public int $fensterVorTage = 5,
        public int $fensterNachTage = 45,
        public int $autoSchwelle = 85,
        public int $autoAbstand = 20,
        public int $vorschlagAb = 40,
    ) {
    }

    public function punkte(Signal $signal): int
    {
        return $this->punkte[$signal->value] ?? self::PUNKTE_START[$signal->value];
    }

    public static function fromSettings(SettingRepository $settings): self
    {
        $roh = json_decode($settings->get(self::SETTING, '{}'), true);

        return self::ausWerten(is_array($roh) ? $roh : []);
    }

    /**
     * Everything usable from $werte (ints in range), the start value for the
     * rest.
     *
     * @param array<mixed> $werte
     */
    public static function ausWerten(array $werte): self
    {
        $punkte = [];
        foreach (self::PUNKTE_START as $signal => $start) {
            $wert = $werte['punkte'][$signal] ?? null;
            $punkte[$signal] = is_int($wert) && $wert >= self::PUNKTE_MIN && $wert <= self::PUNKTE_MAX ? $wert : $start;
        }
        $zahl = static function (string $name) use ($werte): int {
            [$start, $min, $max] = self::WERTE[$name];
            $wert = $werte[$name] ?? null;

            return is_int($wert) && $wert >= $min && $wert <= $max ? $wert : $start;
        };

        return new self(
            $punkte,
            $zahl('skonto_prozent'),
            $zahl('name_aehnlichkeit_prozent'),
            $zahl('fenster_vor_tage'),
            $zahl('fenster_nach_tage'),
            $zahl('auto_schwelle'),
            $zahl('auto_abstand'),
            $zahl('vorschlag_ab'),
        );
    }

    /**
     * The form of the matching page: each field a whole number in range.
     *
     * @param array<string, string> $felder `punkte_<signal>` and the names of WERTE
     *
     * @throws BankRuleViolation naming the first bad field
     */
    public static function ausFormular(array $felder): self
    {
        $werte = ['punkte' => []];
        foreach (array_keys(self::PUNKTE_START) as $signal) {
            $werte['punkte'][$signal] = self::ganzzahl($felder, 'punkte_' . $signal, self::PUNKTE_MIN, self::PUNKTE_MAX);
        }
        foreach (self::WERTE as $name => [, $min, $max]) {
            $werte[$name] = self::ganzzahl($felder, $name, $min, $max);
        }

        return self::ausWerten($werte);
    }

    /**
     * @return array<string, mixed>
     */
    public function alsWerte(): array
    {
        return [
            'punkte' => $this->punkte,
            'skonto_prozent' => $this->skontoProzent,
            'name_aehnlichkeit_prozent' => $this->nameAehnlichkeitProzent,
            'fenster_vor_tage' => $this->fensterVorTage,
            'fenster_nach_tage' => $this->fensterNachTage,
            'auto_schwelle' => $this->autoSchwelle,
            'auto_abstand' => $this->autoAbstand,
            'vorschlag_ab' => $this->vorschlagAb,
        ];
    }

    public function speichern(SettingRepository $settings): void
    {
        $settings->set(self::SETTING, json_encode($this->alsWerte(), JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $felder
     */
    private static function ganzzahl(array $felder, string $name, int $min, int $max): int
    {
        $text = trim($felder[$name] ?? '');
        if (preg_match('/^-?\d{1,4}$/', $text) !== 1 || (int) $text < $min || (int) $text > $max) {
            throw new BankRuleViolation(sprintf('Bitte eine ganze Zahl von %d bis %d eingeben.', $min, $max), $name);
        }

        return (int) $text;
    }
}
