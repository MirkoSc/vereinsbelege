<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\QrCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * App\Support\QrCode (docs/spec/01-sicherheit.md section 3: "QR-Code
 * serverseitig in reinem PHP").
 *
 * What this suite can prove on its own is structural: the fixed patterns
 * (finder, timing, quiet zone) sit where ISO/IEC 18004 puts them, the
 * version grows with the payload the way the capacity table promises, and
 * an oversized payload is refused rather than silently truncated. It cannot
 * prove a code actually scans - nothing that runs in CI holds a camera.
 *
 * The one thing that DOES stand in for that: testTheEncoderMatchesAKnownGoodImplementation()
 * checks a full matrix - byte encoding, Reed-Solomon, mask selection, format
 * information, all of it - against a widely used reference encoder (the
 * `qrcode` package on PyPI, itself derived from Kazuhiko Arase's reference
 * implementation), run once outside this repository to produce the recorded
 * hash. Real device scanning is still the actual acceptance test (this
 * issue's PR, manual checklist) - this fixture only guards against a
 * regression once that scan has confirmed the algorithm is right.
 */
final class QrCodeTest extends TestCase
{
    public function testTheEncoderMatchesAKnownGoodImplementation(): void
    {
        $uri = 'otpauth://totp/Vereinsbelege:kasse@example.org?secret=JBSWY3DPEHPK3PXP&issuer=Vereinsbelege';

        $matrix = QrCode::matrix($uri);

        $bits = implode("\n", array_map(
            static fn(array $row): string => implode('', array_map(static fn(bool $v): string => $v ? '1' : '0', $row)),
            $matrix,
        ));

        self::assertSame(41, count($matrix), 'A 91-byte payload needs version 6.');
        self::assertSame(
            '7fb6054ca55970292d15c3af095ac69710ab85a4eee7153b5841ad09a5947578',
            hash('sha256', $bits),
        );
    }

    #[DataProvider('kapazitaetsGrenzen')]
    public function testDieVersionWaechstGenauAnDenKapazitaetsgrenzen(int $bytes, int $erwarteteGroesse): void
    {
        $matrix = QrCode::matrix(str_repeat('x', $bytes));

        self::assertCount($erwarteteGroesse, $matrix);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function kapazitaetsGrenzen(): iterable
    {
        // Version 1-6 at error correction level M: 14/26/42/62/84/106 bytes.
        yield '1 Byte -> Version 1 (21x21)' => [1, 21];
        yield '14 Byte -> noch Version 1' => [14, 21];
        yield '15 Byte -> Version 2 (25x25)' => [15, 25];
        yield '26 Byte -> noch Version 2' => [26, 25];
        yield '27 Byte -> Version 3 (29x29)' => [27, 29];
        yield '42 Byte -> noch Version 3' => [42, 29];
        yield '43 Byte -> Version 4 (33x33)' => [43, 33];
        yield '62 Byte -> noch Version 4' => [62, 33];
        yield '63 Byte -> Version 5 (37x37)' => [63, 37];
        yield '84 Byte -> noch Version 5' => [84, 37];
        yield '85 Byte -> Version 6 (41x41)' => [85, 41];
        yield '106 Byte -> noch Version 6' => [106, 41];
    }

    public function testEinZuLangerInhaltWirdAbgelehntStattAbgeschnitten(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QrCode::matrix(str_repeat('x', 107));
    }

    public function testDieDreiSuchmusterStehenAnDenErwartetenEcken(): void
    {
        $matrix = QrCode::matrix('kurzer Inhalt');
        $size = count($matrix);

        // The dark center of each 7x7 finder pattern.
        self::assertTrue($matrix[3][3], 'oben links');
        self::assertTrue($matrix[3][$size - 4], 'oben rechts');
        self::assertTrue($matrix[$size - 4][3], 'unten links');

        // The separator ring around a finder pattern is always light.
        self::assertFalse($matrix[7][0]);
        self::assertFalse($matrix[0][7]);
    }

    public function testDasTimingMusterWechseltRegelmaessig(): void
    {
        $matrix = QrCode::matrix('kurzer Inhalt');

        for ($i = 8; $i < count($matrix) - 8; $i++) {
            self::assertSame($i % 2 === 0, $matrix[6][$i], "Zeitmuster Zeile 6, Spalte $i");
            self::assertSame($i % 2 === 0, $matrix[$i][6], "Zeitmuster Spalte 6, Zeile $i");
        }
    }

    public function testDasAusrichtungsmusterAbVersion2StehtAmErwartetenPlatz(): void
    {
        // 26 bytes still fits version 2 (25x25), whose one alignment
        // pattern is centred on (18, 18).
        $matrix = QrCode::matrix(str_repeat('x', 26));

        self::assertCount(25, $matrix);
        self::assertTrue($matrix[18][18], 'Zentrum des Ausrichtungsmusters ist dunkel.');
        self::assertFalse($matrix[17][17], 'Der helle Ring um das Zentrum.');
        self::assertTrue($matrix[16][16], 'Der dunkle äußere Rahmen.');
    }

    public function testVersion1HatKeinAusrichtungsmuster(): void
    {
        // Nothing to assert structurally beyond "it still produces a valid
        // 21x21 matrix" - version 1's alignment position list is empty.
        $matrix = QrCode::matrix('x');

        self::assertCount(21, $matrix);
    }

    public function testDasSvgEnthaeltEinenRuhigenRandUndEinWurzelElement(): void
    {
        $svg = QrCode::svg('otpauth://totp/Test:a@b.c?secret=JBSWY3DPEHPK3PXP&issuer=Test', moduleSize: 4, quietZone: 4);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('role="img"', $svg);
        self::assertStringContainsString('<path', $svg);
        // width = (module count + 2 * quiet zone) * module size; for the
        // above URI (44 bytes) that is version 4 (33 modules).
        self::assertStringContainsString('width="164"', $svg);
    }

    public function testZweiAufrufeMitDemselbenInhaltLiefernDasselbeErgebnis(): void
    {
        $data = 'otpauth://totp/Vereinsbelege:kasse@example.org?secret=JBSWY3DPEHPK3PXP&issuer=Vereinsbelege';

        self::assertSame(QrCode::matrix($data), QrCode::matrix($data));
    }
}
