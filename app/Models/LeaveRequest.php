<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    use HasFactory;

    /** Where a request stands with its reviewer. Only these are ever stored. */
    public const WORKFLOW_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    /** Where an approved request stands against the calendar. Never stored. */
    public const PHASES = ['upcoming', 'ongoing', 'completed'];

    /** Everything a status filter may legitimately be narrowed to. */
    public const FILTERABLE_STATUSES = [...self::WORKFLOW_STATUSES, ...self::PHASES];

    protected $fillable = [
        'uuid',
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'requested_days',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'reviewer_notes',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'requested_days' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Where an approved request sits against today: still to come, being taken
     * right now, or already behind us. Null for anything not approved -- a
     * rejected request never has a phase to be in.
     *
     * This is derived on read rather than written into `status`, and that is
     * deliberate. Roughly fifteen queries outside this module ask for approved
     * leave overlapping a date range -- the schedule board, the absence
     * calculator, the attendance overview, the daily exceptions list, shift
     * eligibility, analytics. Retiring past leave to a stored `completed` would
     * quietly drop it out of every one of them, so history would start
     * disagreeing with itself the moment somebody's leave ended. Deriving it
     * also means nothing to schedule and nothing that can drift: the answer
     * changes the instant the clock does.
     */
    public function getPhaseAttribute(): ?string
    {
        if ($this->status !== 'approved') {
            return null;
        }

        // Compared as plain Y-m-d strings. These are DATE columns with no time
        // of day, so anchoring the comparison to a moment would only invite an
        // offset to push a boundary onto the wrong side of midnight.
        $today = now(config('workforce.timezone'))->toDateString();

        return match (true) {
            $this->start_date->toDateString() > $today => 'upcoming',
            $this->end_date->toDateString() < $today => 'completed',
            default => 'ongoing',
        };
    }

    /**
     * The status people are actually shown: the phase once a request has been
     * approved, and the workflow state until then.
     */
    public function getLifecycleStatusAttribute(): string
    {
        return $this->phase ?? $this->status;
    }

    /**
     * Whether this request can still be withdrawn.
     *
     * Cancelling hands the days back to the employee's balance, which only
     * makes sense while there is still leave left to give back. Once the last
     * day has passed the time off has been taken -- the employee was away, the
     * roster was built around it, and the absence reports have counted it --
     * so refunding the credits would pay somebody twice for the same week.
     *
     * Leave being taken right now is deliberately still cancellable: returning
     * early is a real thing that happens, and refusing it outright would leave
     * nowhere to record it.
     */
    public function isCancellable(): bool
    {
        return in_array($this->status, ['pending', 'approved'], true)
            && $this->phase !== 'completed';
    }

    /**
     * Narrow to one lifecycle status, phases included.
     *
     * The date comparisons are left as plain `where` rather than `whereDate` so
     * they stay sargable against the (status, start_date, end_date) index.
     */
    public function scopeWhereLifecycleStatus(Builder $query, string $status): Builder
    {
        $today = now(config('workforce.timezone'))->toDateString();

        return match ($status) {
            'upcoming' => $query->where('status', 'approved')
                ->where('start_date', '>', $today),
            'ongoing' => $query->where('status', 'approved')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today),
            'completed' => $query->where('status', 'approved')
                ->where('end_date', '<', $today),
            default => $query->where('status', $status),
        };
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveAttachment::class);
    }
}
