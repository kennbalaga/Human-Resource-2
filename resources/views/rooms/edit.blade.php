@extends('layouts.app')
@section('title', 'Edit Room')
@section('content')
    <div class="organization-form-shell">
        {{-- The way back out. A form page reached from a list needs one; the
             browser's own Back is not an interface. --}}
        <section class="page-heading"><div><p class="eyebrow">Organization · Rooms</p><h1>Edit {{ $room->code }}</h1><p>{{ $room->name }} — {{ $room->department?->name ?? 'Unassigned unit' }}</p></div><a class="btn btn-light" href="{{ route('rooms.index') }}">Back to rooms</a></section>
        @include('partials.organization-feedback')
        @include('rooms._form')
        @include('rooms._shift-coverage')
    </div>
@endsection
