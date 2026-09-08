<?php

namespace App\Reports;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * A report the Insights module can show and export.
 *
 * Everything a report needs to exist is declared here once: what it is called,
 * which filters it honours, which columns it has, how its rows are found and
 * what its summary says. The hub, the table view, the three exporters and the
 * read-only route guard all read those declarations rather than each carrying
 * their own copy -- which is what let the attendance report's screen, CSV and
 * PDF disagree about their own contents.
 *
 * Department scoping is the one thing a subclass must not be trusted to
 * remember, so it is applied here, in `scopedQuery()`, ahead of any filter the
 * request supplied. A report that forgets it does not leak quietly; it cannot
 * be written at all, because nothing else calls `query()` directly.
 */
abstract class Report
{
    /** Stable identifier used in routes, filenames and the route guard. */
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /** An icon name understood by the `x-icon` component. */
    abstract public function icon(): string;

    /**
     * The page title. `label()` is the short form the tab strip uses, so the
     * heading is spelled out separately rather than being the tab text with a
     * word glued on -- "Timesheets Reports" being the reason that matters.
     */
    public function heading(): string
    {
        return $this->label().' Reports';
    }

    /** @return array<int, ReportColumn> */
    abstract public function columns(): array;

    /**
     * The report's rows, before scoping. Never call this directly -- it is
     * public only so `scopedQuery()` can reach it.
     *
     * @param  array<string, mixed>  $filters
     */
    abstract public function query(array $filters): Builder;

    /**
     * Narrow an unscoped query to what this user supervises. Attendance, leave
     * and timesheets all hang off an `employee` relation, so the default suits
     * every report written so far.
     */
    abstract public function applyScope(Builder $query, ?User $user): Builder;

    /**
     * Tiles shown above the table.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, value: string, icon: string, tone: string}>
     */
    abstract public function summary(array $filters, ?User $user): array;

    /**
     * Filter controls this report honours. The shared filter form renders only
     * these, so a report is never offered a control that its query ignores.
     *
     * @return array<int, string>
     */
    abstract public function filters(): array;

    /**
     * The dates the range filter names on this report. Attendance filters a
     * single `attendance_date`; timesheets have a period with two ends.
     */
    public function rangeLabel(): string
    {
        return 'Date range';
    }

    /**
     * The inclusive bounds of the reporting period, as query bindings.
     *
     * These are asymmetric on purpose, because this schema stores date columns
     * two different ways. Eloquent's `date` cast serialises through the model's
     * datetime format, so `attendance_date` holds `2026-08-10 00:00:00`; the
     * ScheduleDate cast on `work_date` returns `toDateString()`, so that column
     * holds a bare `2026-08-10`. One pair of bounds has to cover both:
     *
     *   - The lower bound is the bare date. `2026-08-10` sorts at or before
     *     both spellings of that day; a datetime lower bound of
     *     `2026-08-10 00:00:00` would sort *after* the bare `2026-08-10` and
     *     drop the first day of a roster range.
     *   - The upper bound runs to the end of the last day, so the datetime
     *     spelling of that day is not left just past it. A bare upper bound
     *     was the bug that made every single-day report come back empty.
     *
     * Doing this with whereDate() instead would sidestep the whole question by
     * wrapping the column in DATE() -- at the cost of the index the range scan
     * exists to use.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function rangeStart(array $filters): string
    {
        return Carbon::parse($filters['date_from'])->toDateString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function rangeEnd(array $filters): string
    {
        return Carbon::parse($filters['date_to'])->endOfDay()->toDateTimeString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    final public function scopedQuery(array $filters, ?User $user): Builder
    {
        return $this->applyScope($this->query($filters), $user);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, ?User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $this->scopedQuery($filters, $user)->paginate($perPage)->withQueryString();
    }

    /**
     * Rows for an export, read in chunks so a long range never sits in memory
     * in its entirety.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, object>
     */
    public function rows(array $filters, ?User $user): LazyCollection
    {
        return $this->scopedQuery($filters, $user)->lazy();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function countRows(array $filters, ?User $user): int
    {
        return $this->scopedQuery($filters, $user)->toBase()->getCountForPagination();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filename(array $filters, string $extension): string
    {
        return sprintf(
            '%s-%s-to-%s.%s',
            $this->key(),
            $filters['date_from'] ?? 'start',
            $filters['date_to'] ?? 'end',
            $extension,
        );
    }

    /**
     * The screen this report renders on.
     *
     * Most reports are a filter bar, a row of tiles and a table, and the shared
     * view draws all three straight from the column and summary declarations.
     * A report overrides this only when its table earns bespoke cells.
     */
    public function view(): string
    {
        return 'reports.show';
    }
}
