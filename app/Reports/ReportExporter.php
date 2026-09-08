<?php

namespace App\Reports;

use App\Models\User;
use App\Support\SpreadsheetExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns any report into CSV, XLSX or PDF from the one column definition.
 *
 * The three formats have genuinely different constraints, and the version this
 * replaced only respected them in the CSV:
 *
 *  - CSV streams. Rows are read lazily and written straight to the output
 *    handle, so the range is bounded by patience rather than by memory, and it
 *    is what the other two point at when they refuse.
 *  - XLSX assembles. PhpSpreadsheet holds a cell object per value until the
 *    writer runs, so it is read lazily but still capped, and column widths are
 *    set rather than auto-sized -- auto-sizing measures every cell in the sheet
 *    against font metrics and, on a full-hospital range, costs more than the
 *    rest of the export together.
 *  - PDF lays out. dompdf builds a box tree for every row before it can
 *    paginate, has the hardest ceiling of the three, and gets the narrower
 *    compact column set because a page cannot scroll sideways.
 *
 * Both ceilings fail before a byte is sent, naming the format that does cope.
 * Previously they did not fail at all: the download began, the worker hit its
 * memory limit somewhere in the middle, and the reviewer was left with a
 * truncated file that gave no sign of being short.
 */
final class ReportExporter
{
    /** @var array<int, string> */
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function download(Report $report, array $filters, ?User $user, string $format): StreamedResponse|Response
    {
        return match ($format) {
            'csv' => $this->csv($report, $filters, $user),
            'xlsx' => $this->xlsx($report, $filters, $user),
            'pdf' => $this->pdf($report, $filters, $user),
            default => throw ValidationException::withMessages(['format' => 'Unsupported export format.']),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function csv(Report $report, array $filters, ?User $user): StreamedResponse
    {
        $columns = $report->columns();
        $rows = $report->rows($filters, $user);

        return response()->streamDownload(function () use ($columns, $rows): void {
            $output = fopen('php://output', 'w');
            SpreadsheetExport::writeCsvRow($output, ReportColumn::labels($columns));

            foreach ($rows as $record) {
                SpreadsheetExport::writeCsvRow($output, ReportColumn::row($columns, $record));
            }

            fclose($output);
        }, $report->filename($filters, 'csv'), ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function xlsx(Report $report, array $filters, ?User $user): StreamedResponse
    {
        $this->guardRowCount($report, $filters, $user, 'xlsx', 'Excel');

        $columns = $report->columns();
        $labels = ReportColumn::labels($columns);
        $lastColumn = Coordinate::stringFromColumnIndex(count($labels));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($report->label());
        $sheet->fromArray($labels, null, 'A1');
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $row = 2;
        foreach ($report->rows($filters, $user) as $record) {
            // Deliberately not fromArray(): it binds values by inspection, so a
            // name beginning with "=" would be stored as a live formula aimed
            // at whoever opens the file.
            SpreadsheetExport::writeXlsxRow($sheet, ReportColumn::row($columns, $record), $row);
            $row++;
        }

        $width = (int) config('reports.xlsx_column_width');
        foreach (range(1, count($labels)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth($width);
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $report->filename($filters, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function pdf(Report $report, array $filters, ?User $user): Response
    {
        $this->guardRowCount($report, $filters, $user, 'pdf', 'CSV or Excel');

        $columns = ReportColumn::compactOnly($report->columns());
        $rows = $report->rows($filters, $user)
            ->map(fn (object $record) => ReportColumn::row($columns, $record))
            ->all();

        $pdf = Pdf::loadView('reports.pdf', [
            'title' => $report->label().' Report',
            'columns' => $columns,
            'rows' => $rows,
            'filters' => $filters,
        ])->setPaper('a4', 'landscape');

        // Always an attachment. Looking at a report before committing to it is
        // the print view's job, and it does it better -- in front of the reader,
        // with a page count and a save dialog. Streaming this one inline as well
        // would put two differently-rendered PDFs of the same report a click
        // apart, which is worse than either on its own.
        return $pdf->download($report->filename($filters, 'pdf'));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function guardRowCount(Report $report, array $filters, ?User $user, string $format, string $alternative): void
    {
        $limit = (int) config("reports.max_rows.{$format}");
        $rows = $report->countRows($filters, $user);

        if ($rows <= $limit) {
            return;
        }

        throw ValidationException::withMessages([
            'date_to' => sprintf(
                'This range holds %s rows, more than the %s limit of %s. Narrow the range or filters, or export as %s instead.',
                number_format($rows),
                strtoupper($format),
                number_format($limit),
                $alternative,
            ),
        ]);
    }
}
