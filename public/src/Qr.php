<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * A byte-mode QR encoder, emitting the module matrix and inline SVG.
 *
 * WHY THIS EXISTS AT ALL: the live event pass is drawn by the CLIENT, whose
 * encoder (FOK-snake js/qr.js) is a fixed version 3 / level L / mask 0 and
 * tops out at 53 text bytes. The PRINTED key URL is 63 bytes and wants a
 * sturdier level, so the server needs an encoder of its own - generalised
 * over versions, levels and masks where the client's is nailed down.
 *
 * It is the same mathematics: GF(256) with reducer 0x11D, the same
 * Reed-Solomon divisor, the same format BCH (0x537 / 0x5412), the same
 * zigzag placement. That is deliberate and it is TESTED: the unit suite
 * encodes the client's own friend URL at version 3 / L / mask 0 and compares
 * the matrix module for module against the vector the client's test pins.
 * The two encoders are held to each other, so neither can drift alone.
 *
 * Scope: versions 1 to 6. Version 7 is where a QR gains a version-information
 * block, and nothing here needs one - version 5 at level M already holds 84
 * bytes against a 63-byte URL. Levels L and M only, for the same reason.
 *
 * No composer, no CDN, no image library: the output is an SVG string, and the
 * only consumer is the admin print page.
 */
final class Qr
{
    /** Level bits as the format information encodes them. */
    private const LEVELS = ['L' => 1, 'M' => 0];

    /**
     * Per version and level: [ECC codewords per block, block count, data
     * codewords per block]. A version whose blocks are uneven carries the
     * second group as a fourth and fifth entry; none of these do, because the
     * standard's own tables make groups 1 and 2 equal here.
     *
     * @var array<int, array<string, array{0:int,1:int,2:int}>>
     */
    private const BLOCKS = [
        1 => ['L' => [7, 1, 19],  'M' => [10, 1, 16]],
        2 => ['L' => [10, 1, 34], 'M' => [16, 1, 28]],
        3 => ['L' => [15, 1, 55], 'M' => [26, 1, 44]],
        4 => ['L' => [20, 1, 80], 'M' => [18, 2, 32]],
        5 => ['L' => [26, 1, 108], 'M' => [24, 2, 43]],
        6 => ['L' => [18, 2, 68], 'M' => [16, 4, 27]],
    ];

    /**
     * The single alignment-pattern centre of versions 2 to 6. Version 1 has
     * none, and below version 7 the standard's centre list is [6, X], whose
     * four combinations are three overlapping finders and this one.
     */
    private const ALIGN = [2 => 18, 3 => 22, 4 => 26, 5 => 30, 6 => 34];

    /**
     * [ECC codewords per block, block count, data codewords per block].
     * @return array{0:int,1:int,2:int}
     */
    public static function layout(int $version, string $level): array
    {
        return self::BLOCKS[$version][$level];
    }

    public static function size(int $version): int
    {
        return 17 + 4 * $version;
    }

    /** How many text bytes a version and level hold: the 12-bit header costs 2. */
    public static function capacity(int $version, string $level): int
    {
        [, $blocks, $perBlock] = self::BLOCKS[$version][$level];
        return $blocks * $perBlock - 2;
    }

    /** The smallest version of $level that holds $text, or 0 for none. */
    public static function fit(string $text, string $level): int
    {
        foreach (array_keys(self::BLOCKS) as $v) {
            if (strlen($text) <= self::capacity($v, $level)) {
                return $v;
            }
        }
        return 0;
    }

