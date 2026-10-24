<?php

declare(strict_types=1);

namespace App\Tests\Service\Suche;

use App\Service\Suche\Suchanfrage;
use App\Service\Suche\Suchtext;
use PHPUnit\Framework\TestCase;

/**
 * Parsing, matching and the highlighted excerpt of the full-text search
 * (issue #94).
 */
final class SuchanfrageTest extends TestCase
{
    private const string TEXT = 'Rechnung Nr. 4711 – Sporthaus Müller GmbH, Trikots E-Jugend, Summe 1.234,50 EUR';

    public function testEveryTermMustOccur(): void
    {
        $text = Suchtext::fuerIndex([self::TEXT]);

        self::assertTrue(Suchanfrage::aus('müller trikots')->passt($text));
        self::assertTrue(Suchanfrage::aus('MÜLLER   4711')->passt($text));
        self::assertFalse(Suchanfrage::aus('müller bälle')->passt($text));
    }

    public function testQuotesKeepAPhraseTogether(): void
    {
        $text = Suchtext::fuerIndex([self::TEXT]);

        self::assertSame(['sporthaus müller'], Suchanfrage::aus('"Sporthaus Müller"')->begriffe);
        self::assertTrue(Suchanfrage::aus('"sporthaus müller"')->passt($text));
        self::assertFalse(Suchanfrage::aus('"müller sporthaus"')->passt($text));
        // An unclosed quote is just a character.
        self::assertSame(['trikots'], Suchanfrage::aus('"trikots')->begriffe);
    }

    public function testShortDuplicateAndSurplusTermsAreDropped(): void
    {
        self::assertTrue(Suchanfrage::aus('a " "')->leer());
        self::assertSame(['ab'], Suchanfrage::aus('ab AB x')->begriffe);
        self::assertCount(Suchanfrage::MAX_BEGRIFFE, Suchanfrage::aus('aa bb cc dd ee ff gg hh ii jj')->begriffe);
        self::assertFalse(Suchanfrage::aus('')->passt('irgendwas'), 'an empty search finds nothing');
    }

    public function testInputIsCutAndKeptForTheForm(): void
    {
        $anfrage = Suchanfrage::aus(str_repeat('ü', 500));

        self::assertSame(Suchanfrage::MAX_ZEICHEN, mb_strlen($anfrage->eingabe));
        self::assertSame('Trikots  "E-Jugend"', Suchanfrage::aus('  Trikots  "E-Jugend"  ')->eingabe);
    }

    public function testTheExcerptMarksEveryTermAroundTheFirstMatch(): void
    {
        $text = Suchtext::fuerIndex([str_repeat('füllwort ', 30) . self::TEXT . str_repeat(' nachwort', 30)]);

        $segmente = Suchanfrage::aus('trikots müller')->ausschnitt($text);

        self::assertSame(['…', false], $segmente[0], 'cut at the start');
        self::assertSame(['…', false], $segmente[array_key_last($segmente)], 'cut at the end');
        $markiert = array_values(array_map(static fn(array $s): string => $s[0], array_filter($segmente, static fn(array $s): bool => $s[1])));
        self::assertSame(['müller', 'trikots'], $markiert);
        $ganz = implode('', array_map(static fn(array $s): string => $s[0], $segmente));
        self::assertTrue(mb_check_encoding($ganz, 'UTF-8'));
        self::assertStringContainsString('sporthaus müller gmbh, trikots', $ganz);
        self::assertLessThan(260, strlen($ganz));
    }

    public function testShortTextIsShownWhole(): void
    {
        self::assertSame(
            [['beleg ', false], ['42', true]],
            Suchanfrage::aus('42')->ausschnitt('beleg 42'),
        );
        self::assertSame([], Suchanfrage::aus('fehlt')->ausschnitt('beleg 42'));
    }

    public function testMultibyteCharactersAreNeverSplit(): void
    {
        $text = Suchtext::fuerIndex([str_repeat('ä', 100) . ' ziel ' . str_repeat('ö', 200)]);

        foreach (Suchanfrage::aus('ziel')->ausschnitt($text) as [$stueck]) {
            self::assertTrue(mb_check_encoding($stueck, 'UTF-8'), bin2hex($stueck));
        }
    }
}
