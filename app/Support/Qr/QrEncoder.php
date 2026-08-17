<?php

namespace App\Support\Qr;

use InvalidArgumentException;

/**
 * A small QR encoder, enough for the attendance badge and nothing more.
 *
 * The badge is static content the server already knows, so drawing it here
 * rather than in the browser costs one render and removes an entire class of
 * failure: a workstation that has not installed the front-end packages, a
 * blocked script, a browser without canvas. The employee's own page then needs
 * no JavaScript at all to show or download their code.
 *
 * Scope is deliberately narrow — byte mode, error correction level M, and only
 * versions 1 to 3, all of which hold their data in a single block and so need
 * no interleaving. That covers 42 bytes, comfortably more than the badge's
 * ~40-character payload, and anything longer is rejected rather than silently
 * encoded wrong. Reading a QR back from a camera frame is a different problem
 * and still belongs to a real library.
 *
 * Implements ISO/IEC 18004. Verified by decoding its output with an
 * independent decoder; see QrEncoderTest.
 */
class QrEncoder
{
    /** Data codewords per version at EC level M, indexed by version. */
    private const DATA_CODEWORDS = [1 => 16, 2 => 28, 3 => 44];

    /** Error-correction codewords per version at EC level M. */
    private const EC_CODEWORDS = [1 => 10, 2 => 16, 3 => 26];

    /** Centre coordinate of the single alignment pattern, or null for version 1. */
    private const ALIGNMENT_CENTRE = [1 => null, 2 => 18, 3 => 22];

    /** EC level M as it appears in the format information. */
    private const EC_LEVEL_BITS = 0b00;

    public const MAX_BYTES = 42;

    /** @var list<list<bool>> */
    private array $modules = [];

    /** @var list<list<bool>> */
    private array $isFunction = [];

    private int $size;

    private function __construct(private readonly int $version)
    {
        $this->size = $this->version * 4 + 17;

        for ($y = 0; $y < $this->size; $y++) {
            $this->modules[$y] = array_fill(0, $this->size, false);
            $this->isFunction[$y] = array_fill(0, $this->size, false);
        }
    }

