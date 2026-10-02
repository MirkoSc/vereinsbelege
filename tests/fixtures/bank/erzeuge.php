<?php

declare(strict_types=1);

/**
 * Writes the synthetic CSV exports of tests/fixtures/bank/ (issue #61/M9-3).
 * Every account, name, IBAN, creditor id and amount is made up (CLAUDE.md
 * section 1, E-13). The IBANs are the well-known documentation examples.
 *
 *     php tests/fixtures/bank/erzeuge.php
 *
 * Kept next to the files so the encodings and line endings stay exactly as
 * the banks write them; tests/Service/Bank/CsvParserTest.php has the
 * expected values typed by hand, not derived from here.
 */

$verzeichnis = __DIR__;

/**
 * @param list<list<string>> $zeilen
 */
$csv = static function (array $zeilen, string $trenner, bool $alleInQuotes): string {
    $text = [];
    foreach ($zeilen as $zeile) {
        $text[] = implode($trenner, array_map(
            static fn (string $zelle): string => $alleInQuotes || preg_match('/["\r\n' . preg_quote($trenner, '/') . ']/', $zelle) === 1
                ? '"' . str_replace('"', '""', $zelle) . '"'
                : $zelle,
            $zeile,
        ));
    }

    return implode("\r\n", $text) . "\r\n";
};

$konto = 'DE89370400440532013000';

// --- Sparkasse CSV-CAMT V2: Windows-1252, ";", every cell quoted, TT.MM.JJ.
$sparkasseKopf = ['Auftragskonto', 'Buchungstag', 'Valutadatum', 'Buchungstext', 'Verwendungszweck', 'Glaeubiger ID',
    'Mandatsreferenz', 'Kundenreferenz (End-to-End)', 'Sammlerreferenz', 'Lastschrift Ursprungsbetrag',
    'Auslagenersatz Ruecklastschrift', 'Beguenstigter/Zahlungspflichtiger', 'Kontonummer/IBAN', 'BIC (SWIFT-Code)',
    'Betrag', 'Waehrung', 'Info'];
$sparkasse = [
    [$konto, '29.03.24', '29.03.24', 'FOLGELASTSCHRIFT', 'Platzmiete April', 'DE11ZZZ00000000001', 'PLATZ-07', 'E2E-PLATZ-2404', '', '', '',
        'Gemeinde Beispielstadt', 'DE68210501700012345678', 'NOLADE21XXX', '-120,00', 'EUR', 'Umsatz vorgemerkt'],
    [$konto, '28.03.24', '28.03.24', 'FOLGELASTSCHRIFT', 'Strom Abschlag Maerz Vertragskonto 4711', 'DE98ZZZ09999999999', 'MANDAT-0815',
        'E2E-STROM-2403', '', '', '', 'Stadtwerke Musterstadt GmbH', 'DE02120300000000202051', 'BYLADEM1001', '-89,00', 'EUR', 'Umsatz gebucht'],
    [$konto, '27.03.24', '27.03.24', 'GUTSCHR. UEBERWEISUNG', 'Mitgliedsbeitrag 2024 Jugend; Familie Müßig', '', '', 'NOTPROVIDED', '', '', '',
        'Jörg Müßig', 'DE12500105170648489890', 'INGDDEFFXXX', '1.250,00', 'EUR', 'Umsatz gebucht'],
    [$konto, '26.03.24', '26.03.24', 'KARTENZAHLUNG', "Getraenkemarkt Beispiel\r\nKasse 3", '', '', '', '', '', '',
        'Getränkemarkt Beispiel', '', '', '-45,90', 'EUR', 'Umsatz gebucht'],
    [$konto, '02.01.24', '29.12.23', 'ABSCHLUSS', 'Kontofuehrung 4. Quartal', '', '', '', '', '', '',
        '', '', '', '-7,50', 'EUR', 'Umsatz gebucht'],
];
file_put_contents(
    $verzeichnis . '/sparkasse-csv-camt-v2.csv',
    mb_convert_encoding($csv([$sparkasseKopf, ...$sparkasse], ';', true), 'Windows-1252', 'UTF-8'),
);

