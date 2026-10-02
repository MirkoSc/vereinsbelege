<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * The profiles that come with the application (docs/spec/
 * 04-bank-und-abgleich.md section 3: "Sparkasse CSV-CAMT" and "VR Bank
 * CSV-CAMT"). migrations/022_csv_profile.sql seeds exactly these rows;
 * tests/Integration/CsvProfileFlowTest.php checks the seed against this
 * class, so the two cannot drift apart.
 *
 * Both read the character set per file ("Automatisch") and take a two- or
 * four-digit year: the banks' export variants (Sparkasse CSV-CAMT V2/V8)
 * differ in exactly such details and in extra columns, which a mapping by
 * column name ignores.
 */
final class CsvStandardprofile
{
    public const string SPARKASSE = 'Sparkasse CSV-CAMT';
    public const string VR_BANK = 'VR Bank CSV-CAMT';

    /** Header of the Sparkasse export "CSV-CAMT V2" - its signature is stored. */
    public const array SPARKASSE_KOPF = ['Auftragskonto', 'Buchungstag', 'Valutadatum', 'Buchungstext', 'Verwendungszweck',
        'Glaeubiger ID', 'Mandatsreferenz', 'Kundenreferenz (End-to-End)', 'Sammlerreferenz', 'Lastschrift Ursprungsbetrag',
        'Auslagenersatz Ruecklastschrift', 'Beguenstigter/Zahlungspflichtiger', 'Kontonummer/IBAN', 'BIC (SWIFT-Code)',
        'Betrag', 'Waehrung', 'Info'];

    /** Header of the VR Bank (Atruvia) CSV export. */
    public const array VR_BANK_KOPF = ['Bezeichnung Auftragskonto', 'IBAN Auftragskonto', 'BIC Auftragskonto',
        'Bankname Auftragskonto', 'Buchungstag', 'Valutadatum', 'Name Zahlungsbeteiligter', 'IBAN Zahlungsbeteiligter',
        'BIC (SWIFT-Code) Zahlungsbeteiligter', 'Buchungstext', 'Verwendungszweck', 'Betrag', 'Waehrung',
        'Saldo nach Buchung', 'Bemerkung', 'Kategorie', 'Steuerrelevant', 'Glaeubiger ID', 'Mandatsreferenz'];

    private function __construct()
    {
        // Static definitions, no instances.
    }

    /**
     * @return list<CsvProfil>
     */
    public static function alle(): array
    {
        return [self::sparkasse(), self::vrBank()];
    }

    public static function sparkasse(): CsvProfil
    {
        return new CsvProfil(
            null,
            self::SPARKASSE,
            true,
            CsvTrennzeichen::Semikolon,
            CsvZeichensatz::Automatisch,
            CsvDatumsformat::Punkt,
            CsvDezimaltrenner::Komma,
            [
                'buchungstag' => ['Buchungstag'],
                'valuta' => ['Valutadatum'],
                'betrag' => ['Betrag'],
                'waehrung' => ['Waehrung'],
                'name' => ['Beguenstigter/Zahlungspflichtiger'],
                'iban' => ['Kontonummer/IBAN'],
                'bic' => ['BIC (SWIFT-Code)'],
                'verwendungszweck' => ['Verwendungszweck'],
                'eref' => ['Kundenreferenz (End-to-End)'],
                'mref' => ['Mandatsreferenz'],
                'glaeubiger_id' => ['Glaeubiger ID', 'Glaeubiger-ID'],
                'buchungstext' => ['Buchungstext'],
                'hinweis' => ['Info'],
            ],
            CsvProfil::signatur(self::SPARKASSE_KOPF),
        );
    }

    public static function vrBank(): CsvProfil
    {
        return new CsvProfil(
            null,
            self::VR_BANK,
            true,
            CsvTrennzeichen::Semikolon,
            CsvZeichensatz::Automatisch,
            CsvDatumsformat::Punkt,
            CsvDezimaltrenner::Komma,
            [
                'buchungstag' => ['Buchungstag'],
                'valuta' => ['Valutadatum'],
                'betrag' => ['Betrag'],
                'waehrung' => ['Waehrung'],
                'name' => ['Name Zahlungsbeteiligter'],
                'iban' => ['IBAN Zahlungsbeteiligter'],
                'bic' => ['BIC (SWIFT-Code) Zahlungsbeteiligter'],
                'verwendungszweck' => ['Verwendungszweck'],
                'mref' => ['Mandatsreferenz'],
                'glaeubiger_id' => ['Glaeubiger ID', 'Glaeubiger-ID'],
                'buchungstext' => ['Buchungstext'],
                'saldo' => ['Saldo nach Buchung'],
            ],
            CsvProfil::signatur(self::VR_BANK_KOPF),
        );
    }
}
