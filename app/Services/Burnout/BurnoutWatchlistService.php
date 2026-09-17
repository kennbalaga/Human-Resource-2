<?php

namespace App\Services\Burnout;

use App\Models\BurnoutRiskSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * "Closest to burnout": the handful of people a manager should look at first,
 * for the organisation dashboard.
 *
 * The Burnout Risk tab lists everyone. This names only the top few, highest
 * score first, so HR sees who is nearest the edge the moment they sign in
 * instead of having to go looking. It reads the same assessments as the tab,
 * narrowed the same way: a department head sees their own unit, HR sees the
 * hospital.
 *
 * Cached for a few minutes per scope, like the other panels that name people.
 * An assessment only changes once a day, so the cache costs nothing in
 * accuracy, and the dashboard is the page where round trips hurt most.
 */
class BurnoutWatchlistService
{
    /** Names shown. The rest are a count with a link to the tab. */
    public const LIST_LIMIT = 5;

    private const CACHE_SECONDS = 300;

    public function __construct(private readonly BurnoutRiskService $risk) {}

    /**
     * @return array{
     *     people: array<int, array<string, mixed>>,
     *     assessed: int,
     *     high: int,
     *     moderate: int,
     *     rising: int,
     *     at_risk_beyond_list: int,
     * }
     */
    public function forUser(User $user): array
    {
        return Cache::remember(
            'dashboard.burnout-watchlist.v1.'.$this->scopeKey($user).'.'.$this->risk->today()->toDateString(),
            now()->addSeconds(self::CACHE_SECONDS),
            fn (): array => $this->build($user),
        );
    }

    /** @return array<string, mixed> */
    private function build(User $user): array
    {
        $rows = $this->risk->workforce($user)
            ->sortBy([
                fn (array $a, array $b) => $b['risk']['score'] <=> $a['risk']['score'],
                fn (array $a, array $b) => strcmp($a['employee']->last_name, $b['employee']->last_name),
            ])
            ->values();

        $atRisk = $rows->whereIn('risk.level', [BurnoutRiskSnapshot::LEVEL_HIGH, BurnoutRiskSnapshot::LEVEL_MODERATE])->count();
        $people = $rows->take(self::LIST_LIMIT);

        return [
            // Plain arrays only: the cache refuses to rebuild objects.
            'people' => $people->map(fn (array $row): array => [
                'name' => $row['employee']->full_name,
                'position' => $row['employee']->position?->title,
                'department' => $row['employee']->department?->name,
                'department_id' => $row['employee']->department_id,
                'score' => $row['risk']['score'],
                'level' => $row['risk']['level'],
                'level_label' => $row['risk']['level_label'],
                'trend' => $row['risk']['trend'],
                'change' => $row['risk']['change'],
                'driver' => $row['risk']['drivers'][0]['label'] ?? null,
            ])->all(),
            'assessed' => $rows->count(),
            'high' => $rows->where('risk.level', BurnoutRiskSnapshot::LEVEL_HIGH)->count(),
            'moderate' => $rows->where('risk.level', BurnoutRiskSnapshot::LEVEL_MODERATE)->count(),
            'rising' => $rows->where('risk.trend', BurnoutRiskCalculator::TREND_RISING)->count(),
            'at_risk_beyond_list' => max(0, $atRisk - $people->whereIn('risk.level', [BurnoutRiskSnapshot::LEVEL_HIGH, BurnoutRiskSnapshot::LEVEL_MODERATE])->count()),
        ];
    }

    /** Mirrors DailyExceptionsService: the payload names people, so the reach is part of the key. */
    private function scopeKey(User $user): string
    {
        $departmentIds = $user->supervisedDepartmentIds();

        return match (true) {
            $departmentIds === null => 'all',
            $departmentIds === [] => 'none',
            default => 'departments-'.implode('-', collect($departmentIds)->sort()->values()->all()),
        };
    }
}
