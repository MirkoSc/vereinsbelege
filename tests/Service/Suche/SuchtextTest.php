<?php

declare(strict_types=1);

namespace App\Tests\Service\Suche;

use App\Service\Suche\Suchtext;
use PHPUnit\Framework\TestCase;

/**
 * The normalisation both the session index and the search terms go through
 * (issue #94, docs/spec/03-erfassung-und-ki.md section 10).
 */
final class SuchtextTest extends TestCase
{
    public function testCaseAndWhitespaceDoNotMatter(): void
    {
        self::assertSame('sporthaus müller gmbh', Suchtext::normalisiere("  SPORTHAUS\tMÜLLER\n\fGmbH  "));
        self::assertSame('a b', Suchtext::normalisiere("a\u{00A0}\u{2009}b"));
    }

    public function testSoftHyphensZeroWidthAndControlCharactersGo(): void
    {
        self::assertSame('rechnung', Suchtext::normalisiere("Rech\u{00AD}nung"));
        self::assertSame('rechnung nr', Suchtext::normalisiere("\u{FEFF}Rech\u{200B}nung \u{200B} \x01Nr"));
    }

    public function testUmlautsStayUmlauts(): void
    {
        self::assertSame('straße äöü', Suchtext::normalisiere('Straße ÄÖÜ'));
    }

    public function testBrokenUtf8IsScrubbedNotLost(): void
    {
        $text = Suchtext::normalisiere("Beleg \xFF 42");

        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
        self::assertStringContainsString('beleg', $text);
        self::assertStringContainsString('42', $text);
    }

    public function testTextsOfOneDocumentAreJoinedAndCut(): void
    {
        self::assertSame('erste | zweite', Suchtext::fuerIndex(['Erste', '', '  ', 'ZWEITE']));

        $lang = Suchtext::fuerIndex([str_repeat('ä', Suchtext::MAX_BYTES_JE_DOKUMENT)]);
        self::assertLessThanOrEqual(Suchtext::MAX_BYTES_JE_DOKUMENT, strlen($lang));
        self::assertTrue(mb_check_encoding($lang, 'UTF-8'), 'cut on a character boundary');
    }
}
