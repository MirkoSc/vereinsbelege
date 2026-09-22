<?php

declare(strict_types=1);

namespace App\Support;

/**
 * QR code encoder in pure PHP (docs/spec/01-sicherheit.md section 3:
 * "QR-Code serverseitig in reinem PHP"), for the TOTP setup page
 * (App\Service\Account\MfaEnrollment) - no vendored dependency, no GD, no
 * temporary file: the standard (ISO/IEC 18004) is small enough to implement
 * directly and cheap enough (one `otpauth://` URI, generated once per
 * enrollment) that a library buys nothing (CLAUDE.md section 8 - the same
 * argument App\Service\Mail\SmtpTransport already makes for hand-rolling a
 * small, exact protocol instead of adding a runtime dependency).
 *
 * Deliberately narrow: byte mode only, error correction level M, versions 1
 * through 6 (up to 106 bytes of payload). Version 7 and above additionally
 * require an 18-bit "version information" pattern printed twice into the
 * symbol - real extra surface for a mistake this class has no way to verify
 * against a real scanner, so it is left out and encode() throws instead of
 * silently producing a symbol nothing can read. In return, the setup page
 * always has a second way in: the secret is shown as plain Base32 text next
 * to the code (App\Service\Account\Totp::base32Encode()).
 *
 * The module-placement algorithm (finder/timing/alignment patterns, format
 * information, the zig-zag data walk and the eight mask patterns) follows
 * ISO/IEC 18004 directly. Because nothing here can be checked by actually
 * scanning a phone, every constant that matters was cross-checked against a
 * long-standing, widely used open source implementation (the `qrcode`
 * package on PyPI, itself derived from Kazuhiko Arase's reference encoder)
 * rather than taken from memory - the Reed-Solomon block table, the two BCH
 * generator polynomials and their masks, the mask formulas, and the mask
 * penalty rules. Scanning the rendered code with a real authenticator app is
 * the actual acceptance test (see the PR's manual test checklist), not a
 * substitute for it.
 */
final class QrCode
{
    /**
     * Per version 1-6, error correction level M: number of Reed-Solomon
     * blocks, total codewords per block, and data codewords per block (the
     * remainder is the EC codewords per block). None of these versions split
     * into two differently sized groups the way larger versions can.
     */
    private const array BLOCK_INFO = [
        1 => ['blocks' => 1, 'total' => 26, 'data' => 16],
        2 => ['blocks' => 1, 'total' => 44, 'data' => 28],
        3 => ['blocks' => 1, 'total' => 70, 'data' => 44],
        4 => ['blocks' => 2, 'total' => 50, 'data' => 32],
        5 => ['blocks' => 2, 'total' => 67, 'data' => 43],
        6 => ['blocks' => 4, 'total' => 43, 'data' => 27],
    ];

    /**
     * The single non-finder alignment pattern of versions 2-6 sits at
     * (position, position). Version 1 has none.
     */
    private const array ALIGNMENT_POSITION = [
        1 => null,
        2 => 18,
        3 => 22,
        4 => 26,
        5 => 30,
        6 => 34,
    ];

    private const int MODE_BYTE = 0b0100;

    /** The 2-bit error correction level indicator used in the format bits. */
    private const int EC_LEVEL_M = 0b00;

    /** BCH(15,5) generator for the 15-bit format information, ISO/IEC 18004 Annex C. */
    private const int FORMAT_GENERATOR = 0b10100110111;

    /** XOR mask applied to the raw format bits so an all-zero symbol is never valid. */
    private const int FORMAT_MASK = 0b101010000010010;

    private static ?array $expTable = null;

    private static ?array $logTable = null;

