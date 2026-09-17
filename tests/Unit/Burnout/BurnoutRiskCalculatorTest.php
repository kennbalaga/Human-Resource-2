<?php

namespace Tests\Unit\Burnout;

use App\Services\Burnout\BurnoutRiskCalculator;
use Tests\TestCase;

class BurnoutRiskCalculatorTest extends TestCase
{
    private BurnoutRiskCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'burnout.moderate_score' => 35,
            'burnout.high_score' => 60,
            'burnout.trend_threshold' => 5,
        ]);
        $this->calculator = app(BurnoutRiskCalculator::class);
    }

    public function test_the_factor_points_add_up_to_one_hundred(): void
    {
        $this->assertSame(100, array_sum(array_column(config('burnout.factors'), 'points')));
    }

    public function test_an_ordinary_month_scores_nothing(): void
    {
        $result = $this->calculator->score([
            'weekly_hours' => 40,
            'overtime_hours' => 0,
            'work_streak' => 5,
            'night_shifts' => 4,
            'short_rest' => 0,
            'days_since_leave' => 60,
            'unplanned_leave' => 1,
        ]);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('low', $result['level']);
    }

    public function test_every_factor_at_its_ceiling_is_the_full_score(): void
    {
        $result = $this->calculator->score([
            'weekly_hours' => 70,
            'overtime_hours' => 40,
            'work_streak' => 12,
            'night_shifts' => 20,
            'short_rest' => 9,
            'days_since_leave' => 400,
            'unplanned_leave' => 8,
        ]);

        $this->assertSame(100.0, $result['score']);
        $this->assertSame('high', $result['level']);
        $this->assertSame(25.0, $result['factors']['weekly_hours']['points']);
    }

    public function test_a_factor_rises_in_a_straight_line_between_its_floor_and_ceiling(): void
    {
        // 48 hours is halfway from 40 to 56, so half of the factor's 25 points.
        $result = $this->calculator->score(['weekly_hours' => 48]);

        $this->assertSame(12.5, $result['factors']['weekly_hours']['points']);
        $this->assertSame(12.5, $result['score']);
    }

    public function test_the_level_follows_the_configured_thresholds(): void
    {
        $this->assertSame('low', $this->calculator->levelFor(34.99));
        $this->assertSame('moderate', $this->calculator->levelFor(35));
        $this->assertSame('moderate', $this->calculator->levelFor(59.99));
        $this->assertSame('high', $this->calculator->levelFor(60));
    }

    public function test_the_trend_ignores_changes_below_the_threshold(): void
    {
        $this->assertSame('rising', $this->calculator->trend(50, 40));
        $this->assertSame('easing', $this->calculator->trend(30, 40));
        $this->assertSame('steady', $this->calculator->trend(44, 40));
        $this->assertSame('new', $this->calculator->trend(44, null));
    }
}
