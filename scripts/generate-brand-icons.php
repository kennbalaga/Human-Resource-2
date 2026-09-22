<?php

/*
 * Regenerates the WorkForce app icons under public/images/icons from the Staff
 * Cross geometry — the same drawing as resources/views/components/brand-mark
 * .blade.php and public/favicon.svg, which is 64 units square:
 *
 *   each figure  head circle (32, 6.5) r 4.5, shoulders a half-ellipse 16 wide
 *                and 6.5 tall whose flat edge sits at y = 19.5, turned 0/90/180/270
 *   centre dot   (32, 32) r 5, orange
 *
 * Uses only GD, which ships with PHP, so no image toolchain is needed. Every
 * icon is drawn at 8x and downsampled, which is what gives the edges their
 * anti-aliasing (GD's own shape functions draw hard edges).
 *
 *   php scripts/generate-brand-icons.php
 *
 * After running it, bump $iconVersion in resources/views/partials/favicon.blade.php
 * and VERSION in public/sw.js, or browsers keep showing the old icon.
 */

// A 512px icon at 8x is a 4096px canvas: ~64 MB before GD's own overhead.
ini_set('memory_limit', '512M');

const NAVY = [0x17, 0x30, 0x4f];
const WHITE = [0xff, 0xff, 0xff];
const ORANGE = [0xe8, 0x74, 0x3b];
const SS = 8;

$out = dirname(__DIR__).'/public/images/icons';

/*
 * name => [size, glyph scale (share of the tile the 64-unit drawing fills), shape]
 *   rounded  transparent corners — tab strips and "any" launcher icons
 *   square   opaque, full bleed — iOS composites transparency onto black, and a
 *            maskable icon is cropped by the launcher, so its glyph stays inside
 *            the central 80% safe zone
 */
$icons = [
    'favicon-16.png' => [16, 0.92, 'rounded'],
    'favicon-32.png' => [32, 0.86, 'rounded'],
    'favicon-48.png' => [48, 0.82, 'rounded'],
    'icon-192.png' => [192, 0.78, 'rounded'],
    'icon-512.png' => [512, 0.78, 'rounded'],
    'apple-touch-icon.png' => [180, 0.72, 'square'],
    'icon-maskable-192.png' => [192, 0.6, 'square'],
    'icon-maskable-512.png' => [512, 0.6, 'square'],
];

foreach ($icons as $name => [$size, $scale, $shape]) {
    $big = $size * SS;
    $img = imagecreatetruecolor($big, $big);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefilledrectangle($img, 0, 0, $big - 1, $big - 1, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagealphablending($img, true);

    $navy = imagecolorallocate($img, ...NAVY);
    if ($shape === 'rounded') {
        roundedRect($img, $big, $big * 14 / 64, $navy);
    } else {
        imagefilledrectangle($img, 0, 0, $big - 1, $big - 1, $navy);
    }

    // Map drawing units to pixels, centred on the tile.
    $unit = $big * $scale / 64;
    $offset = ($big - 64 * $unit) / 2;
    $at = static fn (float $x, float $y): array => [$offset + $x * $unit, $offset + $y * $unit];

    $white = imagecolorallocate($img, ...WHITE);
    foreach ([0, 90, 180, 270] as $turn) {
        $rotate = static function (float $x, float $y) use ($turn): array {
            $r = deg2rad($turn);
            $dx = $x - 32;
            $dy = $y - 32;

            return [32 + $dx * cos($r) - $dy * sin($r), 32 + $dx * sin($r) + $dy * cos($r)];
        };

        [$hx, $hy] = $at(...$rotate(32, 6.5));
        imagefilledellipse($img, (int) round($hx), (int) round($hy), (int) round(9 * $unit), (int) round(9 * $unit), $white);

        // The shoulders as a polygon: the half-ellipse traced in 48 steps.
        $points = [];
        for ($i = 0; $i <= 48; $i++) {
            $a = M_PI * $i / 48;
            [$px, $py] = $at(...$rotate(32 - 8 * cos($a), 19.5 - 6.5 * sin($a)));
            array_push($points, (int) round($px), (int) round($py));
        }
        imagefilledpolygon($img, $points, $white);
    }

    [$cx, $cy] = $at(32, 32);
    imagefilledellipse($img, (int) round($cx), (int) round($cy), (int) round(10 * $unit), (int) round(10 * $unit), imagecolorallocate($img, ...ORANGE));

    $small = imagecreatetruecolor($size, $size);
    imagealphablending($small, false);
    imagesavealpha($small, true);
    imagecopyresampled($small, $img, 0, 0, 0, 0, $size, $size, $big, $big);
    imagepng($small, "$out/$name", 9);
    unset($img, $small);
    echo "$name ({$size}x{$size})\n";
}

function roundedRect(GdImage $img, int $size, float $radius, int $colour): void
{
    $r = (int) round($radius);
    imagefilledrectangle($img, $r, 0, $size - 1 - $r, $size - 1, $colour);
    imagefilledrectangle($img, 0, $r, $size - 1, $size - 1 - $r, $colour);
    foreach ([[$r, $r], [$size - 1 - $r, $r], [$r, $size - 1 - $r], [$size - 1 - $r, $size - 1 - $r]] as [$x, $y]) {
        imagefilledellipse($img, $x, $y, $r * 2, $r * 2, $colour);
    }
}
