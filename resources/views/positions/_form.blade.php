@php($editing = isset($position))
<form class="panel organization-form" method="POST" action="{{ $editing ? route('positions.update', $position) : route('positions.store') }}">
    @csrf
    @if($editing) @method('PUT') @endif

    @include('positions._form-fields')

    <div class="organization-form-footer"><a class="btn btn-light" href="{{ route('positions.index') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $editing ? 'Save changes' : 'Create position' }}</button></div>
</form>
