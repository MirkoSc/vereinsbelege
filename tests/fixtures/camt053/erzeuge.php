<?php

declare(strict_types=1);

/*
 * Writes the synthetic CAMT.053 fixtures next to this script (issue #91):
 *
 *   php tests/fixtures/camt053/erzeuge.php
 *
 * Everything in here is made up - accounts, names, creditor IDs, amounts
 * (CLAUDE.md section 1: no real receipt data in the repo). The files follow
 * ISO 20022 and the German DK conventions (proprietary transaction code
 * "NTRF+166+931", PRCD as opening balance), in three versions of the
 * message, because banks still send all of them:
 *
 * - sparkasse-053-001-02.xml: camt.053.001.02 with the same two statements
 *   as tests/fixtures/mt940/sparkasse.sta - a switch from MT940 to CAMT for
 *   the same period must find every booking already there.
 * - vrbank-053-001-08.xml: camt.053.001.08 (status as <Cd>, parties as
 *   <Pty>, BICFI, date-times), with a pending entry, a reversal, a batch,
 *   a structured reference and a purpose cut at 140 characters.
 * - tagesauszuege-053-001-04.zip: two daily statements as separate files
 *   (camt.053.001.04), stored newest first, plus files a ZIP from a bank
 *   portal or macOS may carry that are not statements.
 *
 * The expected values in tests/Service/Bank/Camt053ParserTest.php are
 * written by hand, not derived from this script.
 */

const KOPF = '<?xml version="1.0" encoding="UTF-8"?>';

function dokument(string $version, string $inhalt): string
{
    $ns = 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.' . $version;

    return KOPF . "\n" . '<Document xmlns="' . $ns . '" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
        . "  <BkToCstmrStmt>\n"
        . "    <GrpHdr><MsgId>MSG-" . $version . "-0001</MsgId><CreDtTm>2024-03-13T06:00:00+01:00</CreDtTm></GrpHdr>\n"
        . $inhalt
        . "  </BkToCstmrStmt>\n</Document>\n";
}

