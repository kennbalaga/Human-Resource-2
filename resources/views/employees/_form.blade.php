@php($editing = isset($employee))
<form class="panel organization-form" method="POST" action="{{ $editing ? route('employees.update', $employee) : route('employees.store') }}" data-employee-assignment>
    @csrf
    @if($editing) @method('PUT') @endif

    @include('employees._form-fields')

    <div class="organization-form-footer"><a class="btn btn-light" href="{{ route('employees.index') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $editing ? 'Save changes' : 'Create employee' }}</button></div>
</form>
