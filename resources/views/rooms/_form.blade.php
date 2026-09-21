@php($editing = isset($room))
<form class="panel organization-form" method="POST" action="{{ $editing ? route('rooms.update', $room) : route('rooms.store') }}">
    @csrf
    @if($editing) @method('PUT') @endif

    @include('rooms._form-fields')

    <div class="organization-form-footer"><a class="btn btn-light" href="{{ route('rooms.index') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $editing ? 'Save changes' : 'Create room' }}</button></div>
</form>
