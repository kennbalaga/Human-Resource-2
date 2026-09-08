<?php

return [

    /*
     * The longest period any report will assemble in one request. Attendance
     * kept its own limit for years under `attendance.report_max_days`; that
     * value stays the default here so existing bookmarks and the validation
     * message they trigger do not change behaviour.
     */
    'max_days' => (int) env('REPORT_MAX_DAYS', 92),

    /*
     * Row ceilings per format. CSV is deliberately absent: it streams a row at
     * a time and never holds the result set, so it is the escape hatch a large
     * range is pointed at.
     *
     * XLSX and PDF both assemble the whole document in memory before a single
     * byte is sent -- PhpSpreadsheet builds a cell object per value, dompdf
     * builds a layout tree per row -- so an unbounded range is not slow, it is
     * a fatal memory error partway through a download the browser has already
     * started. Refusing early, with a message that says which format does cope,
     * is the difference between a hint and a truncated file.
     */
    'max_rows' => [
        'xlsx' => (int) env('REPORT_MAX_XLSX_ROWS', 25000),
        'pdf' => (int) env('REPORT_MAX_PDF_ROWS', 3000),

        /*
         * The print view is allowed considerably more than the PDF, because the
         * work happens somewhere else. dompdf builds a box tree for every row
         * inside the request, on the server, against its memory limit; the print
         * view sends a table and lets the browser paginate it, which browsers do
         * well and which costs the server one HTML response. The ceiling here is
         * about the reader's machine and their patience, not ours.
         */
        'print' => (int) env('REPORT_MAX_PRINT_ROWS', 10000),
    ],

    /*
     * Column width, in characters, applied to every XLSX column. PhpSpreadsheet
     * can size columns to their contents instead, but auto-sizing measures every
     * cell in the sheet against a font metrics table -- on a full-hospital
     * export that single flag costs more than assembling the rest of the file.
     */
    'xlsx_column_width' => 18,

];
