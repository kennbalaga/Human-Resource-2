{{--
    Shared report heading: title, the tab strip across the available reports,
    and the export menu. The export links carry the current query string, so a
    download is always the report the reader is looking at rather than a fresh
    unfiltered one -- which is what made the old menu's three formats disagree
    with the screen as soon as anybody filtered it.
--}}
<section class="page-heading attendance-heading">
    <div>
        <p class="eyebrow">Insights</p>
        <h1>{{ $report->heading() }}</h1>
        <p>{{ $report->description() }}</p>
    </div>
    @if($canManageData)
        <div class="dropdown dashboard-action-menu">
            <button class="btn btn-primary dashboard-action dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <x-icon name="download" /> Export
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                {{-- CSV and Excel are data files: there is no document to look at
                     before saving one, so they download on the spot. --}}
                @foreach (['csv' => 'CSV', 'xlsx' => 'Excel'] as $format => $label)
                    <li>
                        <a class="dropdown-item" href="{{ route('reports.export', array_merge(['report' => $report->key()], request()->query(), ['format' => $format])) }}">
                            <x-icon name="report" /> <span>Export as {{ $label }}</span>
                        </a>
                    </li>
                @endforeach
                <li>
                    {{-- PDF is a document, so it gets looked at first: report-print.js
                         loads the printable page into an off-screen frame and opens
                         the dialog over this one.

                         A button, not a link. The URL travels in a data attribute so
                         there is no href for a browser to follow when anything goes
                         wrong -- an exception, or a cached bundle without the handler
                         -- because following it would dump the reader on a bare
                         printable page, which is the single outcome this is built to
                         prevent. There is nothing lost: the menu is a Bootstrap
                         dropdown, so it cannot open without scripting anyway. --}}
                    <button
                        type="button"
                        class="dropdown-item"
                        data-print-url="{{ route('reports.print', array_merge(['report' => $report->key()], request()->query())) }}"
                    >
                        <x-icon name="report" /> <span>Export as PDF</span>
                    </button>
                </li>
            </ul>
        </div>
    @endif
</section>

<nav class="report-tabs" aria-label="Report types">
    @foreach ($reports as $available)
        <a
            class="report-tab {{ $available->key() === $report->key() ? 'is-active' : '' }}"
            href="{{ route('reports.show', ['report' => $available->key()]) }}"
            @if($available->key() === $report->key()) aria-current="page" @endif
        >
            <x-icon :name="$available->icon()" />
            <span>{{ $available->label() }}</span>
        </a>
    @endforeach
</nav>

@if ($errors->any())
    <div class="attendance-alert attendance-alert-danger" role="alert">
        <x-icon name="close" /> <span>{{ $errors->first() }}</span>
    </div>
@endif

@if (session('success'))
    <div class="attendance-alert attendance-alert-success" role="status"><x-icon name="check-circle" /> <span>{{ session('success') }}</span></div>
@endif

<section class="report-stats-grid" aria-label="{{ $report->label() }} report summary">
    @foreach ($summary as $tile)
        <article class="report-stat">
            <span class="report-stat-icon report-stat-{{ $tile['tone'] }}"><x-icon :name="$tile['icon']" /></span>
            <div><span>{{ $tile['label'] }}</span><strong>{{ $tile['value'] }}</strong></div>
        </article>
    @endforeach
</section>
