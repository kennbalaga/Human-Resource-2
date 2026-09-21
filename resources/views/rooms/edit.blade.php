@extends('layouts.app')
@section('title', 'Edit Room')
@section('content')
    <div class="organization-form-shell">
        <section class="page-heading"><div><p class="eyebrow">Organization · Rooms</p><h1>Edit {{ $room->code }}</h1><p>{{ $room->name }} — {{ $room->department?->name ?? 'Unassigned unit' }}</p></div></section>
        @include('partials.organization-feedback')
        @include('rooms._form')
        @include('rooms._shift-coverage')
    </div>
@endsection
