@php($editing = isset($department))
<form class="panel organization-form" method="POST" action="{{ $editing ? route('departments.update', $department) : route('departments.store') }}">
    @csrf
    @if($editing) @method('PUT') @endif

    @include('departments._form-fields')

    <div class="organization-form-footer"><a class="btn btn-light" href="{{ route('departments.index') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $editing ? 'Save changes' : 'Create department' }}</button></div>
</form>
