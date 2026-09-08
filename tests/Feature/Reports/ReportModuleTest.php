<?php

namespace Tests\Feature\Reports;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The reports module had no test file of its own. What coverage existed was
 * incidental -- two smoke tests on the attendance page, the department scoping
 * suite and the spreadsheet injection suite -- so the summary arithmetic, the
 * range cap, the export ceilings and the PDF writer were all unverified. The
 * PDF export had never been executed by a test at all.
 */
class ReportModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function hr(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // The hub and the report types
    // -----------------------------------------------------------------

    public function test_reports_opens_on_attendance_with_every_report_reachable(): void
    {
        // /reports is the attendance report itself, not a landing page in front
        // of it -- the tab strip is what moves between the three.
        $this->actingAs($this->hr())
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Attendance Reports')
            ->assertSee(route('reports.show', ['report' => 'leave']), false)
            ->assertSee(route('reports.show', ['report' => 'timesheet']), false);
    }

    public function test_each_report_renders_its_own_screen(): void
    {
        foreach (['attendance', 'leave', 'timesheet'] as $report) {
            $this->actingAs($this->hr())
                ->get(route('reports.show', ['report' => $report]))
                ->assertOk();
        }
    }

    public function test_an_unknown_report_is_not_a_route(): void
    {
        $this->actingAs($this->hr())
            ->get('/reports/payroll')
            ->assertNotFound();
    }

    public function test_a_standard_employee_cannot_open_any_report(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('reports.show', ['report' => 'leave']))->assertForbidden();
    }

    public function test_the_legacy_attendance_urls_still_serve_their_screen(): void
    {
        // Linked from the dashboard, both exception panels, the welcome tour
        // and DailyExceptionsService -- these must not become redirects.
        $this->actingAs($this->hr())
            ->get('/attendance/reports')
            ->assertOk()
            ->assertSee('Attendance Reports');
    }

    // -----------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------

    public function test_a_report_ignores_a_filter_it_does_not_declare(): void
    {
        // capture_method belongs to attendance. Passing it to the leave report
        // must not reach the query, which has no such column to filter on.
        $this->actingAs($this->hr())
            ->get(route('reports.show', ['report' => 'leave', 'capture_method' => 'biometric']))
            ->assertOk();
    }

    public function test_the_range_cap_is_enforced(): void
    {
        $this->actingAs($this->hr())
            ->get(route('reports.show', [
                'report' => 'attendance',
                'date_from' => '2026-01-01',
                'date_to' => '2026-12-31',
            ]))
            ->assertSessionHasErrors('date_to');
    }

    public function test_a_single_day_range_still_returns_that_day(): void
    {
        // A date column is stored with a time, so a BETWEEN against the bare
        // date string puts the whole final day past the upper bound -- which
        // silently emptied every single-day report.
        $record = $this->attendanceRecordFor($this->nurse(), '2026-08-14');

        $this->actingAs($this->hr())
            ->get(route('reports.show', [
                'report' => 'attendance',
                'date_from' => '2026-08-14',
                'date_to' => '2026-08-14',
            ]))
            ->assertOk()
            ->assertSee($record->employee->full_name);
    }

    // -----------------------------------------------------------------
    // Summary arithmetic
    // -----------------------------------------------------------------

    public function test_a_late_arrival_counts_as_present(): void
    {
        $employee = $this->nurse();
        $this->attendanceRecordFor($employee, '2026-08-10', status: 'late');

        $response = $this->actingAs($this->hr())->get(route('reports.show', [
            'report' => 'attendance',
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-10',
        ]))->assertOk();

        $summary = collect($response->viewData('summary'))->keyBy('label');

        // Somebody who clocked in behind the grace period still came to work.
        $this->assertSame('1', $summary['Present']['value']);
        $this->assertSame('1', $summary['Late']['value']);
    }

    public function test_absence_is_counted_across_a_multi_day_range(): void
    {
        $employee = $this->nurse();
        // Rostered for two days, attended one.
        $this->roster($employee, ['2026-08-10', '2026-08-11']);
        $this->attendanceRecordFor($employee, '2026-08-10');

        $summary = $this->summaryFor('2026-08-10', '2026-08-11', $employee);

        // The old report printed an em dash for any range wider than a day.
        $this->assertSame('1', $summary['Absent']['value']);
    }

    public function test_approved_leave_is_not_counted_as_absence(): void
    {
        $employee = $this->nurse();
        $this->roster($employee, ['2026-08-10', '2026-08-11']);
        $this->attendanceRecordFor($employee, '2026-08-10');
        LeaveRequest::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->value('id'),
            'start_date' => '2026-08-11',
            'end_date' => '2026-08-11',
            'requested_days' => 1,
            'reason' => 'Booked annual leave',
            'status' => 'approved',
        ]);

        $summary = $this->summaryFor('2026-08-10', '2026-08-11', $employee);

        // Booked leave is not an absence, and reading it as one is what made
        // the old figure unusable on a ward with anyone off.
        $this->assertSame('0', $summary['Absent']['value']);
    }

    // -----------------------------------------------------------------
    // Exports
    // -----------------------------------------------------------------

    public function test_every_format_exports_the_same_columns(): void
    {
        $this->attendanceRecordFor($this->nurse(), '2026-08-12');

        $csv = $this->actingAs($this->hr())
            ->get($this->exportUrl('attendance', 'csv'))
            ->assertOk()
            ->streamedContent();

        // The screen showed an approval column that neither export carried.
        $this->assertStringContainsString('Approval', $csv);
        $this->assertStringContainsString('Undertime Minutes', $csv);
    }

    public function test_the_pdf_export_renders(): void
    {
        $this->attendanceRecordFor($this->nurse(), '2026-08-12');

        $response = $this->actingAs($this->hr())
            ->get($this->exportUrl('attendance', 'pdf'))
            ->assertOk();

        // dompdf returns a complete response rather than a streamed one.
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_every_report_exports(): void
    {
        foreach (['attendance', 'leave', 'timesheet'] as $report) {
            $this->actingAs($this->hr())
                ->get($this->exportUrl($report, 'csv'))
                ->assertOk()
                ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        }
    }

    public function test_a_pdf_beyond_the_row_ceiling_is_refused_before_it_starts(): void
    {
        config(['reports.max_rows.pdf' => 1]);
        $nurse = $this->nurse();
        $this->attendanceRecordFor($nurse, '2026-08-12');
        $this->attendanceRecordFor($this->otherNurse(), '2026-08-12');

        // Better a refusal naming a format that copes than a download that
        // dies of memory exhaustion halfway through and looks complete.
        $this->actingAs($this->hr())
            ->get($this->exportUrl('attendance', 'pdf'))
            ->assertSessionHasErrors('date_to');
    }

    public function test_an_export_is_written_to_the_audit_trail(): void
    {
        $this->attendanceRecordFor($this->nurse(), '2026-08-12');

        $this->actingAs($this->hr())->get($this->exportUrl('attendance', 'csv'))->assertOk();

        $entry = AuditLog::query()->where('action', 'reports.export')->latest('id')->first();

        // Every export is a GET, and the audit middleware deliberately ignores
        // GET -- so bulk extraction of personal data was the one action in the
        // module that nothing recorded.
        $this->assertNotNull($entry);
        $this->assertSame('attendance', $entry->metadata['report']);
        $this->assertSame('csv', $entry->metadata['format']);
        $this->assertArrayHasKey('row_count', $entry->metadata);
        $this->assertSame('2026-08-01', $entry->metadata['filters']['date_from']);
    }

    public function test_a_read_only_administrator_may_read_a_report_but_not_export_it(): void
    {
        $admin = User::query()->where('email', 'admin@hrms.local')->firstOrFail();

        $this->actingAs($admin)->get(route('reports.show', ['report' => 'leave']))->assertOk();
        $this->actingAs($admin)->get($this->exportUrl('leave', 'csv'))->assertForbidden();
    }

    public function test_the_pdf_download_is_an_attachment(): void
    {
        $this->attendanceRecordFor($this->nurse(), '2026-08-12');

        $response = $this->actingAs($this->hr())
            ->get($this->exportUrl('attendance', 'pdf'))
            ->assertOk();

        // Looking at one before committing is the print view's job, so this
        // path always hands over a file.
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    // -----------------------------------------------------------------
    // Print view
    // -----------------------------------------------------------------

    public function test_the_print_view_renders_the_report_and_opens_the_dialog(): void
    {
        $record = $this->attendanceRecordFor($this->nurse(), '2026-08-12');

        $this->actingAs($this->hr())
            ->get(route('reports.print', ['report' => 'attendance', 'date_from' => '2026-08-01', 'date_to' => '2026-08-20']))
            ->assertOk()
            ->assertSee($record->employee->full_name)
            ->assertSee('window.print()', false)
            // Standalone document: none of the application furniture belongs on
            // paper, so it is never rendered rather than hidden in print CSS.
            ->assertDontSee('sidebar-nav-heading', false);
    }

    public function test_the_print_view_uses_the_compact_columns(): void
    {
        $this->attendanceRecordFor($this->nurse(), '2026-08-12');

        $response = $this->actingAs($this->hr())
            ->get(route('reports.print', ['report' => 'attendance']))
            ->assertOk();

        // Paper does not scroll sideways, so the wide columns are dropped -- the
        // same set the PDF takes.
        $this->assertCount(12, $response->viewData('columns'));
        $this->assertSame(
            $response->viewData('filename'),
            'attendance-'.$response->viewData('filters')['date_from'].'-to-'.$response->viewData('filters')['date_to'].'.pdf',
        );
    }

    public function test_the_print_view_states_the_filters_on_the_sheet(): void
    {
        $nurse = $this->nurse();
        $this->attendanceRecordFor($nurse, '2026-08-12', status: 'late');

        // A printed extract outlives the screen that made it, so it has to carry
        // its own filters or nobody downstream can check what it covers.
        $this->actingAs($this->hr())
            ->get(route('reports.print', [
                'report' => 'attendance',
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-20',
                'status' => 'late',
            ]))
            ->assertOk()
            ->assertSee('Status:')
            ->assertSee('Late');
    }

    public function test_the_print_view_is_audited_like_any_other_export(): void
    {
        $this->attendanceRecordFor($this->nurse(), '2026-08-12');

        $this->actingAs($this->hr())->get(route('reports.print', ['report' => 'attendance']))->assertOk();

        $entry = AuditLog::query()->where('action', 'reports.export')->latest('id')->first();

        // A page saved from the print dialog has left the system as surely as a
        // downloaded file, and the trail should not tell the difference.
        $this->assertNotNull($entry);
        $this->assertSame('print', $entry->metadata['format']);
        $this->assertSame('attendance', $entry->metadata['report']);
    }

    public function test_the_print_view_refuses_a_range_it_cannot_lay_out(): void
    {
        config(['reports.max_rows.print' => 1]);
        $this->attendanceRecordFor($this->nurse(), '2026-08-12');
        $this->attendanceRecordFor($this->otherNurse(), '2026-08-12');

        $this->actingAs($this->hr())
            ->get(route('reports.print', ['report' => 'attendance', 'date_from' => '2026-08-01', 'date_to' => '2026-08-20']))
            ->assertSessionHasErrors('date_to');
    }

    public function test_the_print_view_allows_more_rows_than_the_pdf(): void
    {
        // The browser paginates it, so the ceiling is the reader's machine
        // rather than dompdf building a box tree per row inside the request.
        $this->assertGreaterThan(
            (int) config('reports.max_rows.pdf'),
            (int) config('reports.max_rows.print'),
        );
    }

    public function test_a_read_only_administrator_cannot_open_the_print_view(): void
    {
        $admin = User::query()->where('email', 'admin@hrms.local')->firstOrFail();

        // Denying the file but serving the page it is made from would be a
        // distinction without a difference.
        $this->actingAs($admin)
            ->get(route('reports.print', ['report' => 'attendance']))
            ->assertForbidden();
    }

    public function test_every_report_can_be_printed(): void
    {
        foreach (['attendance', 'leave', 'timesheet'] as $report) {
            $this->actingAs($this->hr())
                ->get(route('reports.print', ['report' => $report]))
                ->assertOk();
        }
    }

    public function test_the_export_menu_downloads_data_files_and_previews_the_pdf(): void
    {
        $response = $this->actingAs($this->hr())
            ->get(route('reports.show', ['report' => 'attendance']))
            ->assertOk();

        // CSV and Excel are data files with nothing to look at first, so they
        // link straight to the download.
        foreach (['csv', 'xlsx'] as $format) {
            $response->assertSee(route('reports.export', ['report' => 'attendance', 'format' => $format]), false);
        }

        // PDF is a document, so it opens the print dialog instead.
        $response
            ->assertSee('data-print-url', false)
            ->assertSee(route('reports.print', ['report' => 'attendance']), false)
            ->assertDontSee(route('reports.export', ['report' => 'attendance', 'format' => 'pdf']), false);
    }

    public function test_the_pdf_menu_item_cannot_navigate(): void
    {
        $html = $this->actingAs($this->hr())
            ->get(route('reports.show', ['report' => 'attendance']))
            ->assertOk()
            ->getContent();

        // The print URL must reach the browser only as data. Carried as an href,
        // any failure of the handler -- an exception, a stale bundle -- becomes a
        // navigation that strands the reader on a bare printable page, which is
        // the one outcome the in-place dialog exists to prevent.
        $printUrl = route('reports.print', ['report' => 'attendance']);

        $this->assertStringContainsString('data-print-url="'.e($printUrl).'"', $html);
        $this->assertStringNotContainsString('href="'.e($printUrl).'"', $html);
    }

    public function test_the_print_response_is_an_injectable_fragment(): void
    {
        $this->attendanceRecordFor($this->nurse(), '2026-08-12');

        $html = $this->actingAs($this->hr())
            ->get(route('reports.print', ['report' => 'attendance']))
            ->assertOk()
            ->getContent();

        // It is injected into whichever report screen the reader is on, so it
        // must be a fragment rather than a document -- an iframe is not an
        // option while the application sends X-Frame-Options: DENY.
        $this->assertStringNotContainsString('<!DOCTYPE', $html);
        $this->assertStringNotContainsString('<body', $html);
        $this->assertStringContainsString('report-print-doc', $html);
        // The styles have to travel with it, scoped so they cannot reach the
        // host page.
        $this->assertStringContainsString('.report-print-doc', $html);
        $this->assertStringContainsString('is-printing-report', $html);
    }

    public function test_the_print_sheet_leaves_no_margin_for_browser_chrome(): void
    {
        $this->attendanceRecordFor($this->nurse(), '2026-08-12');

        $html = $this->actingAs($this->hr())
            ->get(route('reports.print', ['report' => 'attendance']))
            ->assertOk()
            ->getContent();

        // The browser prints its own header and footer -- timestamp, title and
        // the full URL of the page -- into the page margin, and no property
        // turns them off. Leaving no margin leaves nowhere to draw them.
        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*margin:\s*0/', $html);
        // Which means the sheet supplies its own spacing, per page.
        $this->assertStringContainsString('table-footer-group', $html);
    }

    public function test_the_print_sheet_carries_the_export_filename(): void
    {
        $this->attendanceRecordFor($this->nurse(), '2026-08-12');

        // Injected into the host page, the document title is the application's,
        // so a saved PDF would be named after the screen rather than the export.
        $this->actingAs($this->hr())
            ->get(route('reports.print', ['report' => 'attendance', 'date_from' => '2026-08-01', 'date_to' => '2026-08-20']))
            ->assertOk()
            ->assertSee('data-print-title="attendance-2026-08-01-to-2026-08-20.pdf"', false);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function exportUrl(string $report, string $format): string
    {
        return route('reports.export', [
            'report' => $report,
            'format' => $format,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-20',
        ]);
    }

    /**
     * @return Collection<string, array{label: string, value: string, icon: string, tone: string}>
     */
    private function summaryFor(string $from, string $to, Employee $employee): Collection
    {
        $response = $this->actingAs($this->hr())->get(route('reports.show', [
            'report' => 'attendance',
            'date_from' => $from,
            'date_to' => $to,
            'employee_id' => $employee->id,
        ]))->assertOk();

        return collect($response->viewData('summary'))->keyBy('label');
    }

    /**
     * Roster the employee for each date. ScheduleAssignment refuses writes
     * outside RosterWriteContext, so the seeding goes through it too.
     *
     * @param  array<int, string>  $dates
     */
    private function roster(Employee $employee, array $dates): void
    {
        $actor = $this->hr();

        RosterWriteContext::allow($actor, function () use ($employee, $dates, $actor): void {
            foreach ($dates as $date) {
                ScheduleAssignment::query()->create([
                    'employee_id' => $employee->id,
                    'shift_id' => Shift::query()->value('id'),
                    'work_date' => $date,
                    'status' => 'scheduled',
                    'created_by' => $actor->id,
                ]);
            }
        });
    }

    private function nurse(): Employee
    {
        return User::query()->where('email', 'nursing.head@hrms.local')->firstOrFail()->employee;
    }

    private function otherNurse(): Employee
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->whereKeyNot($this->nurse()->id)
            ->firstOrFail();
    }

    private function attendanceRecordFor(Employee $employee, string $date, string $status = 'present'): AttendanceRecord
    {
        return AttendanceRecord::query()->forceCreate([
            'employee_id' => $employee->id,
            'office_location_id' => OfficeLocation::query()->value('id'),
            'attendance_date' => $date,
            'check_in_at' => $date.' 08:00:00',
            'check_out_at' => $date.' 17:00:00',
            'check_in_method' => 'manual',
            'check_out_method' => 'manual',
            'status' => $status,
            'approval_status' => 'pending',
            'worked_minutes' => 480,
            'late_minutes' => $status === 'late' ? 20 : 0,
        ]);
    }
}