    /**
     * Renders the code as a self-contained inline SVG (no external asset, no
     * `data:` URI needed - the CSP already allows inline SVG markup the same
     * way the recovery key page does not need one).
     */
    public static function svg(string $data, int $moduleSize = 4, int $quietZone = 4): string
    {
        $matrix = self::encode($data);
        $size = count($matrix);
        $dimension = ($size + $quietZone * 2) * $moduleSize;

        $path = '';
        foreach ($matrix as $row => $cells) {
            foreach ($cells as $col => $dark) {
                if ($dark) {
                    $x = ($col + $quietZone) * $moduleSize;
                    $y = ($row + $quietZone) * $moduleSize;
                    $path .= sprintf('M%d %dh%dv%dh-%dz', $x, $y, $moduleSize, $moduleSize, -$moduleSize);
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%1$d" height="%1$d"'
            . ' role="img" aria-label="QR-Code zur Einrichtung des Authenticators">'
            . '<rect width="%1$d" height="%1$d" fill="#ffffff"/>'
            . '<path d="%2$s" fill="#000000"/>'
            . '</svg>',
            $dimension,
            $path,
        );
    }

    /**
     * The raw module matrix, exposed for the structural tests - true is dark.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $data): array
    {
        return self::encode($data);
    }

    /**
     * @return list<list<bool>>
     */
    private static function encode(string $data): array
    {
        $version = self::selectVersion(strlen($data));
        $codewords = self::buildCodewords($data, $version);
        $blank = self::blankMatrix($version);
        $size = count($blank);

        // Scored with the format-info strip blanked out (ISO/IEC 18004
        // section 8.8.2): those modules encode the mask pattern itself, so
        // scoring them for real would make the penalty rules partly judge
        // the mask by its own signature instead of by the data pattern it
        // produces. Only the winning mask gets its real format bits drawn,
        // once, below.
        $bestPattern = 0;
        $bestScore = null;
        for ($pattern = 0; $pattern < 8; $pattern++) {
            $matrix = $blank;
            self::placeFormatInfo($matrix, $size, $pattern, test: true);
            self::mapData($matrix, $size, $codewords, $pattern);
            $score = self::lostPoint($matrix, $size);

            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $bestPattern = $pattern;
            }
        }

        $matrix = $blank;
        self::placeFormatInfo($matrix, $size, $bestPattern);
        self::mapData($matrix, $size, $codewords, $bestPattern);

        /** @var list<list<bool>> $matrix */
        return $matrix;
    }

    private static function selectVersion(int $byteLength): int
    {
        foreach (self::BLOCK_INFO as $version => $info) {
            if ($byteLength <= self::capacity($info)) {
                return $version;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'QR payload of %d bytes exceeds what error correction level M supports up to version 6 (%d bytes).',
            $byteLength,
            self::capacity(self::BLOCK_INFO[6]),
        ));
    }

    /**
     * @param array{blocks: int, total: int, data: int} $info
     */
    private static function capacity(array $info): int
    {
        // Mode indicator (4 bits) + byte-mode character count (8 bits,
        // versions 1-9) leave this many whole bytes of the data codewords for
        // the payload itself.
        return intdiv($info['blocks'] * $info['data'] * 8 - 12, 8);
    }

    // ------------------------------------------------------- data encoding

    /**
     * @return list<int>
     */
    private static function buildCodewords(string $data, int $version): array
    {
        $info = self::BLOCK_INFO[$version];
        $bitLimit = $info['blocks'] * $info['data'] * 8;

        $bits = self::toBits(self::MODE_BYTE, 4) . self::toBits(strlen($data), 8);
        foreach (str_split($data) as $byte) {
            $bits .= self::toBits(ord($byte), 8);
        }

        $bits .= str_repeat('0', min($bitLimit - strlen($bits), 4));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        $padByte = 0;
        while (strlen($bits) < $bitLimit) {
            $bits .= self::toBits($padByte % 2 === 0 ? 0xEC : 0x11, 8);
            $padByte++;
        }

        $dataCodewords = array_map(bindec(...), str_split($bits, 8));

        return self::interleave($dataCodewords, $info);
    }

    private static function toBits(int $value, int $width): string
    {
        return str_pad(decbin($value), $width, '0', STR_PAD_LEFT);
    }

    /**
     * @param list<int> $dataCodewords
     * @param array{blocks: int, total: int, data: int} $info
     * @return list<int>
     */
    private static function interleave(array $dataCodewords, array $info): array
    {
        $dataPerBlock = $info['data'];
        $ecPerBlock = $info['total'] - $info['data'];

        $blocks = array_chunk($dataCodewords, $dataPerBlock);
        $ecBlocks = array_map(
            static fn(array $block): array => self::reedSolomonRemainder($block, $ecPerBlock),
            $blocks,
        );

        $result = [];
        for ($i = 0; $i < $dataPerBlock; $i++) {
            foreach ($blocks as $block) {
                $result[] = $block[$i];
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $ecBlock) {
                $result[] = $ecBlock[$i];
            }
        }

        return $result;
    }

    // ------------------------------------------------ GF(256) / Reed-Solomon

    /**
     * @return list<int>
     */
    private static function reedSolomonRemainder(array $data, int $ecCount): array
    {
        $generator = self::generatorPolynomial($ecCount);
        $remainder = [...$data, ...array_fill(0, $ecCount, 0)];
        $dataLength = count($data);

        for ($i = 0; $i < $dataLength; $i++) {
            $coefficient = $remainder[$i];
            if ($coefficient === 0) {
                continue;
            }

            $logCoefficient = self::gfLog($coefficient);
            foreach ($generator as $j => $term) {
                if ($term !== 0) {
                    $remainder[$i + $j] ^= self::gfExp(self::gfLog($term) + $logCoefficient);
                }
            }
        }

        return array_slice($remainder, $dataLength, $ecCount);
    }

    /**
     * The generator polynomial (x - g^0)(x - g^1)...(x - g^(ecCount-1)) over
     * GF(256), highest degree coefficient first.
     *
     * @return list<int>
     */
    private static function generatorPolynomial(int $ecCount): array
    {
        $poly = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $poly = self::polyMultiply($poly, [1, self::gfExp($i)]);
        }

        return $poly;
    }

