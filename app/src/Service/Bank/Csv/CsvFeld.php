<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * What a column of a bank's CSV export can mean (docs/spec/
 * 04-bank-und-abgleich.md section 3). The value is the key in the mapping
 * JSON of `csv_profile`.
 *
 * Required: the booking date and the amount - either one signed amount
 * column or separate debit/credit columns ("Soll"/"Haben"). Everything else
 * is optional.
 */
enum CsvFeld: string
{
    case Buchungstag = 'buchungstag';
    case Valuta = 'valuta';
    case Betrag = 'betrag';
    case Soll = 'soll';
    case Haben = 'haben';
    case Waehrung = 'waehrung';
    case Name = 'name';
    case Iban = 'iban';
    case Bic = 'bic';
    case Verwendungszweck = 'verwendungszweck';
    case Eref = 'eref';
    case Mref = 'mref';
    case GlaeubigerId = 'glaeubiger_id';
    case Buchungstext = 'buchungstext';
    case Saldo = 'saldo';
    case Hinweis = 'hinweis';

    /**
     * Column names a bank typically uses for the field, normalised
     * (CsvProfil::normalisiere()). The mapping assistant suggests a field
     * for a column whose name is one of these - an exact match, so the
     * club's own account ("IBAN Auftragskonto") never passes for the
     * counterparty's.
     */
    private const array VORSCHLAEGE = [
        'buchungstag' => ['buchungstag', 'buchungsdatum', 'datum', 'buchung'],
        'valuta' => ['valutadatum', 'valuta', 'wertstellung', 'wertstellungsdatum', 'wertstellungstag'],
        'betrag' => ['betrag', 'umsatz', 'betrag (eur)', 'betrag in eur', 'betrag eur', 'amount'],
        'soll' => ['soll', 'belastung', 'abgang', 'ausgang'],
        'haben' => ['haben', 'gutschrift', 'zugang', 'eingang'],
        'waehrung' => ['waehrung', 'whrg', 'whrg.', 'currency'],
        'name' => ['beguenstigter/zahlungspflichtiger', 'name zahlungsbeteiligter', 'zahlungsbeteiligter',
            'empfaenger/zahlungspflichtiger', 'auftraggeber/empfaenger', 'empfaenger/auftraggeber', 'empfaenger', 'auftraggeber',
            'zahlungsempfaenger', 'zahlungspflichtiger', 'name'],
        'iban' => ['kontonummer/iban', 'iban zahlungsbeteiligter', 'iban', 'iban gegenkonto', 'gegenkonto'],
        'bic' => ['bic (swift-code)', 'bic (swift-code) zahlungsbeteiligter', 'bic zahlungsbeteiligter', 'bic'],
        'verwendungszweck' => ['verwendungszweck', 'buchungsdetails', 'zweck'],
        'eref' => ['kundenreferenz (end-to-end)', 'end-to-end-referenz', 'end-to-end referenz', 'eref'],
        'mref' => ['mandatsreferenz', 'mandatsreferenz (mref)', 'mref'],
        'glaeubiger_id' => ['glaeubiger id', 'glaeubiger-id', 'glaeubigerid', 'glaeubiger-identifikationsnummer', 'cred'],
        'buchungstext' => ['buchungstext', 'umsatzart', 'vorgang'],
        'saldo' => ['saldo nach buchung', 'saldo', 'kontostand'],
        'hinweis' => ['info', 'status'],
    ];

    public function label(): string
    {
        return match ($this) {
            self::Buchungstag => 'Buchungstag',
            self::Valuta => 'Valuta (Wertstellung)',
            self::Betrag => 'Betrag (mit Vorzeichen)',
            self::Soll => 'Soll (Ausgang)',
            self::Haben => 'Haben (Eingang)',
            self::Waehrung => 'Währung',
            self::Name => 'Name Gegenseite',
            self::Iban => 'IBAN Gegenseite',
            self::Bic => 'BIC Gegenseite',
            self::Verwendungszweck => 'Verwendungszweck',
            self::Eref => 'End-to-End-Referenz (EREF)',
            self::Mref => 'Mandatsreferenz (MREF)',
            self::GlaeubigerId => 'Gläubiger-ID',
            self::Buchungstext => 'Buchungstext',
            self::Saldo => 'Saldo nach Buchung',
            self::Hinweis => 'Status/Info („vorgemerkt“)',
        };
    }

    /**
     * Text fields may take several columns ("Verwendungszweck 1", "... 2"),
     * joined with a space. The others read the first listed column the
     * file has - further names are aliases for header variants.
     */
    public function istText(): bool
    {
        return match ($this) {
            self::Name, self::Verwendungszweck, self::Buchungstext, self::Hinweis => true,
            default => false,
        };
    }

    public static function vorschlag(string $spalte): ?self
    {
        $name = CsvProfil::normalisiere($spalte);
        if (preg_match('/^verwendungszweck\s*\d+$/', $name) === 1) {
            return self::Verwendungszweck;
        }
        foreach (self::cases() as $feld) {
            if (in_array($name, self::VORSCHLAEGE[$feld->value], true)) {
                return $feld;
            }
        }

        return null;
    }
}
