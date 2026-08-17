<?php

namespace Tests\Unit;

use App\Support\Qr\QrEncoder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * A QR code that is merely plausible is worthless — it either scans or it does
 * not, and nothing about looking at one tells you which. The expectations here
 * were taken from output confirmed to decode back to its input by jsQR, an
 * independent decoder, across every version this encoder emits.
 */
class QrEncoderTest extends TestCase
{
    public function test_it_picks_the_smallest_version_that_holds_the_payload(): void
    {
        // 21x21, 25x25 and 29x29 are versions 1, 2 and 3.
        $this->assertCount(21, QrEncoder::matrix('ABCDEF'));
        $this->assertCount(25, QrEncoder::matrix('ABCDEFGHIJKLMNOPQR'));
        $this->assertCount(29, QrEncoder::matrix(str_repeat('Z', 42)));
    }

    public function test_it_refuses_a_payload_it_cannot_hold_rather_than_encoding_it_wrong(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QrEncoder::matrix(str_repeat('Z', QrEncoder::MAX_BYTES + 1));
    }

    public function test_it_rejects_an_empty_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QrEncoder::matrix('');
    }

    public function test_it_places_the_three_finder_patterns_a_scanner_looks_for(): void
    {
        $matrix = QrEncoder::matrix('HRMS-ATT1.2.1d948bc59647588ef92a');
        $size = count($matrix);

        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
            // The 7x7 eye: dark border, light ring, dark 3x3 core.
            $this->assertTrue($matrix[$top][$left], 'Finder corner is not dark.');
            $this->assertFalse($matrix[$top + 1][$left + 1], 'Finder ring is not light.');
            $this->assertTrue($matrix[$top + 3][$left + 3], 'Finder core is not dark.');
        }

        // A quiet corner where the fourth finder deliberately is not.
        $this->assertFalse($matrix[$size - 1][$size - 1]);
    }

    public function test_the_timing_patterns_alternate(): void
    {
        $matrix = QrEncoder::matrix('HRMS-ATT1.2.1d948bc59647588ef92a');

        for ($i = 8; $i < count($matrix) - 8; $i++) {
            $this->assertSame($i % 2 === 0, $matrix[6][$i], "Horizontal timing wrong at {$i}.");
            $this->assertSame($i % 2 === 0, $matrix[$i][6], "Vertical timing wrong at {$i}.");
        }
    }

    /**
     * The exact grid for a representative badge, confirmed to decode back to
     * this string. Any change to the encoder that alters a single module will
     * fail here, which is the point: the drift would otherwise be invisible
     * until a badge stopped scanning at the door.
     */
    public function test_a_known_badge_encodes_to_its_verified_grid(): void
    {
        $matrix = QrEncoder::matrix('HRMS-ATT1.2.1d948bc59647588ef92a');
        $firstRow = implode('', array_map(fn (bool $dark) => $dark ? '1' : '0', $matrix[0]));

        $this->assertCount(29, $matrix);
        $this->assertSame('11111110001111110001001111111', $firstRow);
        $this->assertSame('2bbeeec095060190ea5cb550946d9ca8', $this->fingerprint($matrix));
    }

    /**
     * @param  list<list<bool>>  $matrix
     */
    private function fingerprint(array $matrix): string
    {
        $bits = '';
        foreach ($matrix as $row) {
            foreach ($row as $dark) {
                $bits .= $dark ? '1' : '0';
            }
        }

        return md5($bits);
    }
}