function x(string $text): string
{
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function saldo(string $art, string $betrag, string $datum, string $ccy = 'EUR'): string
{
    [$ind, $wert] = str_starts_with($betrag, '-') ? ['DBIT', substr($betrag, 1)] : ['CRDT', $betrag];

    return "      <Bal><Tp><CdOrPrtry><Cd>$art</Cd></CdOrPrtry></Tp><Amt Ccy=\"$ccy\">$wert</Amt><CdtDbtInd>$ind</CdtDbtInd><Dt><Dt>$datum</Dt></Dt></Bal>\n";
}

/**
 * @param array<string, mixed> $e
 */
function eintrag(array $e, bool $v08): string
{
    $sts = $v08 ? '<Sts><Cd>' . ($e['sts'] ?? 'BOOK') . '</Cd></Sts>' : '<Sts>' . ($e['sts'] ?? 'BOOK') . '</Sts>';
    $buchung = isset($e['bookgDtTm']) ? '<BookgDt><DtTm>' . $e['bookgDtTm'] . '</DtTm></BookgDt>' : '<BookgDt><Dt>' . $e['bookgDt'] . '</Dt></BookgDt>';
    $s = "      <Ntry>\n"
        . '        <Amt Ccy="EUR">' . $e['amt'] . '</Amt><CdtDbtInd>' . $e['ind'] . '</CdtDbtInd>'
        . (isset($e['rvsl']) ? '<RvslInd>true</RvslInd>' : '') . $sts . "\n"
        . '        ' . $buchung . '<ValDt><Dt>' . ($e['valDt'] ?? $e['bookgDt'] ?? '') . '</Dt></ValDt>'
        . (isset($e['ref']) ? '<AcctSvcrRef>' . x($e['ref']) . '</AcctSvcrRef>' : '') . "\n"
        . '        <BkTxCd><Domn><Cd>PMNT</Cd><Fmly><Cd>' . ($e['fmly'] ?? 'RCDT') . '</Cd><SubFmlyCd>' . ($e['sub'] ?? 'ESCT') . '</SubFmlyCd></Fmly></Domn>'
        . '<Prtry><Cd>' . x($e['prtry']) . "</Cd><Issr>DK</Issr></Prtry></BkTxCd>\n";
    $txs = $e['tx'] ?? [];
    if ($txs !== []) {
        $s .= "        <NtryDtls>\n";
        if (count($txs) > 1) {
            $s .= '          <Btch><NbOfTxs>' . count($txs) . "</NbOfTxs></Btch>\n";
        }
        foreach ($txs as $tx) {
            $s .= tx($tx, $v08);
        }
        $s .= "        </NtryDtls>\n";
    }
    if (isset($e['info'])) {
        $s .= '        <AddtlNtryInf>' . x($e['info']) . "</AddtlNtryInf>\n";
    }

    return $s . "      </Ntry>\n";
}

/**
 * @param array<string, mixed> $tx
 */
function tx(array $tx, bool $v08): string
{
    $partei = static function (string $rolle, ?string $name, ?string $id = null) use ($v08): string {
        if ($name === null) {
            return '';
        }
        $innen = '<Nm>' . x($name) . '</Nm>' . ($id === null ? '' : '<Id><PrvtId><Othr><Id>' . $id . '</Id><SchmeNm><Prtry>SEPA</Prtry></SchmeNm></Othr></PrvtId></Id>');

        return "<$rolle>" . ($v08 ? "<Pty>$innen</Pty>" : $innen) . "</$rolle>";
    };
    $bic = $v08 ? 'BICFI' : 'BIC';

    $refs = '<Refs>' . (isset($tx['acctSvcrRef']) ? '<AcctSvcrRef>' . x($tx['acctSvcrRef']) . '</AcctSvcrRef>' : '')
        . '<EndToEndId>' . x($tx['e2e'] ?? 'NOTPROVIDED') . '</EndToEndId>'
        . (isset($tx['mndt']) ? '<MndtId>' . x($tx['mndt']) . '</MndtId>' : '') . '</Refs>';

    $parteien = $partei('Dbtr', $tx['dbtr'] ?? null)
        . (isset($tx['dbtrIban']) ? '<DbtrAcct><Id><IBAN>' . $tx['dbtrIban'] . '</IBAN></Id></DbtrAcct>' : '')
        . $partei('UltmtDbtr', $tx['ultmtDbtr'] ?? null)
        . $partei('Cdtr', $tx['cdtr'] ?? null, $tx['cred'] ?? null)
        . (isset($tx['cdtrIban']) ? '<CdtrAcct><Id><IBAN>' . $tx['cdtrIban'] . '</IBAN></Id></CdtrAcct>' : '')
        . $partei('UltmtCdtr', $tx['ultmtCdtr'] ?? null);
    $agenten = (isset($tx['dbtrBic']) ? "<DbtrAgt><FinInstnId><$bic>" . $tx['dbtrBic'] . "</$bic></FinInstnId></DbtrAgt>" : '')
        . (isset($tx['cdtrBic']) ? "<CdtrAgt><FinInstnId><$bic>" . $tx['cdtrBic'] . "</$bic></FinInstnId></CdtrAgt>" : '');

    $rmt = '';
    if (isset($tx['ustrd']) || isset($tx['strd'])) {
        $rmt = '<RmtInf>';
        foreach ($tx['ustrd'] ?? [] as $u) {
            $rmt .= '<Ustrd>' . x($u) . '</Ustrd>';
        }
        if (isset($tx['strd'])) {
            $rmt .= '<Strd><CdtrRefInf><Tp><CdOrPrtry><Cd>SCOR</Cd></CdOrPrtry></Tp><Ref>' . x($tx['strd']) . '</Ref></CdtrRefInf></Strd>';
        }
        $rmt .= '</RmtInf>';
    }

    return "          <TxDtls>\n            $refs\n"
        . ($parteien === '' ? '' : "            <RltdPties>$parteien</RltdPties>\n")
        . ($agenten === '' ? '' : "            <RltdAgts>$agenten</RltdAgts>\n")
        . ($rmt === '' ? '' : "            $rmt\n")
        . "          </TxDtls>\n";
}

/**
 * @param list<array<string, mixed>> $eintraege
 */
function auszug(string $id, string $nummer, string $iban, string $salden, array $eintraege, bool $v08 = false): string
{
    $s = "    <Stmt>\n      <Id>$id</Id><ElctrncSeqNb>$nummer</ElctrncSeqNb><CreDtTm>2024-03-13T06:00:00+01:00</CreDtTm>\n"
        . "      <Acct><Id><IBAN>$iban</IBAN></Id><Ccy>EUR</Ccy><Svcr><FinInstnId><" . ($v08 ? 'BICFI' : 'BIC') . '>TESTDEFFXXX</' . ($v08 ? 'BICFI' : 'BIC') . "></FinInstnId></Svcr></Acct>\n"
        . $salden;
    foreach ($eintraege as $e) {
        $s .= eintrag($e, $v08);
    }

    return $s . "    </Stmt>\n";
}

// --- Sparkasse, camt.053.001.02: the statements of sparkasse.sta ---------

$sparkasseIban = 'DE09123456780001234567';
$mueller = [
    'cdtr' => 'Getränke Müller GmbH & Co. KG Musterstadt', 'cdtrIban' => 'DE40120505550001234567', 'cdtrBic' => 'GENODEF1XXX',
    'cred' => 'DE98ZZZ09999999999', 'mndt' => 'M-0815', 'e2e' => 'RE-2024-0311-77',
];

$sparkasse = dokument('02', auszug('2024031100012', '12', $sparkasseIban, saldo('PRCD', '1523.40', '2024-03-08') . saldo('CLBD', '1607.50', '2024-03-11'), [
    ['amt' => '45.90', 'ind' => 'DBIT', 'bookgDt' => '2024-03-11', 'prtry' => 'NDDT+105+931', 'fmly' => 'IDDT', 'sub' => 'ESDD', 'ref' => '2024031100001',
        'info' => 'FOLGELASTSCHRIFT',
        'tx' => [$mueller + ['ustrd' => ['Getränke Müller Rechnung 4711 Vereinsheim Kd-Nr 1234']]]],
    ['amt' => '250.00', 'ind' => 'CRDT', 'bookgDt' => '2024-03-11', 'prtry' => 'NTRF+166+804', 'info' => 'GUTSCHR. UEBERWEISUNG',
        'tx' => [['dbtr' => 'Schäfer, Jürgen', 'dbtrIban' => 'DE23120906400007654321', 'dbtrBic' => 'BYLADEM1XXX',
            'ustrd' => ['Spende Jugendabteilung Sommerfest 2024']]]],
    ['amt' => '120.00', 'ind' => 'DBIT', 'bookgDt' => '2024-03-11', 'prtry' => 'NTRF+116+9310', 'fmly' => 'ICDT', 'info' => 'ONLINE-UEBERWEISUNG',
        'tx' => [['e2e' => 'UEB-2024-031', 'cdtr' => 'Weiß, Anna-Lena', 'cdtrIban' => 'DE52120300001111111111', 'cdtrBic' => 'COBADEFFXXX',
            'ultmtDbtr' => 'Förderverein Sportfreunde', 'ustrd' => ['Übungsleiterpauschale März 2024 Turnen Kinder']]]],
]) . auszug('2024031200013', '13', $sparkasseIban, saldo('PRCD', '1607.50', '2024-03-11') . saldo('CLBD', '1649.90', '2024-03-12'), [
    ['amt' => '45.90', 'ind' => 'CRDT', 'rvsl' => true, 'bookgDt' => '2024-03-12', 'prtry' => 'NRTI+109+931', 'fmly' => 'IDDT', 'sub' => 'UPDD',
        'info' => 'RUECKLASTSCHRIFT',
        'tx' => [$mueller + ['ustrd' => ['Rücklastschrift Getränke Müller Rechnung 4711']]]],
    ['amt' => '3.50', 'ind' => 'DBIT', 'bookgDt' => '2024-03-12', 'prtry' => 'NCHG+805+6200', 'fmly' => 'CCRD', 'sub' => 'CHRG',
        'info' => 'ENTGELTABSCHLUSS',
        'tx' => [['ustrd' => ['Entgelt Kontoführung', 'Abrechnung 03/2024']]]],
]));

// --- VR Bank, camt.053.001.08 ------------------------------------------------

$vrIban = 'DE93876543210007654321';
$lang = str_pad('Mitgliedsbeitraege 2024 Familie Mustermann Max Erika Paul Jugend U13 U15 und Erwachsene Breitensport laut Aufnahmeantrag vom 01.12.2023 Nr', 140, 'X');
$vrbank = dokument('08', auszug('STMT-2024-0001', '1', $vrIban, saldo('OPBD', '812.05', '2023-12-29') . saldo('CLBD', '822.06', '2024-01-02'), [
    ['amt' => '75.00', 'ind' => 'CRDT', 'bookgDtTm' => '2023-12-29T09:30:00+01:00', 'valDt' => '2023-12-29', 'prtry' => 'NTRF+166+0599', 'info' => 'GUTSCHRIFT',
        'tx' => [['acctSvcrRef' => 'VR-0001', 'dbtr' => 'Mustermann, Erika', 'dbtrIban' => 'DE36120700000002222222', 'dbtrBic' => 'GENODED1XXX',
            'ustrd' => ['Mitgliedsbeitrag 2024 Max Mustermann Jugend U13']]]],
    ['amt' => '19.99', 'ind' => 'DBIT', 'bookgDt' => '2024-01-02', 'valDt' => '2023-12-29', 'prtry' => 'NDDT+105+0599', 'fmly' => 'IDDT', 'sub' => 'ESDD', 'info' => 'BASISLASTSCHRIFT',
        'tx' => [['e2e' => 'TK-2023-12-998877', 'mndt' => 'TEL-000123', 'cdtr' => 'Telefonanbieter Muster AG', 'cred' => 'DE11ZZZ00000000001',
            'cdtrIban' => 'DE54120500000003333333', 'cdtrBic' => 'DEUTDEFFXXX', 'ultmtCdtr' => 'Sportheim Förderverein',
            'ustrd' => ['Telefon Vereinsheim 12/2023 Kundennr. 0815']]]],
    ['amt' => '500.00', 'ind' => 'CRDT', 'sts' => 'PDNG', 'bookgDt' => '2024-01-03', 'prtry' => 'NTRF+166+0599',
        'tx' => [['dbtr' => 'Sponsor Muster GmbH', 'dbtrIban' => 'DE36120700000002222222', 'ustrd' => ['Sponsoring 2024']]]],
    ['amt' => '75.00', 'ind' => 'DBIT', 'rvsl' => true, 'bookgDt' => '2024-01-02', 'prtry' => 'NRTI+159+0599', 'info' => 'RUECKUEBERWEISUNG',
        'tx' => [['dbtr' => 'Mustermann, Erika', 'dbtrIban' => 'DE36120700000002222222', 'dbtrBic' => 'GENODED1XXX',
            'ustrd' => ['Rückgabe Mitgliedsbeitrag 2024 doppelt gezahlt']]]],
    ['amt' => '30.00', 'ind' => 'DBIT', 'bookgDt' => '2024-01-02', 'prtry' => 'NTRF+191+0599', 'fmly' => 'ICDT', 'info' => 'SAMMEL-UEBERWEISUNG',
        'tx' => [
            ['e2e' => 'START-A', 'cdtr' => 'Turnverein Musterdorf', 'cdtrIban' => 'DE52120300001111111111', 'ustrd' => ['Startgeld Turnier A']],
            ['e2e' => 'START-B', 'cdtr' => 'SV Beispielstadt', 'cdtrIban' => 'DE23120906400007654321', 'ustrd' => ['Startgeld Turnier B']],
        ]],
    ['amt' => '50.00', 'ind' => 'CRDT', 'bookgDt' => '2024-01-02', 'prtry' => 'NTRF+166+0599', 'info' => 'GUTSCHRIFT',
        'tx' => [['dbtr' => 'Muster Immobilien KG', 'dbtrIban' => 'DE54120500000003333333', 'strd' => 'RF18539007547034']]],
    ['amt' => '10.00', 'ind' => 'CRDT', 'bookgDt' => '2024-01-02', 'prtry' => 'NTRF+166+0599', 'info' => 'GUTSCHRIFT',
        'tx' => [['dbtr' => 'Mustermann, Erika', 'dbtrIban' => 'DE36120700000002222222', 'ustrd' => [$lang, 'Ende']]]],
], true));

// --- Daily statements in a ZIP, camt.053.001.04 ------------------------------

$zipIban = 'DE41500105170123456789';
$tag1 = dokument('04', auszug('TAG-20240402', '64', $zipIban, saldo('PRCD', '-20.00', '2024-03-28') . saldo('CLBD', '80.00', '2024-04-02'), [
    ['amt' => '100.00', 'ind' => 'CRDT', 'bookgDt' => '2024-04-02', 'prtry' => 'NTRF+166+931', 'info' => 'GUTSCHRIFT',
        'tx' => [['dbtr' => 'Kreissportbund Muster', 'dbtrIban' => 'DE23120906400007654321', 'ustrd' => ['Zuschuss Übungsleiter Q1 2024']]]],
]));
$tag2 = dokument('04', auszug('TAG-20240403', '65', $zipIban, saldo('PRCD', '80.00', '2024-04-02') . saldo('CLBD', '55.50', '2024-04-03'), [
    ['amt' => '24.50', 'ind' => 'DBIT', 'bookgDt' => '2024-04-03', 'prtry' => 'NTRF+116+931', 'fmly' => 'ICDT', 'info' => 'ONLINE-UEBERWEISUNG',
        'tx' => [['e2e' => 'UEB-0403', 'cdtr' => 'Sportartikel Muster', 'cdtrIban' => 'DE52120300001111111111', 'ustrd' => ['Baelle Rechnung 2024-117']]]],
]));

file_put_contents(__DIR__ . '/sparkasse-053-001-02.xml', $sparkasse);
file_put_contents(__DIR__ . '/vrbank-053-001-08.xml', $vrbank);

$zipPfad = __DIR__ . '/tagesauszuege-053-001-04.zip';
if (is_file($zipPfad)) {
    unlink($zipPfad);
}
$zip = new ZipArchive();
$zip->open($zipPfad, ZipArchive::CREATE);
foreach ([
    'Auszuege/2024-04-03_Kontoauszug.XML' => $tag2,
    'Auszuege/2024-04-02_Kontoauszug.xml' => $tag1,
    'Auszuege/Liesmich.txt' => "Kontoauszüge im Format CAMT.053\n",
    '__MACOSX/Auszuege/._2024-04-02_Kontoauszug.xml' => "\0\5\26\7 Mac OS X metadata",
] as $name => $inhalt) {
    $zip->addFromString($name, $inhalt);
    $zip->setMtimeName($name, mktime(12, 0, 0, 4, 3, 2024));
}
$zip->close();
