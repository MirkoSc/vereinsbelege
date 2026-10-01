<?php

declare(strict_types=1);

/*
 * Writes the synthetic MT940 fixtures next to this script (issue #60/M9-2):
 *
 *   php tests/fixtures/mt940/erzeuge.php
 *
 * Everything in here is made up - accounts, names, creditor IDs, amounts
 * (CLAUDE.md section 1: no real receipt data in the repo). The files copy
 * how the two house banks lay out their exports, which is what the parser
 * has to cope with:
 *
 * - sparkasse.sta: "BLZ/Kontonummer" in :25:, two statements, :86: as one
 *   long string cut every 65 characters regardless of subfields.
 * - vrbank.sta: IBAN in :25:, a statement across New Year, :86: with every
 *   "?NN" subfield on its own line, one unstructured :86:.
 *
 * Both are Windows-1252 with CRLF, as the banks deliver them. Purpose and
 * name subfields are cut into 27-character pieces like the banks do. The
 * expected values in tests/Service/Bank/Mt940ParserTest.php are written
 * by hand, not derived from this script.
 */

/**
 * @param list<string> $zweck    purpose segments, each starting a new subfield ("SVWZ+...")
 * @param array<int, string> $einzeln ?00, ?10, ?30, ?31, ?34
 */
function feld86(string $gvc, array $einzeln, array $zweck, string $name): string
{
    $teile = [];
    foreach ([0, 10] as $nr) {
        if (isset($einzeln[$nr])) {
            $teile[] = sprintf('?%02d%s', $nr, $einzeln[$nr]);
        }
    }

    $nummern = [...range(20, 29), ...range(60, 63)];
    foreach ($zweck as $segment) {
        foreach (mb_str_split($segment, 27) as $stueck) {
            $teile[] = sprintf('?%02d%s', array_shift($nummern), $stueck);
        }
    }

    foreach ([30, 31] as $nr) {
        if (isset($einzeln[$nr])) {
            $teile[] = sprintf('?%02d%s', $nr, $einzeln[$nr]);
        }
    }
    foreach (mb_str_split($name, 27) as $i => $stueck) {
        $teile[] = sprintf('?%02d%s', 32 + $i, $stueck);
    }
    if (isset($einzeln[34])) {
        $teile[] = '?34' . $einzeln[34];
    }

    return $gvc . implode('', $teile);
}

/** Sparkasse: the whole field cut every 65 characters. */
function umbrechen65(string $zeile): string
{
    return implode("\n", mb_str_split($zeile, 65));
}

/** VR Bank: a new line before every subfield. */
function jeTeilfeld(string $zeile): string
{
    return implode("\n", preg_split('/(?=\?\d{2})/', $zeile) ?: []);
}

function schreibe(string $datei, string $text): void
{
    $crlf = str_replace("\n", "\r\n", $text);
    file_put_contents(__DIR__ . '/' . $datei, mb_convert_encoding($crlf, 'Windows-1252', 'UTF-8'));
}

