<?php

declare(strict_types=1);

namespace App\Tests\Service\Export\Nextcloud;

use App\Service\Export\Nextcloud\NextcloudAblage;
use PHPUnit\Framework\TestCase;

final class NextcloudAblageVarianteTest extends TestCase
{
    public function testTheSuffixGoesBeforeTheExtensionLikeInTheZip(): void
    {
        self::assertSame('Belege_2026/a/Bauhaus 03.02.2026.pdf', NextcloudAblage::variante('Belege_2026/a/Bauhaus 03.02.2026.pdf', 1));
        self::assertSame('Belege_2026/a/Bauhaus 03.02.2026 (2).pdf', NextcloudAblage::variante('Belege_2026/a/Bauhaus 03.02.2026.pdf', 2));
        self::assertSame('Belege_2026/index (3).csv', NextcloudAblage::variante('Belege_2026/index.csv', 3));
        self::assertSame('Belege_2026/v1.2/ohne (2)', NextcloudAblage::variante('Belege_2026/v1.2/ohne', 2), 'a dot in a folder is no extension');
        self::assertSame('.hidden (2)', NextcloudAblage::variante('.hidden', 2));
    }

    public function testTheStemGivesWayToTheSuffixLikeInTheZip(): void
    {
        $lang = str_repeat('a', 80);

        self::assertSame('x/' . str_repeat('a', 76) . ' (2).pdf', NextcloudAblage::variante('x/' . $lang . '.pdf', 2));
    }
}
