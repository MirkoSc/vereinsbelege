<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Domain\ImapSicherheit;
use App\Service\Worker\MailAbsenderListe;
use App\Service\Worker\MailEingangEinstellungen;
use PHPUnit\Framework\TestCase;

/**
 * The mail intake's admin form and sender allowlist (issue #87, E-54).
 */
final class MailEingangEinstellungenTest extends TestCase
{
    /** @return array<string, string> */
    private static function formular(array $anders = []): array
    {
        return $anders + [
            'aktiv' => '1',
            'host' => 'imap.verein.de',
            'sicherheit' => 'starttls',
            'port' => '',
            'benutzer' => 'belege@verein.de',
            'passwort' => 'geheim',
            'ordner' => '',
            'ziel_ordner' => 'Erledigt',
            'absender' => "Kasse@Verein.de, @stadtwerke.de\n",
            'abruf_minuten' => '',
        ];
    }

    public function testAValidFormWithDefaults(): void
    {
        [$e, $fehler] = MailEingangEinstellungen::ausFormular(self::formular(), new MailEingangEinstellungen());

        self::assertSame([], $fehler);
        self::assertNotNull($e);
        self::assertSame(ImapSicherheit::Starttls, $e->sicherheit);
        self::assertSame(143, $e->port, 'the usual port of the encryption');
        self::assertSame('INBOX', $e->ordner);
        self::assertSame(5, $e->abrufMinuten);
        self::assertSame(['kasse@verein.de', '@stadtwerke.de'], $e->absender->eintraege);
    }

    public function testThePortOfTheOtherEncryptionIsReplaced(): void
    {
        [$e] = MailEingangEinstellungen::ausFormular(self::formular(['port' => '993']), new MailEingangEinstellungen());
        self::assertSame(143, $e?->port, 'STARTTLS on the IMAPS port never works');

        [$e] = MailEingangEinstellungen::ausFormular(self::formular(['port' => '1143']), new MailEingangEinstellungen());
        self::assertSame(1143, $e?->port, 'any other port is kept');
    }

    public function testTheStartDayIsKeptWhileTheIntakeStaysOn(): void
    {
        $tag = new \DateTimeImmutable('2026-10-01');
        [$an] = MailEingangEinstellungen::ausFormular(self::formular(), new MailEingangEinstellungen(), $tag);
        self::assertSame('2026-10-01', $an?->aktivSeit);

        [$weiter] = MailEingangEinstellungen::ausFormular(self::formular(), $an, new \DateTimeImmutable('2026-10-20'));
        self::assertSame('2026-10-01', $weiter?->aktivSeit);

        [$aus] = MailEingangEinstellungen::ausFormular(self::formular(['aktiv' => '']), $weiter);
        self::assertNull($aus?->aktivSeit);
    }

    public function testThereIsNoPlaintextOption(): void
    {
        [$e, $fehler] = MailEingangEinstellungen::ausFormular(self::formular(['sicherheit' => 'keine']), new MailEingangEinstellungen());

        self::assertNull($e);
        self::assertArrayHasKey('sicherheit', $fehler);
    }

    public function testAnEmptyPasswordKeepsTheStoredOne(): void
    {
        $bisher = new MailEingangEinstellungen(passwort: 'alt');

        [$e] = MailEingangEinstellungen::ausFormular(self::formular(['passwort' => '']), $bisher);
        self::assertSame('alt', $e?->passwort);

        [$e, $fehler] = MailEingangEinstellungen::ausFormular(self::formular(['passwort' => '']), new MailEingangEinstellungen());
        self::assertNull($e, 'switching on needs a password');
        self::assertArrayHasKey('aktiv', $fehler);
    }

    public function testInvalidInputIsRefusedPerField(): void
    {
        [, $fehler] = MailEingangEinstellungen::ausFormular(self::formular([
            'host' => 'imaps://imap.verein.de',
            'port' => '70000',
            'ordner' => "INBOX\r\nA1 DELETE x",
            'ziel_ordner' => 'inbox',
            'absender' => 'kein-absender',
            'abruf_minuten' => '0',
        ]), new MailEingangEinstellungen());

        self::assertSame(['host', 'port', 'ordner', 'absender', 'abruf_minuten'], array_keys($fehler));

        [, $fehler] = MailEingangEinstellungen::ausFormular(self::formular(['ziel_ordner' => 'inbox']), new MailEingangEinstellungen());
        self::assertArrayHasKey('ziel_ordner', $fehler, 'not the folder that is read');
    }

    public function testTheAllowlistMatchesAddressesAndWholeDomains(): void
    {
        [$liste] = MailAbsenderListe::lesen("kasse@verein.de\n@stadtwerke.de");
        self::assertNotNull($liste);

        self::assertTrue($liste->erlaubt('Kasse@Verein.de'));
        self::assertTrue($liste->erlaubt('rechnung@stadtwerke.de'));
        self::assertFalse($liste->erlaubt('rechnung@netz.stadtwerke.de'), 'no subdomains');
        self::assertFalse($liste->erlaubt('vorstand@verein.de'));
        self::assertFalse($liste->erlaubt(null));
        self::assertTrue(new MailAbsenderListe()->erlaubt(null), 'an empty list lets everyone in');
        self::assertSame(['kasse@verein.de'], MailAbsenderListe::ausListe(['kasse@verein.de', 'kaputt', 7])->eintraege);
    }
}
