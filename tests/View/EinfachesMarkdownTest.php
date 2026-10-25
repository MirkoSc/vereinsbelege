<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\EinfachesMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text format of the club-maintained legal texts (issue #80/M13-3,
 * E-46). The texts come from an admin form and land on public pages: no
 * input may produce markup this class did not write itself.
 */
final class EinfachesMarkdownTest extends TestCase
{
    public function testHeadingsListsParagraphsAndLineBreaks(): void
    {
        $html = EinfachesMarkdown::html("## Kontakt\n\nMusterverein e. V.\nHauptstraße 1\n\n- eins\n- **zwei**\n\n### Klein\nText");

        self::assertSame(
            "<h3>Kontakt</h3>\n<p>Musterverein e. V.<br>\nHauptstraße 1</p>\n<ul>\n<li>eins</li>\n<li><strong>zwei</strong></li>\n</ul>\n<h4>Klein</h4>\n<p>Text</p>",
            $html,
        );
    }

    public function testCarriageReturnsAndAHashHeadingAreNormalised(): void
    {
        self::assertSame("<h3>Titel</h3>\n<p>a<br>\nb</p>", EinfachesMarkdown::html("# Titel\r\na\r\nb\r\n"));
    }

    public function testAllowedLinks(): void
    {
        self::assertSame(
            '<p><a href="/datenschutz">Datenschutz</a> und <a href="https://example.org/a?b=1&amp;c=2">extern</a></p>',
            EinfachesMarkdown::html('[Datenschutz](/datenschutz) und [extern](https://example.org/a?b=1&c=2)'),
        );
        self::assertSame(
            '<p><a href="mailto:vorstand@example.org">Mail</a> <a href="tel:+49123456">Tel</a></p>',
            EinfachesMarkdown::html('[Mail](mailto:vorstand@example.org) [Tel](tel:+49123456)'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function boeseEingaben(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'javascript link' => ['[klick](javascript:alert(1))'];
        yield 'javascript link upper case' => ['[klick](JavaScript:alert(1))'];
        yield 'data link' => ['[klick](data:text/html;base64,PHNjcmlwdD4=)'];
        yield 'protocol relative link' => ['[klick](//evil.example/x)'];
        yield 'backslash protocol relative link' => ['[klick](/\\evil.example/x)'];
        yield 'attribute breakout' => ['[klick](https://example.org/"onmouseover="alert(1))'];
        yield 'html in link text' => ['[<b>x</b>](https://example.org)'];
        yield 'html in heading' => ['## <iframe src="x"></iframe>'];
        yield 'html in list' => ['- <svg onload=alert(1)>'];
        yield 'html in bold' => ['**<script>x</script>**'];
        yield 'entity' => ['&lt;script&gt;'];
    }

    #[DataProvider('boeseEingaben')]
    public function testNoInputProducesForeignMarkup(string $eingabe): void
    {
        $html = EinfachesMarkdown::html($eingabe) . EinfachesMarkdown::html($eingabe, neuerTab: true);

        // Only the tags this class writes, and only href as attribute.
        $ohneEigene = preg_replace('~</?(p|br|h3|h4|ul|li|strong)>|<a href="[^"<>]*"( target="_blank" rel="noopener")?>|</a>~', '', $html);
        self::assertIsString($ohneEigene);
        self::assertStringNotContainsString('<', $ohneEigene, $html);
        self::assertStringNotContainsString('>', $ohneEigene, $html);
        self::assertDoesNotMatchRegularExpression('~href="(?!https?://|mailto:|tel:|/[^/\\\\])~i', $html);
    }

    public function testARejectedLinkStaysVisibleAsText(): void
    {
        self::assertSame(
            '<p>[klick](javascript:alert(1))</p>',
            EinfachesMarkdown::html('[klick](javascript:alert(1))'),
        );
    }

    public function testPlaceholdersAreFoundButLinksAreNot(): void
    {
        self::assertSame(
            ['[Name des Vereins]', '[Ort]'],
            EinfachesMarkdown::platzhalter("[Name des Vereins] in [Ort], [Ort]\n[Impressum](/impressum)"),
        );
        self::assertSame([], EinfachesMarkdown::platzhalter('Alles ausgefüllt. [Impressum](/impressum)'));
    }

    public function testLongPlaceholdersOfTheTemplatesAreFound(): void
    {
        $lang = '[Frist, z. B. 8 bzw. 10 Jahre nach Ende des Geschäftsjahres – mit dem Steuerberater zu klären, sehr ausführlich]';
        self::assertSame([$lang], EinfachesMarkdown::platzhalter('Aufbewahrung: ' . $lang));
    }

    public function testLinksCanOpenInANewTab(): void
    {
        self::assertSame(
            '<p><a href="/datenschutz" target="_blank" rel="noopener">Datenschutz</a></p>',
            EinfachesMarkdown::html('[Datenschutz](/datenschutz)', neuerTab: true),
        );
    }

    public function testEmptyTextGivesNoMarkup(): void
    {
        self::assertSame('', EinfachesMarkdown::html(" \n\n "));
    }
}
