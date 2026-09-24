<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;

/**
 * Vendored JavaScript libraries (public/js/vendor/).
 *
 * There is no build step and no package manager on this project: the files
 * are committed as they were downloaded, and nothing but this test would
 * notice if one of them were edited, replaced or half-updated. The checksums
 * in the README are the record of what was reviewed - if a file and its
 * entry disagree, one of the two was changed without the other.
 */
final class VendorAssetsTest extends TestCase
{
    private const string VENDOR_DIR = '/public/js/vendor';

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function readme(): string
    {
        return (string) file_get_contents(self::repoRoot() . self::VENDOR_DIR . '/README.md');
    }

    /**
     * @return list<string> paths relative to VENDOR_DIR, without the README -
     *         recursive, because pdf.js (issue #30/M4-8) ships as a small
     *         tree (`pdfjs/`, `pdfjs/wasm/`), not flat files like htmx
     */
    private static function libraries(): array
    {
        $wurzel = self::repoRoot() . self::VENDOR_DIR;
        $dateien = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $datei) {
            $name = $datei->getFilename();
            if (str_ends_with($name, '.js') || str_ends_with($name, '.mjs')) {
                $dateien[] = ltrim(str_replace($wurzel, '', $datei->getPathname()), '/');
            }
        }
        sort($dateien);

        return $dateien;
    }

    public function testHtmxIsVendored(): void
    {
        // Loaded by the layout on every page: if it is missing, every page
        // still renders and nothing works.
        self::assertFileExists(self::repoRoot() . self::VENDOR_DIR . '/htmx.min.js');
    }

    public function testPdfJsIsVendored(): void
    {
        // Loaded on demand by public/js/rasterung.js (issue #30/M4-8): if
        // it is missing, PDF rendering fails but nothing else does.
        self::assertFileExists(self::repoRoot() . self::VENDOR_DIR . '/pdfjs/pdf.min.mjs');
        self::assertFileExists(self::repoRoot() . self::VENDOR_DIR . '/pdfjs/pdf.worker.min.mjs');
    }

    public function testEveryVendoredFileIsDocumentedWithItsChecksum(): void
    {
        $readme = self::readme();

        foreach (self::libraries() as $datei) {
            $hash = hash_file('sha256', self::repoRoot() . self::VENDOR_DIR . '/' . $datei);

            self::assertStringContainsString($datei, $readme, $datei . ' is not documented');
            self::assertStringContainsString(
                (string) $hash,
                $readme,
                $datei . ' differs from the reviewed version recorded in ' . self::VENDOR_DIR . '/README.md',
            );
        }
    }

    public function testTheLicenceOfEveryLibraryIsRecorded(): void
    {
        // CLAUDE.md section 8: licence has to be GPL compatible, and the
        // check belongs in the repository, not in a pull request comment.
        self::assertStringContainsString('Lizenz', self::readme());
    }

    /**
     * A vendored file that loads something from a CDN would walk straight
     * around the CSP - and tell the provider about every page view of an
     * application that shows decrypted receipts.
     */
    public function testNoVendoredFileReachesOutToAnExternalHost(): void
    {
        foreach (self::libraries() as $datei) {
            $inhalt = (string) file_get_contents(self::repoRoot() . self::VENDOR_DIR . '/' . $datei);

            self::assertDoesNotMatchRegularExpression(
                '#sourceMappingURL=\s*https?://#i',
                $inhalt,
                $datei,
            );
            self::assertStringNotContainsString('cdn.jsdelivr.net', $inhalt, $datei);
            self::assertStringNotContainsString('unpkg.com', $inhalt, $datei);
        }
    }
}