    /**
     * Encode text into a square grid, where true is a dark module.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $text): array
    {
        $length = strlen($text);

        if ($length === 0) {
            throw new InvalidArgumentException('Nothing to encode.');
        }

        if ($length > self::MAX_BYTES) {
            throw new InvalidArgumentException(
                'This encoder holds '.self::MAX_BYTES." bytes; {$length} were given."
            );
        }

        $qr = new self(self::smallestVersionFor($length));
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->codewordsFor($text));
        $qr->applyBestMask();

        return $qr->modules;
    }

    /**
     * The grid as a standalone SVG. Sized in modules with a viewBox, so the
     * caller decides the final dimensions in CSS and the code stays sharp at
     * any size — including on paper, which is where a badge ends up.
     */
    public static function svg(string $text, int $quietZone = 4): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix) + $quietZone * 2;

        $path = '';
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M'.($x + $quietZone).' '.($y + $quietZone).'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" '
            .'shape-rendering="crispEdges" role="img">'
            .'<rect width="'.$size.'" height="'.$size.'" fill="#ffffff"/>'
            .'<path d="'.$path.'" fill="#000000"/>'
            .'</svg>';
    }

    /**
     * The grid as PNG bytes, for the download a phone or a printer expects.
     */
    public static function png(string $text, int $moduleSize = 12, int $quietZone = 4): string
    {
        $matrix = self::matrix($text);
        $side = (count($matrix) + $quietZone * 2) * $moduleSize;

        $image = imagecreatetruecolor($side, $side);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $side, $side, $white);

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if (! $dark) {
                    continue;
                }

                $left = ($x + $quietZone) * $moduleSize;
                $top = ($y + $quietZone) * $moduleSize;
                imagefilledrectangle($image, $left, $top, $left + $moduleSize - 1, $top + $moduleSize - 1, $black);
            }
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private static function smallestVersionFor(int $length): int
    {
        foreach (self::DATA_CODEWORDS as $version => $codewords) {
            // Four bits of mode indicator plus an eight-bit character count.
            if ($length <= intdiv($codewords * 8 - 12, 8)) {
                return $version;
            }
        }

        return 3;
    }

    /**
     * Byte-mode bitstream, padded out and given its error-correction tail.
     *
     * @return list<int>
     */
    private function codewordsFor(string $text): array
    {
        $capacity = self::DATA_CODEWORDS[$this->version] * 8;
        $bits = '0100'.str_pad(decbin(strlen($text)), 8, '0', STR_PAD_LEFT);

        foreach (str_split($text) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        // Terminator, then out to a whole codeword.
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $data = [];
        foreach (str_split($bits, 8) as $byte) {
            $data[] = bindec($byte);
        }

        // The standard's alternating filler for any codewords left over.
        $padding = [0xEC, 0x11];
        for ($i = 0; count($data) < self::DATA_CODEWORDS[$this->version]; $i++) {
            $data[] = $padding[$i % 2];
        }

        return array_merge($data, $this->errorCorrection($data));
    }

    /**
     * Reed-Solomon remainder over GF(256), which is what lets a scanner read a
     * badge that has been creased, smudged, or partly covered by a thumb.
     *
     * @param  list<int>  $data
     * @return list<int>
     */
    private function errorCorrection(array $data): array
    {
        $degree = self::EC_CODEWORDS[$this->version];
        $generator = $this->generatorPolynomial($degree);
        $remainder = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($remainder);
            $remainder[] = 0;

            foreach ($generator as $i => $coefficient) {
                $remainder[$i] ^= $this->multiply($coefficient, $factor);
            }
        }

        return $remainder;
    }

    /**
     * @return list<int>
     */
    private function generatorPolynomial(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = $this->multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = $this->multiply($root, 0x02);
        }

        return $result;
    }

    /** Carry-less multiply reduced by the QR field polynomial 0x11D. */
    private function multiply(int $x, int $y): int
    {
        $product = 0;

        for ($i = 7; $i >= 0; $i--) {
            $product = ($product << 1) ^ (($product >> 7) * 0x11D);
            $product ^= (($y >> $i) & 1) * $x;
        }

        return $product & 0xFF;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction($i, 6, $i % 2 === 0);
            $this->setFunction(6, $i, $i % 2 === 0);
        }

        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $centre = self::ALIGNMENT_CENTRE[$this->version];
        if ($centre !== null) {
            $this->drawAlignment($centre, $centre);
        }

        // Format information is drawn for real once a mask is chosen; this
        // reserves its cells so data never lands there.
        $this->drawFormat(0);
    }

    private function drawFinder(int $centreX, int $centreY): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $centreX + $dx;
                $y = $centreY + $dy;

                if ($x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size) {
                    continue;
                }

                $distance = max(abs($dx), abs($dy));
                $this->setFunction($x, $y, $distance !== 2 && $distance !== 4);
            }
        }
    }

    private function drawAlignment(int $centreX, int $centreY): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($centreX + $dx, $centreY + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /**
     * Format information: the EC level and mask, protected by a BCH code and
     * XORed with the standard's mask so it is never all zeroes. Written twice,
     * so losing one corner does not cost the reader the whole code.
     */
    private function drawFormat(int $mask): void
    {
        $data = self::EC_LEVEL_BITS << 3 | $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $bits = (($data << 10) | $remainder) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $this->bit($bits, $i));
        }

        $this->setFunction(8, 7, $this->bit($bits, 6));
        $this->setFunction(8, 8, $this->bit($bits, 7));
        $this->setFunction(7, 8, $this->bit($bits, 8));

        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $this->bit($bits, $i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $this->bit($bits, $i));
        }

        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $this->bit($bits, $i));
        }

        // The one module that is always dark, by definition.
        $this->setFunction(8, $this->size - 8, true);
    }

    /**
     * Lay the codewords into the grid, two columns at a time from the
     * bottom-right, alternating direction and stepping over the timing column.
     *
     * @param  list<int>  $codewords
     */
    private function drawCodewords(array $codewords): void
    {
        $bit = 0;
        $total = count($codewords) * 8;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                for ($column = 0; $column < 2; $column++) {
                    $x = $right - $column;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;

                    if ($this->isFunction[$y][$x] || $bit >= $total) {
                        continue;
                    }

                    $this->modules[$y][$x] = $this->bit($codewords[$bit >> 3], 7 - ($bit & 7));
                    $bit++;
                }
            }
        }
    }

    /**
     * Try every mask and keep the one the standard scores best. Masking exists
     * to break up the large blank areas and stray finder-like shapes that a
     * plain layout can produce and a scanner then trips over.
     */
    private function applyBestMask(): void
    {
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormat($mask);
            $penalty = $this->penalty();

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }

            // XOR is its own inverse, so the same pass undoes it.
            $this->applyMask($mask);
        }

        $this->applyMask($bestMask);
        $this->drawFormat($bestMask);
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }

                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                $this->modules[$y][$x] = $this->modules[$y][$x] !== $invert;
            }
        }
    }

    /** The standard's four penalty rules, summed. */
    private function penalty(): int
    {
        $penalty = 0;

        // Runs of five or more, in both directions.
        for ($i = 0; $i < $this->size; $i++) {
            $penalty += $this->runPenalty($this->row($i));
            $penalty += $this->runPenalty($this->column($i));
        }

        // Solid two-by-two blocks.
        for ($y = 0; $y < $this->size - 1; $y++) {
            for ($x = 0; $x < $this->size - 1; $x++) {
                $module = $this->modules[$y][$x];

                if ($module === $this->modules[$y][$x + 1]
                    && $module === $this->modules[$y + 1][$x]
                    && $module === $this->modules[$y + 1][$x + 1]) {
                    $penalty += 3;
                }
            }
        }

        // Shapes a scanner could mistake for a finder pattern.
        for ($i = 0; $i < $this->size; $i++) {
            $penalty += $this->finderLikePenalty($this->row($i));
            $penalty += $this->finderLikePenalty($this->column($i));
        }

        // Drift away from an even balance of dark and light.
        $dark = 0;
        foreach ($this->modules as $row) {
            $dark += count(array_filter($row));
        }

        $total = $this->size * $this->size;
        $deviation = (int) (abs($dark * 100 - $total * 50) / $total);

        return $penalty + intdiv($deviation, 5) * 10;
    }

    /**
     * @param  list<bool>  $line
     */
    private function runPenalty(array $line): int
    {
        $penalty = 0;
        $runLength = 1;

        for ($i = 1; $i < count($line); $i++) {
            if ($line[$i] === $line[$i - 1]) {
                $runLength++;

                continue;
            }

            $penalty += $runLength >= 5 ? $runLength - 2 : 0;
            $runLength = 1;
        }

        return $penalty + ($runLength >= 5 ? $runLength - 2 : 0);
    }

    /**
     * @param  list<bool>  $line
     */
    private function finderLikePenalty(array $line): int
    {
        $bits = '';
        foreach ($line as $module) {
            $bits .= $module ? '1' : '0';
        }

        return (substr_count($bits, '10111010000') + substr_count($bits, '00001011101')) * 40;
    }

    /**
     * @return list<bool>
     */
    private function row(int $y): array
    {
        return $this->modules[$y];
    }

    /**
     * @return list<bool>
     */
    private function column(int $x): array
    {
        return array_column($this->modules, $x);
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function bit(int $value, int $position): bool
    {
        return (($value >> $position) & 1) !== 0;
    }
}
