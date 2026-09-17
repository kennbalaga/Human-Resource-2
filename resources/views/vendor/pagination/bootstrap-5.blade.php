{{--
    The pager every list in the app uses (`->links('pagination::bootstrap-5')`).

    Laravel's own version shows the first two and last two page numbers around
    the current one ("1 2 … 7 8 9 … 21 22"), which reads as a row of unrelated
    numbers once a list runs past a few pages. This shows a steady window of
    five pages around where you are, with First and Last to jump to either end,
    and says which page of how many you are on.

    The window is worked out here, so `onEachSide()` on the caller has no effect.
    On a phone the outer two numbers and the button words drop away, leaving
    three pages and the arrows.
--}}
@if ($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $window = 5;
        $start = max(1, min($current - intdiv($window, 2), $last - $window + 1));
        $end = min($last, $start + $window - 1);

        // "Go to page" submits to the same list with the same filters. They are
        // read back off a page URL the paginator built, which is the only
        // public way to get at the query it carries, and flattened so array
        // filters survive as hidden fields.
        $pageName = $paginator->getPageName();
        $sampleUrl = $paginator->url(1);
        parse_str((string) parse_url($sampleUrl, PHP_URL_QUERY), $carried);
        unset($carried[$pageName]);
        $hiddenFields = collect(explode('&', http_build_query($carried)))
            ->filter()
            ->map(fn (string $pair) => array_map('urldecode', array_pad(explode('=', $pair, 2), 2, '')));
        $fragment = parse_url($sampleUrl, PHP_URL_FRAGMENT);
        $jumpAction = $paginator->path().($fragment ? '#'.$fragment : '');
    @endphp

    <nav class="hr-pager" aria-label="Pagination">
        <p class="hr-pager-summary">
            Showing <b>{{ number_format($paginator->firstItem()) }}</b> to <b>{{ number_format($paginator->lastItem()) }}</b>
            of <b>{{ number_format($paginator->total()) }}</b> results
            {{-- The jump box below says the same thing once it is shown. --}}
            @if ($last <= $window)
                <span class="hr-pager-page">· Page {{ number_format($current) }} of {{ number_format($last) }}</span>
            @endif
        </p>

        <div class="hr-pager-controls">
            <ul class="pagination">
                @if ($paginator->onFirstPage())
                    <li class="page-item disabled hr-pager-edge" aria-disabled="true">
                        <span class="page-link"><span aria-hidden="true">&laquo;</span><span class="hr-pager-word"> First</span></span>
                    </li>
                    <li class="page-item disabled" aria-disabled="true">
                        <span class="page-link"><span aria-hidden="true">&lsaquo;</span><span class="hr-pager-word"> Prev</span></span>
                    </li>
                @else
                    <li class="page-item hr-pager-edge">
                        <a class="page-link" href="{{ $paginator->url(1) }}" rel="first" aria-label="First page"><span aria-hidden="true">&laquo;</span><span class="hr-pager-word"> First</span></a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page"><span aria-hidden="true">&lsaquo;</span><span class="hr-pager-word"> Prev</span></a>
                    </li>
                @endif

                @foreach (range($start, $end) as $page)
                    {{-- Pages two steps from the current one are the first to go on a narrow screen. --}}
                    @php($far = abs($page - $current) >= 2)
                    @if ($page === $current)
                        <li class="page-item active" aria-current="page"><span class="page-link">{{ number_format($page) }}</span></li>
                    @else
                        <li @class(['page-item', 'hr-pager-far' => $far])><a class="page-link" href="{{ $paginator->url($page) }}" aria-label="Page {{ $page }}">{{ number_format($page) }}</a></li>
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <li class="page-item">
                        <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page"><span class="hr-pager-word">Next </span><span aria-hidden="true">&rsaquo;</span></a>
                    </li>
                    <li class="page-item hr-pager-edge">
                        <a class="page-link" href="{{ $paginator->url($last) }}" rel="last" aria-label="Last page, {{ $last }}"><span class="hr-pager-word">Last </span><span aria-hidden="true">&raquo;</span></a>
                    </li>
                @else
                    <li class="page-item disabled" aria-disabled="true">
                        <span class="page-link"><span class="hr-pager-word">Next </span><span aria-hidden="true">&rsaquo;</span></span>
                    </li>
                    <li class="page-item disabled hr-pager-edge" aria-disabled="true">
                        <span class="page-link"><span class="hr-pager-word">Last </span><span aria-hidden="true">&raquo;</span></span>
                    </li>
                @endif
            </ul>

            {{-- "Page [ n ] of N  Go", drawn as one control the same height as the
                 page buttons. Only worth offering once some pages are out of sight.
                 The box starts on the current page, so it doubles as "you are here". --}}
            @if ($last > $window)
                <form class="hr-pager-jump" method="GET" action="{{ $jumpAction }}" data-page-jump>
                    @foreach ($hiddenFields as [$name, $value])
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <label class="hr-pager-jump-label" for="hr-pager-jump-{{ $pageName }}">Page</label>
                    <input
                        id="hr-pager-jump-{{ $pageName }}"
                        class="hr-pager-jump-input"
                        type="number"
                        name="{{ $pageName }}"
                        value="{{ $current }}"
                        min="1"
                        max="{{ $last }}"
                        step="1"
                        inputmode="numeric"
                        required
                        aria-describedby="hr-pager-jump-of-{{ $pageName }}"
                    >
                    <span class="hr-pager-jump-of" id="hr-pager-jump-of-{{ $pageName }}">of {{ number_format($last) }}</span>
                    <button type="submit" class="hr-pager-jump-go" aria-label="Go to that page">
                        Go <span aria-hidden="true">&rarr;</span>
                    </button>
                </form>
            @endif
        </div>
    </nav>
@endif