$sparkasse = [
    '',
    ':20:STARTUMSE',
    ':25:12345678/0001234567',
    ':28C:00012/001',
    ':60F:C240311EUR1523,40',
    ':61:2403110311DR45,90N005NONREF',
    umbrechen65(':86:' . feld86('105', [0 => 'FOLGELASTSCHRIFT', 10 => '931', 30 => 'GENODEF1XXX', 31 => 'DE40120505550001234567', 34 => '992'], [
        'EREF+RE-2024-0311-77',
        'MREF+M-0815',
        'CRED+DE98ZZZ09999999999',
        'SVWZ+Getränke Müller Rechnung 4711 Vereinsheim Kd-Nr 1234',
    ], 'Getränke Müller GmbH & Co. KG Musterstadt')),
    ':61:2403110311CR250,00N166NONREF',
    umbrechen65(':86:' . feld86('166', [0 => 'GUTSCHR. UEBERWEISUNG', 10 => '804', 30 => 'BYLADEM1XXX', 31 => 'DE23120906400007654321'], [
        'EREF+NOTPROVIDED',
        'SVWZ+Spende Jugendabteilung Sommerfest 2024',
    ], 'Schäfer, Jürgen')),
    ':61:2403110311DR120,00N116NONREF',
    umbrechen65(':86:' . feld86('116', [0 => 'ONLINE-UEBERWEISUNG', 10 => '9310', 30 => 'COBADEFFXXX', 31 => 'DE52120300001111111111'], [
        'EREF+UEB-2024-031',
        'SVWZ+Übungsleiterpauschale März 2024 Turnen Kinder',
        'ABWA+Förderverein Sportfreunde',
    ], 'Weiß, Anna-Lena')),
    ':62F:C240311EUR1607,50',
    '-',
    ':20:STARTUMSE',
    ':25:12345678/0001234567',
    ':28C:00013/001',
    ':60F:C240312EUR1607,50',
    ':61:2403120312RD45,90N109NONREF',
    umbrechen65(':86:' . feld86('109', [0 => 'RUECKLASTSCHRIFT', 10 => '931', 30 => 'GENODEF1XXX', 31 => 'DE40120505550001234567'], [
        'EREF+RE-2024-0311-77',
        'MREF+M-0815',
        'CRED+DE98ZZZ09999999999',
        'SVWZ+Rücklastschrift Getränke Müller Rechnung 4711',
    ], 'Getränke Müller GmbH & Co. KG Musterstadt')),
    ':61:2403120312DR3,50N805NONREF',
    umbrechen65(':86:' . feld86('805', [0 => 'ENTGELTABSCHLUSS', 10 => '6200'], [
        'Entgelt Kontoführung',
        'Abrechnung 03/2024',
    ], '')),
    ':62F:C240312EUR1649,90',
    '-',
];

$vrbank = [
    ':20:STARTUMS',
    ':25:DE93876543210007654321',
    ':28C:00052/001',
    ':60F:C231229EUR812,05',
    ':61:2312291229CR75,00NTRFNONREF',
    jeTeilfeld(':86:' . feld86('166', [0 => 'GUTSCHRIFT', 10 => '0599', 30 => 'GENODED1XXX', 31 => 'DE36120700000002222222'], [
        'EREF+NOTPROVIDED',
        'SVWZ+Mitgliedsbeitrag 2024 Max Mustermann Jugend U13',
    ], 'Mustermann, Erika')),
    ':61:2401021229DR19,99NDDTKREF-4711//0815',
    jeTeilfeld(':86:' . feld86('105', [0 => 'BASISLASTSCHRIFT', 10 => '0599', 30 => 'DEUTDEFFXXX', 31 => 'DE54120500000003333333'], [
        'EREF+TK-2023-12-998877',
        'MREF+TEL-000123',
        'CRED+DE11ZZZ00000000001',
        'SVWZ+Telefon Vereinsheim 12/2023 Kundennr. 0815',
        'ABWE+Sportheim Förderverein',
    ], 'Telefonanbieter Muster AG')),
    ':61:2312310102DR9,95NMSCNONREF',
    '/OCMT/EUR9,95/',
    ':86:Kartenzahlung Sporthaus Größe',
    '42 Trikots Jugend',
    ':61:2401020102RC75,00NTRFNONREF',
    jeTeilfeld(':86:' . feld86('159', [0 => 'RUECKUEBERWEISUNG', 10 => '0599', 30 => 'GENODED1XXX', 31 => 'DE36120700000002222222'], [
        'EREF+NOTPROVIDED',
        'SVWZ+Rückgabe Mitgliedsbeitrag 2024 doppelt gezahlt',
    ], 'Mustermann, Erika')),
    ':62F:C240102EUR782,11',
    '-',
];

schreibe('sparkasse.sta', implode("\n", $sparkasse) . "\n");
schreibe('vrbank.sta', implode("\n", $vrbank) . "\n");
