{{--
    The printable report, as a fragment rather than a page.

    It is fetched by report-print.js and injected into whichever report screen
    the reader is on, so the browser's print dialog opens over that screen
    instead of replacing it. An iframe would have been the obvious way to do
    that and is not available: SecurityHeaders sends X-Frame-Options: DENY and
    the policy sets frame-ancestors 'none', so the application refuses to be
    framed even by itself. Rather than punch a hole in that for one feature, the
    markup simply joins the page it was asked from.

    The styles travel with it. They are scoped to .report-print-doc so nothing
    leaks into the host page, and they arrive as a <style> element, which does
    apply when inserted through innerHTML -- unlike a <script>, which does not,
    and which is why the auto-print below runs only when this URL is opened
    directly.
--}}
<div class="report-print-doc" data-print-title="{{ $filename }}">
    <div class="report-print-header">
        <h1>{{ $title }}</h1>
        <p>Dr. Jose N. Rodriguez Memorial Hospital and Sanitarium</p>
    </div>

    <div class="report-print-meta">
        {{ \Illuminate\Support\Carbon::parse($filters['date_from'])->format('M j, Y') }}
        &ndash;
        {{ \Illuminate\Support\Carbon::parse($filters['date_to'])->format('M j, Y') }}
        &middot; Generated {{ now()->format('M j, Y g:i A') }}
        &middot; {{ number_format(count($rows)) }} {{ str('record')->plural(count($rows)) }}
    </div>

    @if (count($filterSummary) > 1)
        {{-- More than the date range alone, so the sheet says what it was
             narrowed by. A printed extract outlives the screen that produced it,
             and a page of rows with no statement of its own filters cannot be
             checked by whoever reads it later. --}}
        <p class="report-print-filters">
            @foreach ($filterSummary as $row)
                <strong>{{ $row['label'] }}:</strong> {{ $row['value'] }}@if (! $loop->last) &nbsp;&middot;&nbsp; @endif
            @endforeach
        </p>
    @endif

    @if (empty($rows))
        <p class="report-print-empty">No matching records for the selected filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th @class(['text-right' => $column->numeric])>{{ $column->label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach (array_values($row) as $index => $value)
                            <td @class(['text-right' => $columns[$index]->numeric])>
                                {{ $value === null || $value === '' ? '—' : $value }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            {{-- A spacer, not data. It repeats on every sheet under
                 table-footer-group, which is what keeps the last row of each
                 page off the paper edge now that the page margin is zero. --}}
            <tfoot aria-hidden="true">
                <tr><td colspan="{{ count($columns) }}"></td></tr>
            </tfoot>
        </table>
    @endif
</div>

<style>
    .report-print-doc {
        color: #1b2923;
        font-family: 'Instrument Sans', system-ui, -apple-system, 'Segoe UI', sans-serif;
        font-size: 11px;
    }

    .report-print-doc .report-print-header { margin-bottom: 12px; }
    .report-print-doc .report-print-header h1 { margin: 0 0 3px; font-size: 17px; }
    .report-print-doc .report-print-header p { margin: 0; color: #55635b; font-size: 11px; }
    .report-print-doc .report-print-meta { margin-bottom: 14px; color: #55635b; font-size: 10px; }

    .report-print-doc .report-print-filters {
        margin: 0 0 14px;
        padding: 9px 11px;
        border-left: 3px solid #176b43;
        color: #3d4a44;
        font-size: 10px;
        line-height: 1.55;
    }

    .report-print-doc table { width: 100%; border-collapse: collapse; }

    .report-print-doc thead th {
        padding: 6px 7px;
        border-bottom: 1.5px solid #55635b;
        color: #1b2923;
        font-size: 9px;
        letter-spacing: .03em;
        text-align: left;
        text-transform: uppercase;
    }

    /* Rules, not background fills. Chrome leaves "Background graphics" off by
       default, so a striped design prints as undifferentiated white rows; a
       hairline under every row separates them whatever that box is set to. */
    .report-print-doc tbody td {
        padding: 6px 7px;
        border-bottom: 1px solid #d5ded8;
        vertical-align: top;
    }

    .report-print-doc .text-right { text-align: right; }
    .report-print-doc .report-print-empty { padding: 20px 0; color: #55635b; text-align: center; }

    /* Injected into the host page, it waits off-screen until the dialog opens.
       Not display:none -- an element with no layout cannot be measured, and the
       print preview would come back blank. */
    html.is-printing-report #report-print-root {
        position: fixed;
        top: 0;
        left: -10000px;
        width: 1100px;
    }

    @media print {
        /* Everything the application draws around a report is furniture nobody
           wants on paper. While a print is in flight the host page steps aside
           and only the document is laid out. */
        html.is-printing-report body > *:not(#report-print-root) { display: none !important; }

        html.is-printing-report #report-print-root {
            position: static;
            left: auto;
            width: auto;
        }

        /*
         * Zero margin, on purpose. The browser draws its own header and footer
         * -- the timestamp, the document title, and the full URL of the page --
         * inside the page margin, and there is no property that turns them off:
         * "Headers and footers" is the reader's own checkbox. Leaving no margin
         * leaves nowhere to draw them, which suppresses them without asking the
         * reader to remember a setting.
         *
         * The spacing then has to come from the content, and it cannot come
         * from padding alone: vertical padding on a block that spans pages
         * applies once at the start and once at the end, so page two onward
         * would begin hard against the paper edge. The repeating table groups
         * below supply it per sheet instead.
         */
        @page { margin: 0; size: A4 landscape; }

        .report-print-doc { padding: 12mm 10mm 0; }

        /* Both repeat on every sheet: the head so a table running to page four
           still says what its columns are, and the foot purely as a spacer, so
           no page ends flush against the edge. */
        .report-print-doc thead { display: table-header-group; }
        .report-print-doc tfoot { display: table-footer-group; }
        .report-print-doc tfoot td { height: 12mm; border: 0; }
        .report-print-doc tr { page-break-inside: avoid; }

        /* Page two onward opens on the repeated heading rather than the title
           block, so the heading carries the top spacing for those sheets. */
        .report-print-doc thead th { padding-top: 6mm; }
    }
</style>

<script @if(isset($cspNonce)) nonce="{{ $cspNonce }}" @endif>
    // Only runs when this URL is opened directly: a <script> inserted through
    // innerHTML is inert, so the injected copy never reaches this and cannot
    // race the dialog that report-print.js opens itself.
    window.addEventListener('load', () => window.print());
</script>
