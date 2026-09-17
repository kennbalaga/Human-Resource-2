<?php

namespace App\Services\Burnout;

use App\Models\BurnoutRiskSnapshot;

/**
 * Turns one window of workload figures into a 0-100 score and a level.
 *
 * Pure arithmetic over config('burnout.factors'), with no database access, so
 * the scoring rules can be tested and tuned without building a roster first.
 */
class BurnoutRiskCalculator
{
    public const TREND_RISING = 'rising';

    public const TREND_EASING = 'easing';

    public const TREND_STEADY = 'steady';

    /** No earlier window to compare against, typically a recent hire. */
    public const TREND_NEW = 'new';

    /**
     * @param  array<string, int|float>  $metrics  Keyed like config('burnout.factors')
     * @return array{score: float, level: string, factors: array<string, array<string, mixed>>}
     */
    public function score(array $metrics): array
    {
        $factors = [];

        foreach (config('burnout.factors') as $key => $factor) {
            $value = (float) ($metrics[$key] ?? 0);
            $span = (float) $factor['to'] - (float) $factor['from'];
            $ratio = $span > 0
                ? min(1, max(0, ($value - (float) $factor['from']) / $span))
                : ($value >= (float) $factor['to'] ? 1.0 : 0.0);

            $factors[$key] = [
                'label' => $factor['label'],
                'unit' => $factor['unit'],
                'value' => round($value, 1),
                'from' => $factor['from'],
                'to' => $factor['to'],
                'points' => round($ratio * (float) $factor['points'], 2),
                'maximum' => (float) $factor['points'],
            ];
        }

        $score = round(min(100, (float) array_sum(array_column($factors, 'points'))), 2);

        return [
            'score' => $score,
            'level' => $this->levelFor($score),
            'factors' => $factors,
        ];
    }

    public function levelFor(float $score): string
    {
        return match (true) {
            $score >= (float) config('burnout.high_score') => BurnoutRiskSnapshot::LEVEL_HIGH,
            $score >= (float) config('burnout.moderate_score') => BurnoutRiskSnapshot::LEVEL_MODERATE,
            default => BurnoutRiskSnapshot::LEVEL_LOW,
        };
    }

    public function trend(float $score, ?float $previous): string
    {
        if ($previous === null) {
            return self::TREND_NEW;
        }

        $threshold = (float) config('burnout.trend_threshold');

        return match (true) {
            $score - $previous >= $threshold => self::TREND_RISING,
            $previous - $score >= $threshold => self::TREND_EASING,
            default => self::TREND_STEADY,
        };
    }
}
