<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Requests\Analytics\BurnoutRiskRequest;
use App\Models\Employee;
use App\Services\Burnout\BurnoutRiskService;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Workforce Analytics > Burnout Risk: today's assessment for everyone the
 * viewer supervises, highest risk first.
 *
 * Kept off the Overview tab on purpose. The overview is aggregate-only -- its
 * figures are what the Gemini insight button sends out -- and a list of named
 * people with a wellbeing label does not belong in that payload. The overview
 * carries the counts; the names live here.
 */
class BurnoutRiskController extends Controller
{
    use ScopesWorkforceAccess;

    private const PER_PAGE = 25;

    /** Width and height of a row's sparkline, in SVG user units. */
    private const SPARK_WIDTH = 96;

    private const SPARK_HEIGHT = 28;

    public function index(BurnoutRiskRequest $request, BurnoutRiskService $risk): Response
    {
        $filters = $request->validated();

        $rows = $risk->workforce(
            $request->user(),
            isset($filters['department_id']) ? (int) $filters['department_id'] : null,
        );

        $listed = $rows
            ->when($filters['level'] ?? null, fn (Collection $rows, string $level) => $rows->where('risk.level', $level))
            ->when($filters['trend'] ?? null, fn (Collection $rows, string $trend) => $rows->where('risk.trend', $trend))
            ->sortBy([
                fn (array $a, array $b) => $b['risk']['score'] <=> $a['risk']['score'],
                fn (array $a, array $b) => strcmp($a['employee']->last_name, $b['employee']->last_name),
            ])
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $listed->forPage($page, self::PER_PAGE)->values(),
            $listed->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $history = $risk->history(collect($paginator->items())->pluck('employee.id')->all());

        $results = [
            'filters' => $filters,
            'summary' => [
                'assessed' => $rows->count(),
                'high' => $rows->where('risk.level', 'high')->count(),
                'moderate' => $rows->where('risk.level', 'moderate')->count(),
                'low' => $rows->where('risk.level', 'low')->count(),
                'rising' => $rows->where('risk.trend', 'rising')->count(),
                'average' => round((float) ($rows->avg('risk.score') ?? 0), 1),
            ],
            'departmentBreakdown' => $this->departmentBreakdown($rows),
            'rows' => $paginator,
            'sparklines' => $history->map(fn (Collection $points) => $this->sparkline($points)),
        ];

        // The live filters ask for the results alone. The same URL serves the
        // whole page to a normal visit, so the response says it varies on the
        // header, or a Back button could be handed the fragment.
        if ($request->ajax()) {
            return response()
                ->view('analytics._burnout-risk-results', $results)
                ->header('Vary', 'X-Requested-With');
        }

        return response()
            ->view('analytics.burnout-risk', $results + [
                'departments' => $this->selectableDepartments($request),
                'asOf' => $risk->today(),
                'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
            ])
            ->header('Vary', 'X-Requested-With');
    }

    /**
     * @param  Collection<int, array{employee: Employee, risk: array<string, mixed>}>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function departmentBreakdown(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (array $row) => (int) $row['employee']->department_id)
            ->map(function (Collection $group): array {
                $department = $group->first()['employee']->department;

                return [
                    'name' => $department?->name ?? 'No department',
                    'code' => $department?->code,
                    'assessed' => $group->count(),
                    'high' => $group->where('risk.level', 'high')->count(),
                    'moderate' => $group->where('risk.level', 'moderate')->count(),
                    'rising' => $group->where('risk.trend', 'rising')->count(),
                    'average' => round((float) $group->avg('risk.score'), 1),
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => $b['high'] <=> $a['high'],
                fn (array $a, array $b) => $b['moderate'] <=> $a['moderate'],
                fn (array $a, array $b) => $b['average'] <=> $a['average'],
            ])
            ->values();
    }

    /**
     * An SVG polyline for a run of daily scores, on a fixed 0-100 scale so two
     * rows can be compared by eye. Null when there is nothing to draw a line
     * between yet.
     *
     * @param  Collection<int, array{date: string, score: float}>  $points
     */
    private function sparkline(Collection $points): ?string
    {
        if ($points->count() < 2) {
            return null;
        }

        $step = self::SPARK_WIDTH / ($points->count() - 1);

        return $points
            ->values()
            ->map(fn (array $point, int $index) => round($index * $step, 1).','.round(self::SPARK_HEIGHT - min(100, max(0, $point['score'])) / 100 * self::SPARK_HEIGHT, 1))
            ->implode(' ');
    }
}
