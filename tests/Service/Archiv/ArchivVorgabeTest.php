<?php

declare(strict_types=1);

namespace App\Tests\Service\Archiv;

use App\Domain\InvoiceDirection;
use App\Service\Archiv\ArchivVorgabe;
use PHPUnit\Framework\TestCase;

/**
 * The archive import's suggestion for the review page (issue #77/M12-3):
 * stored as `archiv-v1`, laid over the automatic reading with priority, a
 * hint wherever it replaces a read value. All names are made up.
 */
final class ArchivVorgabeTest extends TestCase
{
    private const array LEER = [
        'richtung' => 'ausgabe', 'datum' => '', 'nummer' => '', 'lieferant' => '', 'lieferant_neu_name' => '',
        'lieferant_neu_iban' => '', 'kategorie' => '', 'brutto' => '',
    ];

    public function testItSurvivesTheRoundTripThroughItsStoredForm(): void
    {
        $vorgabe = new ArchivVorgabe(
            pfad: 'Einnahmen/Max Mustermann 03.02.2026.pdf',
            lieferantId: 7,
            lieferantName: 'Max Mustermann',
            datum: new \DateTimeImmutable('2026-02-03'),
            nr: 'RE-1',
            kategorieId: 4,
            richtung: InvoiceDirection::Einnahme,
            richtungAusOrdner: true,
            kasse: true,
            wiedervorlage: true,
            hinweise: ['ein Hinweis'],
        );

        self::assertEquals($vorgabe, ArchivVorgabe::fromArray(json_decode((string) json_encode($vorgabe->toArray()), true)));
    }

    public function testAnythingElseIsNoArchivePayload(): void
    {
        self::assertNull(ArchivVorgabe::fromArray([]));
        self::assertNull(ArchivVorgabe::fromArray(['schema' => 'extract-v1', 'pfad' => 'x']));
    }

    public function testWithoutAnAutomaticReadingItOnlyFillsAndExplainsWhereFrom(): void
    {
        $vorgabe = new ArchivVorgabe(pfad: 'Bauhaus 03.02.2026.pdf', lieferantId: 3, lieferantName: 'Bauhaus', datum: new \DateTimeImmutable('2026-02-03'), kategorieId: 5);

        ['felder' => $felder, 'hinweise' => $hinweise] = $vorgabe->ueberlagern(self::LEER, self::LEER, 'E-Rechnung');

        self::assertSame('2026-02-03', $felder['datum']);
        self::assertSame('3', $felder['lieferant']);
        self::assertSame('5', $felder['kategorie']);
        self::assertSame(['Aus dem Archiv-Import: „Bauhaus 03.02.2026.pdf“.'], $hinweise);
    }

    public function testItWinsOverADifferentReadingAndSaysSo(): void
    {
        $gelesen = [...self::LEER, 'richtung' => 'ausgabe', 'datum' => '2026-02-04', 'nummer' => 'X-9', 'lieferant_neu_name' => 'Fremd GmbH', 'brutto' => '12,00'];
        $vorgabe = new ArchivVorgabe(
            pfad: 'Einnahmen/Max Mustermann 03.02.2026.pdf',
            lieferantId: 3,
            lieferantName: 'Max Mustermann',
            datum: new \DateTimeImmutable('2026-02-03'),
            nr: 'RE-1',
            richtung: InvoiceDirection::Einnahme,
            richtungAusOrdner: true,
        );

        ['felder' => $felder, 'hinweise' => $hinweise] = $vorgabe->ueberlagern(self::LEER, $gelesen, 'KI');

        self::assertSame('einnahme', $felder['richtung']);
        self::assertSame('2026-02-03', $felder['datum']);
        self::assertSame('RE-1', $felder['nummer']);
        self::assertSame('3', $felder['lieferant']);
        self::assertSame('', $felder['lieferant_neu_name']);
        self::assertSame('12,00', $felder['brutto'], 'what the archive does not know stays');
        self::assertSame([
            'Aus dem Archiv-Import: „Einnahmen/Max Mustermann 03.02.2026.pdf“.',
            'Archiv: Datum 03.02.2026, KI: 04.02.2026 – Archivwert übernommen.',
            'Archiv: Rechnungsnummer RE-1, KI: X-9 – Archivwert übernommen.',
            'Archiv: Zahler „Max Mustermann“, KI nennt einen anderen – Archivwert übernommen.',
        ], $hinweise, 'the direction was the default, not read - no hint for it');
    }

    public function testTheSameValueGivesNoHint(): void
    {
        $gelesen = [...self::LEER, 'datum' => '2026-02-03', 'lieferant' => '3'];
        $vorgabe = new ArchivVorgabe(pfad: 'a.pdf', lieferantId: 3, datum: new \DateTimeImmutable('2026-02-03'), kasse: true, hinweise: ['vom Pfad']);

        ['hinweise' => $hinweise] = $vorgabe->ueberlagern(self::LEER, $gelesen, 'E-Rechnung');

        self::assertSame(['Aus dem Archiv-Import: „a.pdf“.', 'Archiv: Ordner „Kasse“ – bar bezahlt.', 'vom Pfad'], $hinweise);
    }
}
