<form class="panel organization-form" method="POST" action="{{ route('departments.shift-coverage.update', $department) }}">
    @csrf
    @method('PUT')
    <fieldset class="organization-fieldset">
        <legend>Shift coverage standard</legend>

        <p class="organization-field-full">
            @if($derivationSummary)
                {{ $derivationSummary }} Leave a shift blank to keep that figure, or enter a number where the shift is staffed differently.
            @else
                This unit has no bed capacity recorded, so there is no ratio to work from. Enter the staffing each shift requires.
            @endif
        </p>

        @error('requirements')<small class="organization-error organization-field-full">{{ $message }}</small>@enderror

        <div class="table-responsive organization-field-full">
            <table class="dashboard-table organization-table">
                <thead><tr><th>Shift</th><th>Hours</th><th>Minimum staff</th><th>Minimum senior</th></tr></thead>
                <tbody>
                @foreach($shifts as $shift)
                    @php($requirement = $requirements->get($shift->id))
                    <tr>
                        <td><strong>{{ $shift->name }}</strong><span class="organization-secondary">{{ $shift->code }}</span></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($shift->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($shift->end_time)->format('g:i A') }}</td>
                        <td>
                            <input type="number" name="requirements[{{ $shift->id }}][minimum_staff]" min="1" max="100"
                                   value="{{ old('requirements.'.$shift->id.'.minimum_staff', $requirement->minimum_staff ?? '') }}"
                                   placeholder="{{ $derivedMinimum ?? 1 }}">
                        </td>
                        <td>
                            <input type="number" name="requirements[{{ $shift->id }}][minimum_senior]" min="0" max="100"
                                   value="{{ old('requirements.'.$shift->id.'.minimum_senior', $requirement->minimum_senior ?? 0) }}">
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </fieldset>
    <div class="organization-form-footer"><button class="btn btn-primary" type="submit">Save coverage standard</button></div>
</form>