    /**
     * @return list<int>
     */
    private static function polyMultiply(array $a, array $b): array
    {
        $result = array_fill(0, count($a) + count($b) - 1, 0);
        foreach ($a as $i => $ai) {
            if ($ai === 0) {
                continue;
            }
            $logAi = self::gfLog($ai);
            foreach ($b as $j => $bj) {
                if ($bj !== 0) {
                    $result[$i + $j] ^= self::gfExp($logAi + self::gfLog($bj));
                }
            }
        }

        return $result;
    }

    /**
     * QR's field: GF(2^8) with primitive polynomial x^8+x^4+x^3+x^2+1
     * (0x11D), generator 2. Built once per process and cached, the same
     * pattern App\Service\Crypto\ServerCrypto::blindIndex() uses for a
     * per-call derivation that is cheap to repeat but pointless to redo
     * inside a loop.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function gfTables(): array
    {
        if (self::$expTable !== null) {
            /** @var array{0: list<int>, 1: list<int>} */
            return [self::$expTable, self::$logTable];
        }

        $exp = array_fill(0, 256, 0);
        for ($i = 0; $i < 8; $i++) {
            $exp[$i] = 1 << $i;
        }
        for ($i = 8; $i < 256; $i++) {
            $exp[$i] = $exp[$i - 4] ^ $exp[$i - 5] ^ $exp[$i - 6] ^ $exp[$i - 8];
        }

        $log = array_fill(0, 256, 0);
        for ($i = 0; $i < 255; $i++) {
            $log[$exp[$i]] = $i;
        }

        self::$expTable = $exp;
        self::$logTable = $log;

        return [$exp, $log];
    }

    private static function gfExp(int $n): int
    {
        [$exp] = self::gfTables();

        return $exp[(($n % 255) + 255) % 255];
    }

    private static function gfLog(int $n): int
    {
        [, $log] = self::gfTables();

        return $log[$n];
    }

    // ------------------------------------------------------- module layout

    /**
     * @return list<list<bool|null>>
     */
    private static function blankMatrix(int $version): array
    {
        $size = $version * 4 + 17;
        $modules = array_fill(0, $size, array_fill(0, $size, null));

        self::placeFinder($modules, 0, 0, $size);
        self::placeFinder($modules, $size - 7, 0, $size);
        self::placeFinder($modules, 0, $size - 7, $size);
        self::placeAlignment($modules, $version, $size);
        self::placeTiming($modules, $size);

        return $modules;
    }

    /**
     * One 7x7 finder pattern plus its light separator, clipped to the
     * matrix - clipping is what makes the separator land only on the side
     * that faces the symbol's interior for a finder sitting in a corner.
     *
     * @param list<list<bool|null>> $modules
     */
    private static function placeFinder(array &$modules, int $row, int $col, int $size): void
    {
        for ($r = -1; $r <= 7; $r++) {
            if ($row + $r <= -1 || $size <= $row + $r) {
                continue;
            }
            for ($c = -1; $c <= 7; $c++) {
                if ($col + $c <= -1 || $size <= $col + $c) {
                    continue;
                }

                $dark = (0 <= $r && $r <= 6 && ($c === 0 || $c === 6))
                    || (0 <= $c && $c <= 6 && ($r === 0 || $r === 6))
                    || (2 <= $r && $r <= 4 && 2 <= $c && $c <= 4);
                $modules[$row + $r][$col + $c] = $dark;
            }
        }
    }

    /**
     * @param list<list<bool|null>> $modules
     */
    private static function placeAlignment(array &$modules, int $version, int $size): void
    {
        $position = self::ALIGNMENT_POSITION[$version];
        if ($position === null) {
            return;
        }

        // The Cartesian product of {6, position} covers four spots; three
        // fall inside a finder pattern and are skipped because that cell is
        // already assigned.
        foreach ([6, $position] as $row) {
            foreach ([6, $position] as $col) {
                if ($modules[$row][$col] !== null) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $dark = $r === -2 || $r === 2 || $c === -2 || $c === 2 || ($r === 0 && $c === 0);
                        $modules[$row + $r][$col + $c] = $dark;
                    }
                }
            }
        }
    }

    /**
     * @param list<list<bool|null>> $modules
     */
    private static function placeTiming(array &$modules, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            if ($modules[$i][6] === null) {
                $modules[$i][6] = $i % 2 === 0;
            }
            if ($modules[6][$i] === null) {
                $modules[6][$i] = $i % 2 === 0;
            }
        }
    }

    /**
     * The 15-bit format information (error correction level + mask pattern,
     * BCH(15,5)-protected), written twice, plus the one module that is
     * always dark regardless of format or mask (ISO/IEC 18004 section 8.9).
     *
     * @param list<list<bool|null>> $modules
     * @param bool $test true while a mask is only being scored
     *              (lostPoint()): the format strip is blanked out instead of
     *              carrying its real bits, because it encodes the mask
     *              pattern itself and would otherwise bias the very score
     *              that is supposed to pick the mask.
     */
    private static function placeFormatInfo(array &$modules, int $size, int $maskPattern, bool $test = false): void
    {
        $bits = self::formatBits((self::EC_LEVEL_M << 3) | $maskPattern);

        for ($i = 0; $i < 15; $i++) {
            $bit = !$test && (($bits >> $i) & 1) === 1;
            if ($i < 6) {
                $modules[$i][8] = $bit;
            } elseif ($i < 8) {
                $modules[$i + 1][8] = $bit;
            } else {
                $modules[$size - 15 + $i][8] = $bit;
            }
        }

        for ($i = 0; $i < 15; $i++) {
            $bit = !$test && (($bits >> $i) & 1) === 1;
            if ($i < 8) {
                $modules[8][$size - $i - 1] = $bit;
            } elseif ($i < 9) {
                $modules[8][15 - $i] = $bit;
            } else {
                $modules[8][14 - $i] = $bit;
            }
        }

        $modules[$size - 8][8] = !$test;
    }

    private static function formatBits(int $data): int
    {
        $shifted = $data << 10;
        $remainder = $shifted;
        while (self::bitLength($remainder) - self::bitLength(self::FORMAT_GENERATOR) >= 0) {
            $remainder ^= self::FORMAT_GENERATOR << (self::bitLength($remainder) - self::bitLength(self::FORMAT_GENERATOR));
        }

        return (($data << 10) | $remainder) ^ self::FORMAT_MASK;
    }

    private static function bitLength(int $value): int
    {
        $length = 0;
        while ($value !== 0) {
            $length++;
            $value >>= 1;
        }

        return $length;
    }

    /**
     * The zig-zag walk that fills every module the function patterns left
     * `null`, two columns at a time from the bottom-right, skipping the
     * vertical timing column.
     *
     * @param list<list<bool|null>> $modules
     * @param list<int> $data
     */
    private static function mapData(array &$modules, int $size, array $data, int $maskPattern): void
    {
        $mask = self::maskFunction($maskPattern);
        $direction = -1;
        $row = $size - 1;
        $bitIndex = 7;
        $byteIndex = 0;
        $dataLength = count($data);

        // Steps by -2 from a value independent of $col: the timing column
        // (6) is skipped by processing one column to its left instead, but
        // the NEXT step must still continue from the original, unadjusted
        // sequence (4, 2, ...) - not from the adjusted value. A single
        // mutated loop variable would collapse that into stepping by -2 from
        // 5 instead of from 4, losing column 0 entirely.
        for ($outerCol = $size - 1; $outerCol > 0; $outerCol -= 2) {
            $col = $outerCol <= 6 ? $outerCol - 1 : $outerCol;

            while (true) {
                foreach ([$col, $col - 1] as $c) {
                    if ($modules[$row][$c] === null) {
                        $dark = $byteIndex < $dataLength && (($data[$byteIndex] >> $bitIndex) & 1) === 1;
                        if ($mask($row, $c)) {
                            $dark = !$dark;
                        }
                        $modules[$row][$c] = $dark;

                        if (--$bitIndex === -1) {
                            $byteIndex++;
                            $bitIndex = 7;
                        }
                    }
                }

                $row += $direction;
                if ($row < 0 || $size <= $row) {
                    $row -= $direction;
                    $direction = -$direction;
                    break;
                }
            }
        }
    }

    /**
     * @return \Closure(int, int): bool
     */
    private static function maskFunction(int $pattern): \Closure
    {
        return match ($pattern) {
            0 => static fn(int $i, int $j): bool => ($i + $j) % 2 === 0,
            1 => static fn(int $i, int $j): bool => $i % 2 === 0,
            2 => static fn(int $i, int $j): bool => $j % 3 === 0,
            3 => static fn(int $i, int $j): bool => ($i + $j) % 3 === 0,
            4 => static fn(int $i, int $j): bool => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
            5 => static fn(int $i, int $j): bool => ($i * $j) % 2 + ($i * $j) % 3 === 0,
            6 => static fn(int $i, int $j): bool => (($i * $j) % 2 + ($i * $j) % 3) % 2 === 0,
            7 => static fn(int $i, int $j): bool => (($i * $j) % 3 + ($i + $j) % 2) % 2 === 0,
            default => throw new \InvalidArgumentException(sprintf('Unknown mask pattern %d.', $pattern)),
        };
    }

    // ------------------------------------------------------ mask selection

    /**
     * @param list<list<bool>> $modules
     */
    private static function lostPoint(array $modules, int $size): int
    {
        return self::lostPointRuns($modules, $size)
            + self::lostPointBlocks($modules, $size)
            + self::lostPointFinderLike($modules, $size)
            + self::lostPointDarkRatio($modules, $size);
    }

    /** Five-or-more same-colour modules in a row or column. */
    private static function lostPointRuns(array $modules, int $size): int
    {
        $lost = 0;
        for ($row = 0; $row < $size; $row++) {
            $lost += self::runsIn(static fn(int $i): bool => $modules[$row][$i], $size);
        }
        for ($col = 0; $col < $size; $col++) {
            $lost += self::runsIn(static fn(int $i): bool => $modules[$i][$col], $size);
        }

        return $lost;
    }

    /**
     * @param \Closure(int): bool $at
     */
    private static function runsIn(\Closure $at, int $size): int
    {
        $lost = 0;
        $previous = $at(0);
        $length = 1;
        for ($i = 1; $i < $size; $i++) {
            $value = $at($i);
            if ($value === $previous) {
                $length++;
                continue;
            }
            if ($length >= 5) {
                $lost += $length - 2;
            }
            $previous = $value;
            $length = 1;
        }
        if ($length >= 5) {
            $lost += $length - 2;
        }

        return $lost;
    }

    /** 2x2 blocks of a single colour. */
    private static function lostPointBlocks(array $modules, int $size): int
    {
        $lost = 0;
        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $corner = $modules[$row][$col];
                if (
                    $corner === $modules[$row][$col + 1]
                    && $corner === $modules[$row + 1][$col]
                    && $corner === $modules[$row + 1][$col + 1]
                ) {
                    $lost += 3;
                }
            }
        }

        return $lost;
    }

    /** The 1:1:3:1:1 finder-like run, in rows and in columns. */
    private static function lostPointFinderLike(array $modules, int $size): int
    {
        $lost = 0;
        $limit = $size - 10;

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $limit; $col++) {
                if (self::isFinderLike(static fn(int $i): bool => $modules[$row][$col + $i])) {
                    $lost += 40;
                }
            }
        }
        for ($col = 0; $col < $size; $col++) {
            for ($row = 0; $row < $limit; $row++) {
                if (self::isFinderLike(static fn(int $i): bool => $modules[$row + $i][$col])) {
                    $lost += 40;
                }
            }
        }

        return $lost;
    }

    /**
     * @param \Closure(int): bool $at offset 0..10 along the run being tested
     */
    private static function isFinderLike(\Closure $at): bool
    {
        if (!(!$at(1) && $at(4) && !$at(5) && $at(6) && !$at(9))) {
            return false;
        }

        return ($at(0) && $at(2) && $at(3) && !$at(7) && !$at(8) && !$at(10))
            || (!$at(0) && !$at(2) && !$at(3) && $at(7) && $at(8) && $at(10));
    }

    /** Departure of the dark-module ratio from 50%, in steps of 5%. */
    private static function lostPointDarkRatio(array $modules, int $size): int
    {
        $dark = 0;
        foreach ($modules as $row) {
            $dark += count(array_filter($row));
        }

        $percent = ($dark / ($size * $size)) * 100;

        return (int) (abs($percent - 50) / 5) * 10;
    }
}
