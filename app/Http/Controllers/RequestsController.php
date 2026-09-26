<?php

namespace App\Http\Controllers;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\PreferredDayOff;
use App\Models\ShiftSwapRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Everything this employee has asked for, in one list.
 *
 * Leave, shift swaps and preferred days off are three modules with three
 * controllers and three screens. To the person filing them they are one act —
 * asking for something and waiting on an answer — and the question they come
 * back with is "where has my request got to", which none of the three pages
 * answers on its own.
 *
 * Read-only on purpose. Filing still happens on the pages that own each kind,
 * which is where the forms, the validation and the permissions already live;
 * duplicating any of that here would be a second place for the rules to be
 * wrong. Every row links back to its own page to act on.
 */
class RequestsController extends Controller
{
    /** How far back the list reaches. Older than this belongs to a report. */
    private const RECENT = 25;

    public function __invoke(Request $request): View
    {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403, 'Your user account is not linked to an employee profile.');

        $filter = in_array($request->query('type'), ['leave', 'swap', 'day-off'], true)
            ? $request->query('type')
            : 'all';

        $items = collect()
            ->concat($this->leave($employee->id))
            ->concat($this->swaps($employee->id))
            ->concat($this->daysOff($employee->id))
            // One list, newest first, whatever kind each row is — the whole
            // point of merging them.
            ->sortByDesc('sort_at')
            ->values();

        return view('requests.index', [
            'filter' => $filter,
            'items' => $filter === 'all' ? $items : $items->where('kind', $filter)->values(),
            'counts' => [
                'all' => $items->count(),
                'leave' => $items->where('kind', 'leave')->count(),
                'swap' => $items->where('kind', 'swap')->count(),
                'day-off' => $items->where('kind', 'day-off')->count(),
            ],
            'awaiting' => $items->where('tone', 'warning')->count(),
            'balances' => $this->balances($employee->id),
            'canSwap' => $employee->canUseShiftSwaps(),
        ]);
    }

    /**
     * The balance-backed types only, biggest first.
     *
     * Read directly rather than through LeaveService::balancesFor, which
     * creates the rows it cannot find — correct when somebody is about to file
     * against them, wrong as a side effect of looking at a summary.
     *
     * @return Collection<int, LeaveBalance>
     */
    private function balances(int $employeeId): Collection
    {
        return LeaveBalance::query()
            ->with('leaveType')
            ->where('employee_id', $employeeId)
            ->where('year', (int) now()->format('Y'))
            ->get()
            ->filter(fn (LeaveBalance $balance): bool => $balance->leaveType?->isBalanceBacked() ?? false)
            ->sortByDesc(fn (LeaveBalance $balance): float => $balance->available_days)
            ->take(2)
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function leave(int $employeeId): Collection
    {
        return LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employeeId)
            ->latest()
            ->limit(self::RECENT)
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                'kind' => 'leave',
                'kind_label' => $leave->leaveType?->name ?? 'Leave',
                'title' => $this->dateRange($leave->start_date, $leave->end_date)
                    .' · '.rtrim(rtrim(number_format((float) $leave->requested_days, 1), '0'), '.').' '
                    .str('day')->plural((float) $leave->requested_days),
                'detail' => $leave->reviewer_notes ?: $leave->reason,
                'status_label' => ucfirst($leave->status),
                'tone' => $this->tone($leave->status),
                'sort_at' => $leave->created_at,
                'url' => route('leaves.index'),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function swaps(int $employeeId): Collection
    {
        return ShiftSwapRequest::query()
            ->with(['requesterEmployee', 'targetEmployee', 'requesterAssignment.shift'])
            ->where(fn ($query) => $query
                ->where('requester_employee_id', $employeeId)
                ->orWhere('target_employee_id', $employeeId))
            ->latest()
            ->limit(self::RECENT)
            ->get()
            ->map(function (ShiftSwapRequest $swap) use ($employeeId): array {
                $iAsked = $swap->requester_employee_id === $employeeId;
                $other = $iAsked ? $swap->targetEmployee : $swap->requesterEmployee;
                $assignment = $swap->requesterAssignment;

                return [
                    'kind' => 'swap',
                    'kind_label' => 'Shift swap',
                    'title' => $assignment?->work_date
                        ? $assignment->work_date->format('D j M')
                            .($assignment->shift ? ' · '.$assignment->shift->name : '')
                        : 'Shift swap',
                    'detail' => $iAsked
                        ? 'You asked '.($other?->full_name ?? 'a colleague').' to take this shift.'
                        : ($other?->full_name ?? 'A colleague').' asked you to take this shift.',
                    'status_label' => $this->swapStatus($swap, $iAsked, $other?->first_name),
                    'tone' => $this->tone($swap->status),
                    'sort_at' => $swap->created_at,
                    'url' => route('schedule-preferences.index').'#shift-swaps',
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function daysOff(int $employeeId): Collection
    {
        return PreferredDayOff::query()
            ->where('employee_id', $employeeId)
            ->latest()
            ->limit(self::RECENT)
            ->get()
            ->map(fn (PreferredDayOff $dayOff): array => [
                'kind' => 'day-off',
                'kind_label' => 'Preferred day off',
                'title' => $dayOff->preferred_date->format('l, j F'),
                'detail' => $dayOff->reviewer_notes ?: $dayOff->reason,
                'status_label' => ucfirst($dayOff->status),
                'tone' => $this->tone($dayOff->status),
                'sort_at' => $dayOff->created_at,
                'url' => route('schedule-preferences.index'),
            ]);
    }

    /**
     * A swap has two waiting states and they are not the same news: one is on a
     * colleague, the other is on a manager. Saying "Pending" for both is how an
     * employee ends up chasing the wrong person.
     */
    private function swapStatus(ShiftSwapRequest $swap, bool $iAsked, ?string $otherFirstName): string
    {
        return match ($swap->status) {
            'pending_target' => $iAsked
                ? 'Waiting on '.($otherFirstName ?? 'your colleague')
                : 'Waiting on you',
            'pending_manager' => 'With your manager',
            'approved' => 'Approved',
            'rejected' => 'Declined',
            'cancelled' => 'Withdrawn',
            default => ucfirst(str_replace('_', ' ', $swap->status)),
        };
    }

    private function tone(string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'rejected' => 'danger',
            'cancelled' => 'secondary',
            default => 'warning',
        };
    }

    private function dateRange(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('j M Y');
        }

        return $start->month === $end->month && $start->year === $end->year
            ? $start->format('j').' – '.$end->format('j M Y')
            : $start->format('j M').' – '.$end->format('j M Y');
    }
}
