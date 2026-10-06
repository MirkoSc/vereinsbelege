<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Domain\InvoiceDirection;

/**
 * The configurable folder and file name pattern of the ZIP export (issue
 * #75/M12-1, docs/spec/05-auswertung-und-export.md section 2), e.g.
 * `{lieferant}/{jahr}/{monatsname}/{lieferant} {datum}.pdf`.
 *
 * `/` separates the levels, the last level is the file name. A trailing
 * `.pdf` is optional: the extension comes from the exported file (an
 * original may be a JPEG). Placeholder values are cleaned one by one
 * (App\Service\Export\Dateiname), so a value never opens a level of its
 * own; a level that ends up empty (`{kasse}` of a receipt not paid in cash)
 * is dropped. Collisions are not this class's business - see
 * App\Service\Export\PfadVergabe.
 *
 * Pure: no database, no session, no vault - the values arrive decrypted.
 */
final readonly class PfadMuster
{
    /** The default: one folder per supplier, then year and month. */
    public const string STANDARD = '{lieferant}/{jahr}/{monatsname}/{lieferant} {datum}.pdf';

    /** Like the club's former manual archive: year and month first. */
    public const string ARCHIV = '{jahr}/{monatsname}/{lieferant} {datum}.pdf';

    /** As ARCHIV, cash receipts in a "Kasse" folder of their own. */
    public const string ARCHIV_KASSE = '{jahr}/{monatsname}/{kasse}/{lieferant} {datum}.pdf';

    /** The bundled patterns the admin page offers, label => pattern. */
    public const array VORLAGEN = [
        'Standard (nach Lieferant)' => self::STANDARD,
        'Wie das bisherige Archiv (nach Monat)' => self::ARCHIV,
        'Bisheriges Archiv mit Kasse-Ordner' => self::ARCHIV_KASSE,
    ];

    /** Placeholder => what the admin page says about it. */
    public const array PLATZHALTER = [
        'lieferant' => 'Name des Lieferanten bzw. Zahlers, sonst „_Ohne Lieferant“',
        'jahr' => 'Jahr des Belegdatums (2026)',
        'monat' => 'Monat zweistellig (01)',
        'monatsname' => 'Monat mit Namen („01. Januar“)',
        'kasse' => '„Kasse“ bei Barbelegen, sonst leer – die Ebene entfällt dann',
        'richtung' => '„Ausgaben“ oder „Einnahmen“',
        'datum' => 'Belegdatum TT.MM.JJJJ',
        'datum_iso' => 'Belegdatum JJJJ-MM-TT',
        'kategorie' => 'Name der Kategorie, sonst „_Ohne Kategorie“',
        'nr' => 'Rechnungsnummer (kann leer sein)',
        'betrag' => 'Bruttobetrag („1.234,56“, andere Währung mit Kürzel)',
    ];

    public const string OHNE_LIEFERANT = '_Ohne Lieferant';
    public const string OHNE_KATEGORIE = '_Ohne Kategorie';

    /** File name used when the file level resolves to nothing at all. */
    public const string ERSATZ_DATEINAME = 'Beleg';

    public const int MAX_LAENGE = 300;

    private const array MONATE = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    /**
     * @param list<list<array{0: bool, 1: string}>> $ebenen per level the
     *        parts in order: [true, placeholder] or [false, literal text]
     */
    private function __construct(
        public string $muster,
        private array $ebenen,
    ) {
    }

    public static function standard(): self
    {
        return self::parse(self::STANDARD);
    }

    /**
     * @throws UngueltigesPfadMuster with a German message for the admin page
     */
    public static function parse(string $muster): self
    {
        $muster = trim($muster);
        if (!mb_check_encoding($muster, 'UTF-8')) {
            throw new UngueltigesPfadMuster('Das Muster enthält ungültige Zeichen.');
        }
        if ($muster === '') {
            throw new UngueltigesPfadMuster('Bitte ein Muster angeben.');
        }
        if (mb_strlen($muster) > self::MAX_LAENGE) {
            throw new UngueltigesPfadMuster(sprintf('Das Muster ist zu lang (höchstens %d Zeichen).', self::MAX_LAENGE));
        }
        if (str_contains($muster, '\\')) {
            throw new UngueltigesPfadMuster('Ordner bitte mit „/“ trennen, nicht mit „\\“.');
        }
        if (str_starts_with($muster, '/') || str_ends_with($muster, '/')) {
            throw new UngueltigesPfadMuster('Das Muster darf nicht mit „/“ beginnen oder enden – die letzte Ebene ist der Dateiname.');
        }

        $roheEbenen = explode('/', $muster);
        $letzte = array_key_last($roheEbenen);
        // The extension comes from the file; a written-out ".pdf" is decoration.
        $roheEbenen[$letzte] = (string) preg_replace('/\.pdf$/i', '', $roheEbenen[$letzte]);

        $ebenen = [];
        foreach ($roheEbenen as $index => $roh) {
            $ebenen[] = self::ebene($roh, $index === $letzte);
        }

        return new self($muster, $ebenen);
    }

    /**
     * The cleaned folder names and, last, the file name without extension.
     * Levels that resolve to nothing are left out; the file name never is
     * (ERSATZ_DATEINAME stands in).
     *
     * @return non-empty-list<string>
     */
    public function aufloesen(BelegPfadDaten $beleg): array
    {
        $werte = self::werte($beleg);
        $letzte = array_key_last($this->ebenen);

        $ordner = [];
        $datei = '';
        foreach ($this->ebenen as $index => $teile) {
            $text = '';
            foreach ($teile as [$istPlatzhalter, $inhalt]) {
                $text .= $istPlatzhalter ? Dateiname::zeichen($werte[$inhalt]) : $inhalt;
            }
            $segment = Dateiname::segment($text);

            if ($index === $letzte) {
                $datei = $segment === '' ? self::ERSATZ_DATEINAME : $segment;
            } elseif ($segment !== '') {
                $ordner[] = $segment;
            }
        }

        return [...$ordner, $datei];
    }

    /**
     * The value of every placeholder for one receipt, before cleaning.
     *
     * @return array<string, string>
     */
    public static function werte(BelegPfadDaten $beleg): array
    {
        $monat = (int) $beleg->datum->format('n');
        $lieferant = trim($beleg->lieferant ?? '');
        $kategorie = trim($beleg->kategorie ?? '');

        return [
            'lieferant' => $lieferant === '' ? self::OHNE_LIEFERANT : $lieferant,
            'jahr' => $beleg->datum->format('Y'),
            'monat' => $beleg->datum->format('m'),
            'monatsname' => sprintf('%02d. %s', $monat, self::MONATE[$monat]),
            'kasse' => $beleg->kasse ? 'Kasse' : '',
            'richtung' => match ($beleg->richtung) {
                InvoiceDirection::Ausgabe => 'Ausgaben',
                InvoiceDirection::Einnahme => 'Einnahmen',
            },
            'datum' => $beleg->datum->format('d.m.Y'),
            'datum_iso' => $beleg->datum->format('Y-m-d'),
            'kategorie' => $kategorie === '' ? self::OHNE_KATEGORIE : $kategorie,
            'nr' => trim($beleg->nr),
            'betrag' => self::betrag($beleg->betragCent, $beleg->waehrung),
        ];
    }

    /** "1.234,56" - integer arithmetic only, never float (CLAUDE.md section 5). */
    private static function betrag(int $cent, string $waehrung): string
    {
        $betrag = abs($cent);
        $text = ($cent < 0 ? '-' : '')
            . number_format(intdiv($betrag, 100), 0, ',', '.')
            . ',' . sprintf('%02d', $betrag % 100);
        $waehrung = strtoupper(trim($waehrung));

        return $waehrung === '' || $waehrung === 'EUR' ? $text : $text . ' ' . $waehrung;
    }

    /**
     * @return list<array{0: bool, 1: string}>
     */
    private static function ebene(string $roh, bool $istDatei): array
    {
        if (trim($roh) === '') {
            throw new UngueltigesPfadMuster($istDatei
                ? 'Die letzte Ebene (der Dateiname) ist leer.'
                : 'Das Muster enthält eine leere Ebene („//“).');
        }

        $teile = [];
        $hatPlatzhalter = false;
        foreach (preg_split('/(\{[^{}]*\})/', $roh, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $teil) {
            if (preg_match('/^\{([^{}]*)\}$/', $teil, $treffer) === 1) {
                $name = trim($treffer[1]);
                if (!array_key_exists($name, self::PLATZHALTER)) {
                    throw new UngueltigesPfadMuster(sprintf('Unbekannter Platzhalter „{%s}“.', $treffer[1]));
                }
                $teile[] = [true, $name];
                $hatPlatzhalter = true;
                continue;
            }

            if (str_contains($teil, '{') || str_contains($teil, '}')) {
                throw new UngueltigesPfadMuster('Geschweifte Klammern sind nur um Platzhalter erlaubt, z. B. „{jahr}“.');
            }
            if (preg_match('/[:*?"<>|\x00-\x1F\x7F]/u', $teil) === 1) {
                throw new UngueltigesPfadMuster('Das Muster enthält Zeichen, die in Datei- und Ordnernamen verboten sind (: * ? " < > |).');
            }
            $teile[] = [false, $teil];
        }

        if (!$hatPlatzhalter && Dateiname::segment($roh) === '') {
            throw new UngueltigesPfadMuster('Ebenen aus nur „.“ oder „..“ sind nicht erlaubt.');
        }

        return $teile;
    }
}
