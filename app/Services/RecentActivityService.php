<?php

namespace App\Services;

use App\Models\AuditLog;
use Carbon\Carbon;

/**
 * The dashboard's activity feed, read straight off the audit trail.
 *
 * Nothing here is a second record of anything: the audit log is already written
 * for every authenticated write request, and this only puts a readable face on
 * the newest few. It records route names — "leaves.approve" — so the work is
 * turning those back into a sentence a person can scan.
 *
 * It is deliberately not cached. A feed whose whole claim is "just now" cannot
 * be a minute stale, and it is one indexed query for a handful of rows.
 */
class RecentActivityService
{
    /**
     * Route verbs, in the words somebody would use for what happened. The verb is
     * the last segment of the action; anything not listed keeps its own name
     * rather than being forced into a guess.
     *
     * @var array<string, string>
     */
    private const VERBS = [
        'store' => 'Created',
        'update' => 'Updated',
        'destroy' => 'Deleted',
        'approve' => 'Approved',
        'reject' => 'Rejected',
        'cancel' => 'Cancelled',
        'submit' => 'Submitted',
        'reissue' => 'Reissued',
        'reset' => 'Reset',
        'export' => 'Exported',
        'import' => 'Imported',
        'publish' => 'Published',
        'restore' => 'Restored',
    ];

    /**
     * @return array<int, array{
     *     id: int,
     *     actor: string,
     *     summary: string,
     *     subject: string|null,
     *     time_label: string,
     *     day_label: string,
     *     failed: bool,
     * }>
     */
    public function latest(int $limit = 6): array
    {
        $timezone = config('workforce.timezone', 'Asia/Manila');
        $today = Carbon::now($timezone)->toDateString();

        return AuditLog::query()
            ->with('user:id,name')
            ->latest('created_at')
            // Requests written inside the same second are ordered by insertion,
            // so the feed does not reshuffle its own top rows between reloads.
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (AuditLog $log) use ($timezone, $today): array {
                $at = $log->created_at->timezone($timezone);

                return [
                    'id' => $log->id,
                    // An unauthenticated write is a real thing the trail records,
                    // and naming it is more use than an empty byline.
                    'actor' => $log->user?->name ?? 'System',
                    'summary' => $this->summarise($log->action),
                    'subject' => $log->subject_type
                        ? class_basename($log->subject_type).' #'.$log->subject_id
                        : null,
                    'time_label' => $at->format('g:i A'),
                    'day_label' => $at->toDateString() === $today ? 'Today' : $at->format('D, M j'),
                    // A rejected or failed write is the row on this list most
                    // worth noticing, so it is carried rather than smoothed over.
                    'failed' => $log->response_status >= 400,
                ];
            })
            ->all();
    }

    /** Turn a route name such as "leaves.approve" into "Approved leave". */
    private function summarise(string $action): string
    {
        $segments = explode('.', $action);
        $verb = array_pop($segments);

        if ($segments === [] || ! isset(self::VERBS[$verb])) {
            return (string) str($action)->replace('.', ' ')->headline();
        }

        $subject = str(implode(' ', $segments))->replace(['-', '_'], ' ')->singular()->lower();

        return self::VERBS[$verb].' '.$subject;
    }
}
