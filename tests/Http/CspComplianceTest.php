<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Content-Security-Policy the docroot .htaccess sends
 * (CLAUDE.md section 4). The policy restricts scripts to same-origin files,
 * which only holds as long as no template reintroduces an inline <script> or
 * an inline event handler.
 *
 * That failure mode is the reason this is a test rather than a review item: a
 * browser enforcing script-src silently DROPS inline handlers. The page keeps
 * rendering, the button keeps looking clickable - the delete confirmation
 * just never appears and the form submits straight through. Nothing in the
 * app would notice.
 */
final class CspComplianceTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private static function viewFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::repoRoot() . '/app/views', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function htaccess(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/docker/web/.htaccess');
    }

    private static function csp(): string
    {
        preg_match('/Content-Security-Policy "([^"]+)"/', self::htaccess(), $m);
        self::assertNotEmpty($m, 'no Content-Security-Policy header in docker/web/.htaccess');

        return $m[1];
    }

    public function testThereAreViewsToCheck(): void
    {
        self::assertNotSame([], self::viewFiles(), 'the scan below would pass vacuously');
    }

    /**
     * Inline handlers (onclick, onsubmit, ...) are dead under script-src.
     * htmx's hx-on is the same thing in a different spelling and is ruled out
     * by CLAUDE.md section 4 for exactly this reason.
     */
    public function testNoViewUsesAnInlineEventHandler(): void
    {
        $treffer = [];
        foreach (self::viewFiles() as $file) {
            $inhalt = (string) file_get_contents($file);
            if (preg_match_all('/\son[a-z]+\s*=\s*"/i', $inhalt, $m) > 0) {
                $treffer[] = basename($file) . ': ' . implode(', ', $m[0]);
            }
            if (preg_match_all('/\shx-on[:a-z-]*\s*=\s*"/i', $inhalt, $m) > 0) {
                $treffer[] = basename($file) . ': ' . implode(', ', $m[0]);
            }
        }

        self::assertSame(
            [],
            $treffer,
            "Inline event handlers are silently disabled by script-src.\n"
            . 'Use a listener in public/js/ instead.',
        );
    }

    /**
     * <script> with a body is blocked too. type="application/json" is fine -
     * it is data the page reads, never executed.
     */
    public function testNoViewEmbedsAnExecutableInlineScript(): void
    {
        $treffer = [];
        foreach (self::viewFiles() as $file) {
            preg_match_all('/<script(?<attrs>[^>]*)>/i', (string) file_get_contents($file), $m);
            foreach ($m['attrs'] as $attrs) {
                if (str_contains($attrs, 'src=') || str_contains($attrs, 'application/json')) {
                    continue;
                }
                $treffer[] = basename($file);
            }
        }

        self::assertSame(
            [],
            $treffer,
            "Inline <script> is blocked by script-src 'self'. Move it to public/js/.",
        );
    }

    public function testPolicyRestrictsScriptsToSameOrigin(): void
    {
        $csp = self::csp();

        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString(
            'unsafe-eval',
            $csp,
            'htmx does not need it, and a scanner bundle that demanded it '
            . 'would be the wrong library for a page handling receipt data',
        );

        preg_match('/script-src ([^;]+)/', $csp, $m);
        self::assertStringNotContainsString('unsafe-inline', $m[1] ?? '');
    }

    /**
     * Styles too: the CSS is handwritten and served from public/css/, so
     * unlike the calendar this application has no reason to allow inline
     * styles anywhere. Pinned before the design system arrives (M1-3), while
     * it is still free to keep it that way.
     */
    public function testPolicyAllowsNoInlineStylesEither(): void
    {
        preg_match('/style-src ([^;]+)/', self::csp(), $m);

        self::assertNotEmpty($m, 'no style-src directive');
        self::assertStringNotContainsString('unsafe-inline', $m[1]);
    }

    public function testPolicyKeepsTheDirectivesThatNeedNoScriptChanges(): void
    {
        $csp = self::csp();

        foreach ([
            "default-src 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ] as $direktive) {
            self::assertStringContainsString($direktive, $csp);
        }
    }

    /**
     * No external host anywhere: every JS library is vendored into
     * public/js/vendor/ (CLAUDE.md section 4). A CDN would also mean the
     * provider sees every request of a page that shows decrypted receipts.
     */
    public function testPolicyNamesNoExternalHost(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '#https?://#i',
            self::csp(),
            'the CSP must not list an external origin',
        );
    }

    /**
     * The scanner (milestone M5) renders camera frames and pdf.js pages into
     * a canvas and previews them via createObjectURL before anything is
     * uploaded. Without blob: the preview is silently blank - the sort of
     * failure only a real browser catches, so it is pinned here.
     */
    public function testPolicyAllowsLocallyGeneratedImagesForTheScanner(): void
    {
        preg_match('/img-src ([^;]+)/', self::csp(), $m);

        self::assertNotEmpty($m, 'no img-src directive');
        self::assertStringContainsString('blob:', $m[1]);
    }

    /**
     * The security headers only exist if the host evaluates .htaccess at all.
     * The M0 check verified it does; this keeps the header set itself from
     * drifting.
     */
    public function testTheSecurityHeadersAreAllPresent(): void
    {
        $htaccess = self::htaccess();

        foreach ([
            'X-Content-Type-Options',
            'Referrer-Policy',
            'Content-Security-Policy',
            'Strict-Transport-Security',
        ] as $header) {
            self::assertStringContainsString($header, $htaccess);
        }
    }
}