// --- Sparkasse CSV-CAMT V8 (assumed layout, see README.md): one more
// column at the end and four-digit years - otherwise the same file.
$v8 = array_map(
    static fn (array $zeile): array => [
        ...array_map(static fn (string $zelle): string => preg_replace('/^(\d\d\.\d\d\.)(\d\d)$/', '${1}20$2', $zelle) ?? $zelle, $zeile),
        '',
    ],
    $sparkasse,
);
$v8[1][17] = 'Energie';
file_put_contents(
    $verzeichnis . '/sparkasse-csv-camt-v8.csv',
    mb_convert_encoding($csv([[...$sparkasseKopf, 'Kategorie'], ...$v8], ';', true), 'Windows-1252', 'UTF-8'),
);

// --- VR Bank: UTF-8 with BOM, ";", TT.MM.JJJJ, thousands dots, balance
// after each booking (newest first: each balance minus its amount is the
// next row's balance).
$vrKopf = ['Bezeichnung Auftragskonto', 'IBAN Auftragskonto', 'BIC Auftragskonto', 'Bankname Auftragskonto', 'Buchungstag',
    'Valutadatum', 'Name Zahlungsbeteiligter', 'IBAN Zahlungsbeteiligter', 'BIC (SWIFT-Code) Zahlungsbeteiligter', 'Buchungstext',
    'Verwendungszweck', 'Betrag', 'Waehrung', 'Saldo nach Buchung', 'Bemerkung', 'Kategorie', 'Steuerrelevant', 'Glaeubiger ID',
    'Mandatsreferenz'];
$vrKonto = ['Vereinskonto', $konto, 'GENODEF1XXX', 'VR Bank Musterland eG'];
$vr = [
    [...$vrKonto, '28.03.2024', '28.03.2024', 'Sportartikel Beispiel GmbH', 'DE02120300000000202051', 'COBADEFFXXX', 'Überweisung',
        'RE 2024-117 Trikots E-Jugend', '-1.234,56', 'EUR', '3.765,44', '', '', '', '', ''],
    [...$vrKonto, '27.03.2024', '27.03.2024', 'Förderverein Beispiel e.V.', 'DE12500105170648489890', 'INGDDEFFXXX', 'Gutschrift',
        'Spende Flutlicht', '500,00', 'EUR', '5.000,00', '', '', '', '', ''],
    [...$vrKonto, '26.03.2024', '25.03.2024', 'Versicherung Beispiel AG', 'DE68210501700012345678', 'NOLADE21XXX', 'Basislastschrift',
        'Beitrag Haftpflicht 2024 Vertrag 99-1', '-35,00', 'EUR', '4.500,00', '', '', '', 'DE22ZZZ00000012345', 'VERS-4711'],
];
file_put_contents($verzeichnis . '/vrbank.csv', "\xEF\xBB\xBF" . $csv([$vrKopf, ...$vr], ';', false));

// --- Unknown bank: what the assistant has to learn. UTF-8, ",", a
// preamble, ISO dates, decimal point, separate debit/credit columns, two
// purpose columns and one row without an amount.
$unbekannt = [
    ['Kontoumsaetze Vereinskonto'],
    ['Zeitraum', '2024-03-01', '2024-03-31'],
    [''],
    ['Datum', 'Wertstellung', 'Empfaenger/Auftraggeber', 'Verwendungszweck 1', 'Verwendungszweck 2', 'Soll', 'Haben'],
    ['2024-03-05', '2024-03-05', 'Hallenbad Beispielstadt', 'Bahnmiete Training', 'Maerz', '1,020.00', ''],
    ['2024-03-07', '2024-03-07', 'Kreissportbund Beispiel', 'Zuschuss', 'Uebungsleiter', '', '750.50'],
    ['2024-03-09', '2024-03-08', 'Bäckerei Krümel, Inh. Test', 'Brötchen Turnier', '', '12.40', ''],
    ['2024-03-31', '2024-03-31', 'Kontoabschluss', '', '', '', ''],
];
file_put_contents($verzeichnis . '/unbekannt.csv', $csv($unbekannt, ',', false));

echo "Fixtures geschrieben.\n";
