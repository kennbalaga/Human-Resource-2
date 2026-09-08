<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Reports\ReportColumn;
use App\Reports\ReportExporter;
use App\Reports\ReportRegistry;
use App\Services\Reports\ReportExportAuditor;
use App\Services\Reports\ReportFilterSummary;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every report in the Insights module, on one contract.
 *
 * There used to be one controller here that could only ever show attendance,
 * with three near-identical export actions differing in their writer. The
 * report is now a parameter: the definition supplies the columns, the query,
 * the summary and the filters it honours, and this class does the same two
 * things to whichever one was asked for.
 *
 * /reports has no landing page of its own -- it resolves to attendance with the
 * report pinned as a route default, and the tab strip in the shared heading
 * moves between the three.
 *
 * The old /attendance/reports URLs still serve their screen directly rather
 * than redirecting -- they are linked from the dashboard, the exception panels,
 * the welcome tour and a service that builds deep links -- so they are bound
 * here with the report defaulted, and behave exactly as before.
 */
class ReportController extends Controller
{
    use ScopesWorkforceAccess;

    public function __construct(private readonly ReportRegistry $registry) {}

    public function show(ReportRequest $request): View
    {
        $report = $request->report();
        $filters = $request->reportFilters();
        $user = $request->user();

        return view($report->view(), [
            'report' => $report,
            'reports' => $this->registry->all(),
            'columns' => $report->columns(),
            'records' => $report->paginate($filters, $user),
            'summary' => $report->summary($filters, $user),
            'filters' => $filters,
            'departments' => $this->selectableDepartments($request),
            'employees' => Employee::query()
                ->visibleTo($user)
                ->where('employment_status', 'active')
                ->orderBy('last_name')
                ->get(['id', 'employee_number', 'first_name', 'last_name']),
            'leaveTypes' => in_array('leave_type_id', $report->filters(), true)
                ? LeaveType::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'canManageData' => $user->canManageData(),
            'currentRole' => $user->roles->first()?->name ?? 'Employee',
        ]);
    }

    /**
     * The report as a printable page, with the browser's print dialog opened
     * over it.
     *
     * This is the other half of previewing an export, and for PDF it is the
     * better half. dompdf renders on the server and hands back a finished file:
     * whatever it produced is what you get, and you find out by opening it.
     * The browser renders this one in front of the reader, with a live page
     * count, a layout toggle, adjustable margins and Save as PDF in the same
     * dialog -- and it costs the server one HTML response instead of a box tree
     * per row, which is why it is allowed several times the PDF's row ceiling.
     *
     * The generated file is audited like any other export. A page saved from
     * the print dialog has left the system just as surely as a downloaded one,
     * and the audit trail should not be able to tell the difference.
     */
    public function print(
        ReportRequest $request,
        ReportFilterSummary $summary,
        ReportExportAuditor $auditor,
    ): View {
        $report = $request->report();
        $filters = $request->reportFilters();
        $user = $request->user();
        $rows = $report->countRows($filters, $user);

        $this->guardPrintSize($rows);

        $auditor->record($request, $report, $filters, 'print', $rows);

        // The compact set, for the same reason the PDF uses it: paper does not
        // scroll sideways.
        $columns = ReportColumn::compactOnly($report->columns());

        return view('reports.print', [
            'title' => $report->label().' Report',
            'filename' => $report->filename($filters, 'pdf'),
            'columns' => $columns,
            'rows' => $report->rows($filters, $user)
                ->map(fn (object $record) => ReportColumn::row($columns, $record))
                ->all(),
            'filters' => $filters,
            'filterSummary' => $summary->for(
                $report,
                $filters,
                $this->selectableDepartments($request),
                Employee::query()->visibleTo($user)->where('employment_status', 'active')->get(['id', 'first_name', 'last_name']),
                in_array('leave_type_id', $report->filters(), true) ? LeaveType::query()->get(['id', 'name']) : collect(),
            ),
        ]);
    }

    private function guardPrintSize(int $rows): void
    {
        $limit = (int) config('reports.max_rows.print');

        if ($rows <= $limit) {
            return;
        }

        throw ValidationException::withMessages([
            'date_to' => sprintf(
                'This range holds %s rows, more than the %s the print view will lay out. Narrow the range, or export as CSV.',
                number_format($rows),
                number_format($limit),
            ),
        ]);
    }

    public function export(
        ReportRequest $request,
        ReportExporter $exporter,
        ReportExportAuditor $auditor,
    ): StreamedResponse|Response {
        $report = $request->report();
        $filters = $request->reportFilters();
        $format = $request->exportFormat();
        $user = $request->user();

        // Written before the response is built, not after: a streamed download
        // runs its callback once the response has already been handed to the
        // client, so an audit entry written there would be lost in exactly the
        // case worth recording -- an export that failed partway through.
        $auditor->record($request, $report, $filters, $format, $report->countRows($filters, $user));

        return $exporter->download($report, $filters, $user, $format);
    }
}
