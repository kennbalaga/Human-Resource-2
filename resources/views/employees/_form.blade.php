@php($editing = isset($employee))
<form class="panel organization-form" method="POST" action="{{ $editing ? route('employees.update', $employee) : route('employees.store') }}" data-employee-assignment>
    @csrf
    @if($editing) @method('PUT') @endif

    {{-- The record names itself before its fields do, and says plainly that
         nothing has been written yet — a long form with a Save at the far end
         otherwise gives no clue whether it is holding anything. --}}
    <div class="panel-header">
        <div>
            <p class="panel-kicker">{{ $editing ? 'Existing record' : 'New record' }}</p>
            <h2>Employee details</h2>
        </div>
        <span class="status-badge status-secondary"><span class="status-dot"></span>{{ $editing ? 'Saved record' : 'Draft · not yet saved' }}</span>
    </div>

    @include('employees._form-fields')

    <div class="organization-form-footer">
        <span class="organization-form-note">The account invitation is emailed once the record is saved.</span>
        <a class="btn btn-light" href="{{ route('employees.index') }}">Cancel</a>
        <button class="btn btn-primary" type="submit"><x-icon name="check" /> {{ $editing ? 'Save changes' : 'Save employee' }}</button>
    </div>
</form>
