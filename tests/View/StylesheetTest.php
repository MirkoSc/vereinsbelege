<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;

/**
 * The handwritten stylesheet (E-07: no CSS build, no framework).
 *
 * Only the properties that silently break something are pinned here. Looks
 * are checked in a browser; a test that asserted paddings would only make
 * every design change a two-file change.
 */
final class StylesheetTest extends TestCase
{
    private static function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css');
    }

    /**
     * Light and dark are an acceptance criterion of milestone M1-3. Both
     * come from the same token set, so a colour that exists in only one of
     * them is the failure mode - unreadable text in whichever theme was not
     * looked at.
     */
    public function testEveryColourTokenExistsInBothThemes(): void
    {
        $css = self::css();

        preg_match('/:root\s*\{(.*?)\}/s', $css, $hell);
        preg_match('/@media \(prefers-color-scheme: dark\).*?:root\s*\{(.*?)\}/s', $css, $dunkel);

        self::assertNotEmpty($hell, 'no :root token block');
        self::assertNotEmpty($dunkel, 'no dark theme block');

        preg_match_all('/(--farbe-[a-z0-9-]+):/', $hell[1], $tokenHell);
        preg_match_all('/(--farbe-[a-z0-9-]+):/', $dunkel[1], $tokenDunkel);

        self::assertNotSame([], $tokenHell[1]);
        self::assertSame(
            [],
            array_values(array_diff($tokenHell[1], $tokenDunkel[1])),
            'colour tokens without a dark value',
        );
    }

    /**
     * Without color-scheme the browser renders its own widgets - scrollbars,
     * date pickers, the select drop-down - in the light palette on a dark
     * page.
     */
    public function testTheDocumentDeclaresBothColourSchemes(): void
    {
        self::assertMatchesRegularExpression('/color-scheme:\s*light dark/', self::css());
    }

    /**
     * The CSP allows no external host (CLAUDE.md section 4), and a web font
     * loaded from a foreign origin would tell its provider about every page
     * view of an application that shows decrypted receipts.
     */
    public function testTheStylesheetLoadsNothingFromOutside(): void
    {
        $css = self::css();

        self::assertDoesNotMatchRegularExpression('#url\(\s*[\'"]?https?://#i', $css);
        self::assertStringNotContainsString('@import', $css);
    }

    /**
     * htmx would otherwise inject its indicator styles as a <style> element,
     * which style-src 'self' drops without a word - the spinner would simply
     * never appear (E-07).
     */
    public function testTheIndicatorStylesAreShippedByHand(): void
    {
        self::assertStringContainsString('.htmx-indicator', self::css());
        self::assertStringContainsString('.htmx-request .htmx-indicator', self::css());
    }

    /**
     * The regression this once caused: .knopf sets display:inline-flex, and
     * any author display rule beats the browser default for [hidden] - the
     * "Update starten" button reappeared while it was supposed to be hidden.
     */
    public function testHiddenElementsStayHidden(): void
    {
        self::assertMatchesRegularExpression(
            '/\[hidden\]\s*\{\s*display:\s*none\s*!important/',
            self::css(),
        );
    }

    /**
     * Mobile first is an acceptance criterion: the base rules have to hold
     * at 360 px, and media queries may only add to them. A max-width query
     * would mean the narrow case is the exception.
     */
    public function testWidthQueriesOnlyAddToTheNarrowCase(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/@media[^{]*max-width/i',
            self::css(),
            'mobile first: width queries are min-width',
        );
    }
}
