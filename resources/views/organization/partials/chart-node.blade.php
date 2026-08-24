{{--
    One node of the reporting-line tree, rendered recursively.

    The markup is a nested <ul>/<li> on purpose rather than absolutely
    positioned boxes: assistive technology announces it as a real tree, and it
    degrades to a readable indented list with no CSS at all.
--}}
<li class="org-node" @if($node['direct_reports'] > 0) data-org-branch @endif>
    <div class="org-card" @if($node['seniority_rank']) data-seniority="{{ $node['seniority_rank'] }}" @endif>
        @if ($node['direct_reports'] > 0)
            <button
                class="org-toggle"
                type="button"
                data-org-toggle
                aria-expanded="true"
                aria-label="Collapse direct reports of {{ $node['name'] }}"
            ><x-icon name="chevron-down" /></button>
        @endif

        <div class="org-card-body">
            <strong class="org-name">{{ $node['name'] }}</strong>
            <span class="org-position">{{ $node['position'] ?? 'No position on record' }}</span>
            <span class="org-meta">
                <span class="org-employee-number">{{ $node['employee_number'] }}</span>
                @if ($node['department'])
                    <span class="org-department">{{ $node['department'] }}</span>
                @endif
                @if ($node['employment_status'] === 'on_leave')
                    <span class="org-status-flag">On leave</span>
                @endif
            </span>
            @if ($node['seniority_label'] || $node['direct_reports'] > 0)
                <span class="org-footnote">
                    @if ($node['seniority_label']){{ $node['seniority_label'] }}@endif
                    @if ($node['seniority_label'] && $node['direct_reports'] > 0) · @endif
                    @if ($node['direct_reports'] > 0){{ $node['direct_reports'] }} direct {{ Str::plural('report', $node['direct_reports']) }}@endif
                </span>
            @endif
        </div>
    </div>

    @if ($node['reports'] !== [])
        <ul class="org-children">
            @foreach ($node['reports'] as $report)
                @include('organization.partials.chart-node', ['node' => $report])
            @endforeach
        </ul>
    @endif
</li>
