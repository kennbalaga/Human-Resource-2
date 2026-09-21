@extends('layouts.app')
@section('title', 'Add Room')
@section('content')
    <div class="organization-form-shell"><section class="page-heading"><div><p class="eyebrow">Organization · Rooms</p><h1>Add room</h1><p>Record a theatre, ward, delivery room or clinic room so a rostered shift can be given a place to stand.</p></div></section>@include('partials.organization-feedback')@include('rooms._form')</div>
@endsection