    /**
     * The module matrix: $m[$row][$col] true for a dark module.
     *
     * $mask -1 picks the lowest-penalty mask by the standard's four rules,
     * which is what a printed code wants; naming one pins the output, which
     * is what the compatibility vector needs.
     *
     * @return array{size: int, version: int, level: string, mask: int, m: list<list<bool>>}
     */
    public static function matrix(string $text, string $level = 'M',
                                  int $version = 0, int $mask = -1): array
    {
        if (!isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException('qr: unknown ecc level');
        }
        if ($version === 0) {
            $version = self::fit($text, $level);
        }
        if (!isset(self::BLOCKS[$version])) {
            throw new InvalidArgumentException('qr: unsupported version');
        }
        if (strlen($text) > self::capacity($version, $level)) {
            throw new InvalidArgumentException('qr: payload too long for the version');
        }
        $cw = self::codewords($text, $version, $level);
        if ($mask >= 0) {
            return self::draw($text, $version, $level, $mask, $cw);
        }
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $q = self::draw($text, $version, $level, $m, $cw);
            $score = self::penalty($q['m'], $q['size']);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $q;
            }
        }
        return $best ?? self::draw($text, $version, $level, 0, $cw);
    }

    /**
     * Inline SVG, one rect per dark module. $scale is the module size in user
     * units and $quiet the light margin, in modules - four is the standard's
     * minimum and a printed code that loses it stops scanning.
     */
    public static function svg(string $text, string $level = 'M', int $version = 0,
                               int $mask = -1, int $scale = 8, int $quiet = 4): string
    {
        $q = self::matrix($text, $level, $version, $mask);
        $n = $q['size'] + 2 * $quiet;
        $side = $n * $scale;
        $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '"'
            . ' width="' . $side . '" height="' . $side . '" shape-rendering="crispEdges"'
            . ' role="img" aria-label="QR code">'
            . '<rect width="' . $n . '" height="' . $n . '" fill="#fff"></rect><g fill="#000">';
        // One rect per dark RUN rather than per module: a run of eight modules
        // is one rect instead of eight, which roughly halves the page.
        for ($y = 0; $y < $q['size']; $y++) {
            $x = 0;
            while ($x < $q['size']) {
                if (!$q['m'][$y][$x]) {
                    $x++;
                    continue;
                }
                $run = 1;
                while ($x + $run < $q['size'] && $q['m'][$y][$x + $run]) {
                    $run++;
                }
                $out .= '<rect x="' . ($x + $quiet) . '" y="' . ($y + $quiet)
                    . '" width="' . $run . '" height="1"></rect>';
                $x += $run;
            }
        }
        return $out . '</g></svg>';
    }

    // ---------------------------------------------------------------
    // The mathematics, shared with the client's encoder
    // ---------------------------------------------------------------

    /** GF(256) multiply, reducer 0x11D. No tables: this runs 2000 times. */
    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ ((($z >> 7) & 1) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    /** The Reed-Solomon generator polynomial of a degree: product of (x - a^i). */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root)
                    ^ ($j + 1 < $degree ? $result[$j + 1] : 0);
            }
            $root = self::gfMul($root, 2);
        }
        return $result;
    }

    /** Remainder of data / divisor: the ECC codewords. */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $j => $d) {
                $result[$j] ^= self::gfMul($d, $factor);
            }
        }
        return $result;
    }

    /**
     * The whole codeword sequence: the byte-mode payload split into blocks,
     * each with its own ECC, then interleaved the way the standard reads them
     * back. A single-block version comes out as data followed by ECC, which
     * is what the client's fixed encoder produces.
     */
    private static function codewords(string $text, int $version, string $level): array
    {
        [$ecc, $blocks, $perBlock] = self::BLOCKS[$version][$level];
        $total = $blocks * $perBlock;
        $bits = [];
        $push = static function (int $val, int $n) use (&$bits): void {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $push(4, 4);                      // byte mode
        $push(strlen($text), 8);          // count, 8 bits for versions 1-9
        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $push(ord($text[$i]), 8);
        }
        $push(0, min(4, $total * 8 - count($bits)));   // terminator
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $data = [];
        for ($i = 0, $n = count($bits); $i < $n; $i += 8) {
            $b = 0;
            for ($j = 0; $j < 8; $j++) {
                $b = ($b << 1) | $bits[$i + $j];
            }
            $data[] = $b;
        }
        for ($pad = 0xEC; count($data) < $total; $pad ^= 0xEC ^ 0x11) {
            $data[] = $pad;
        }
        $divisor = self::rsDivisor($ecc);
        $dataBlocks = [];
        $eccBlocks = [];
        for ($b = 0; $b < $blocks; $b++) {
            $chunk = array_slice($data, $b * $perBlock, $perBlock);
            $dataBlocks[] = $chunk;
            $eccBlocks[] = self::rsRemainder($chunk, $divisor);
        }
        $out = [];
        for ($i = 0; $i < $perBlock; $i++) {
            foreach ($dataBlocks as $blk) {
                $out[] = $blk[$i];
            }
        }
        for ($i = 0; $i < $ecc; $i++) {
            foreach ($eccBlocks as $blk) {
                $out[] = $blk[$i];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Geometry
    // ---------------------------------------------------------------

    /**
     * True where a module belongs to the code's fixed furniture rather than
     * to the data: finders with their separators and format strips, the two
     * timing lines, the alignment pattern and the always-dark module.
     *
     * This and dataOrder() are public because READING a code back needs
     * exactly the same map, and a second copy of it would be a second
     * thing to keep right (see the round trip in test/unit.php).
     */
    public static function isFunction(int $version, int $x, int $y): bool
    {
        $s = self::size($version);
        if (($x < 9 && $y < 9) || ($x >= $s - 8 && $y < 9) || ($x < 9 && $y >= $s - 8)) {
            return true;
        }
        if ($x === 6 || $y === 6) {
            return true;
        }
        $c = self::ALIGN[$version] ?? null;
        return $c !== null && $x >= $c - 2 && $x <= $c + 2 && $y >= $c - 2 && $y <= $c + 2;
    }

    /**
     * The zigzag the data follows: up and down two-module columns from the
     * bottom right, skipping the vertical timing line.
     *
     * @return list<array{0:int,1:int}>
     */
    public static function dataOrder(int $version): array
    {
        $s = self::size($version);
        $ord = [];
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $s - 1 - $vert : $vert;
                    if (!self::isFunction($version, $x, $y)) {
                        $ord[] = [$x, $y];
                    }
                }
            }
        }
        return $ord;
    }

    /** The eight mask patterns, by the standard's numbering. */
    public static function masked(int $mask, int $x, int $y): bool
    {
        switch ($mask) {
            case 0: return ($x + $y) % 2 === 0;
            case 1: return $y % 2 === 0;
            case 2: return $x % 3 === 0;
            case 3: return ($x + $y) % 3 === 0;
            case 4: return (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0;
            case 5: return ($x * $y) % 2 + ($x * $y) % 3 === 0;
            case 6: return (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0;
            default: return ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0;
        }
    }

    /**
     * @return array{size: int, version: int, level: string, mask: int, m: list<list<bool>>}
     */
    private static function draw(string $text, int $version, string $level,
                                 int $mask, array $cw): array
    {
        $s = self::size($version);
        $m = array_fill(0, $s, array_fill(0, $s, false));
        // Timing lines first; the finders overwrite their ends.
        for ($i = 0; $i < $s; $i++) {
            $m[$i][6] = $i % 2 === 0;
            $m[6][$i] = $i % 2 === 0;
        }
        $finder = static function (int $cx, int $cy) use (&$m, $s): void {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x < 0 || $x >= $s || $y < 0 || $y >= $s) {
                        continue;
                    }
                    $dist = max(abs($dx), abs($dy));
                    $m[$y][$x] = $dist !== 2 && $dist !== 4;
                }
            }
        };
        $finder(3, 3);
        $finder($s - 4, 3);
        $finder(3, $s - 4);
        $c = self::ALIGN[$version] ?? null;
        if ($c !== null) {
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $m[$c + $dy][$c + $dx] = max(abs($dx), abs($dy)) !== 1;
                }
            }
        }
        // Format information: level and mask, BCH-protected, both copies.
        $fdata = (self::LEVELS[$level] << 3) | $mask;
        $rem = $fdata;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $fbits = (($fdata << 10) | $rem) ^ 0x5412;
        $fbit = static fn(int $i): bool => (($fbits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $m[$i][8] = $fbit($i);
        }
        $m[7][8] = $fbit(6);
        $m[8][8] = $fbit(7);
        $m[8][7] = $fbit(8);
        for ($i = 9; $i < 15; $i++) {
            $m[8][14 - $i] = $fbit($i);
        }
        for ($i = 0; $i < 8; $i++) {
            $m[8][$s - 1 - $i] = $fbit($i);
        }
        for ($i = 8; $i < 15; $i++) {
            $m[$s - 15 + $i][8] = $fbit($i);
        }
        $m[$s - 8][8] = true;      // the always-dark module
        $bi = 0;
        $bits = count($cw) * 8;
        foreach (self::dataOrder($version) as [$x, $y]) {
            $dark = false;
            if ($bi < $bits) {
                // Past the last codeword come the version's remainder bits,
                // which are light before masking and carry nothing.
                $dark = (($cw[$bi >> 3] >> (7 - ($bi & 7))) & 1) === 1;
                $bi++;
            }
            if (self::masked($mask, $x, $y)) {
                $dark = !$dark;
            }
            $m[$y][$x] = $dark;
        }
        return ['size' => $s, 'version' => $version, 'level' => $level,
                'mask' => $mask, 'm' => $m];
    }

    /**
     * The standard's four penalty rules, used only to choose a mask. Lower is
     * better; the absolute number means nothing outside that comparison.
     */
    private static function penalty(array $m, int $s): int
    {
        $score = 0;
        // Rule 1: runs of five or more of one colour, in rows and columns.
        for ($i = 0; $i < $s; $i++) {
            for ($dir = 0; $dir < 2; $dir++) {
                $run = 1;
                for ($j = 1; $j < $s; $j++) {
                    $a = $dir === 0 ? $m[$i][$j] : $m[$j][$i];
                    $b = $dir === 0 ? $m[$i][$j - 1] : $m[$j - 1][$i];
                    if ($a === $b) {
                        $run++;
                        continue;
                    }
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }
        // Rule 2: every 2x2 block of one colour.
        for ($y = 0; $y < $s - 1; $y++) {
            for ($x = 0; $x < $s - 1; $x++) {
                $v = $m[$y][$x];
                if ($v === $m[$y][$x + 1] && $v === $m[$y + 1][$x] && $v === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        // Rule 3: the finder-like 1:1:3:1:1 run with four light modules beside
        // it, which is what a scanner mistakes for a finder.
        $bad = [true, false, true, true, true, false, true, false, false, false, false];
        $badRev = array_reverse($bad);
        for ($i = 0; $i < $s; $i++) {
            for ($j = 0; $j + 11 <= $s; $j++) {
                $row = [];
                $col = [];
                for ($k = 0; $k < 11; $k++) {
                    $row[] = $m[$i][$j + $k];
                    $col[] = $m[$j + $k][$i];
                }
                foreach ([$bad, $badRev] as $pat) {
                    if ($row === $pat) {
                        $score += 40;
                    }
                    if ($col === $pat) {
                        $score += 40;
                    }
                }
            }
        }
        // Rule 4: how far the dark proportion strays from half.
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $v) {
                if ($v) {
                    $dark++;
                }
            }
        }
        $pct = (int)floor($dark * 100 / ($s * $s));
        $score += 10 * (int)floor(abs($pct - 50) / 5);
        return $score;
    }
}
