{{-- The read-back shown once after a schedule create: what was written, for
     whom, and anything that was skipped. Opened by schedule-forms.js on load. --}}
@php($confirmation = session('schedule_confirmation'))
@if (is_array($confirmation))
    <div class="modal fade" id="scheduleConfirmationModal" tabindex="-1" role="alertdialog" aria-modal="true" aria-labelledby="scheduleConfirmationTitle" aria-describedby="scheduleConfirmationText">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content schedule-confirmation-content">
            <div class="schedule-confirmation-head">
                <span class="schedule-confirmation-icon" aria-hidden="true"><x-icon name="check" /></span>
                <h2 id="scheduleConfirmationTitle">{{ $confirmation['title'] ?? 'Schedule saved' }}</h2>
                <p id="scheduleConfirmationText">{{ $confirmation['text'] ?? '' }}</p>
            </div>
            @if (! empty($confirmation['rows']))
                <dl class="schedule-confirmation-rows">
                    @foreach ($confirmation['rows'] as [$label, $value])
                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            @endif
            @if (! empty($confirmation['note']))
                <p class="schedule-confirmation-note">{{ $confirmation['note'] }}</p>
            @endif
            <div class="schedule-confirmation-actions">
                @if (! empty($confirmation['again']['target']))
                    <button type="button" class="btn btn-light" data-confirmation-again data-bs-target="{{ $confirmation['again']['target'] }}">{{ $confirmation['again']['label'] ?? 'Create another' }}</button>
                @endif
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>
            </div>
        </div></div>
    </div>
@endif
