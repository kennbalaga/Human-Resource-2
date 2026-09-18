<?php

namespace App\Support;

/**
 * Minutes and part-days, written the way people read them.
 *
 * Four views had grown their own identical copy of this pair of closures. A
 * payslip and its own printout disagreeing about what "7h 30m" looks like is
 * the failure this exists to prevent, so the two payslip views call in here
 * instead. The other copies (payslips/index, timesheets/index) still have
 * theirs and can adopt this when they are next touched.
 */
final class Duration
{
    /** 450 => "7h 30m". Minutes are padded so a column of them lines up. */
    public static function hm(int $minutes): string
    {
        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }

    /** 1.0 => "1", 1.5 => "1.5". A half day is real; a trailing ".0" is noise. */
    public static function days(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1), '0'), '.');
    }
}
