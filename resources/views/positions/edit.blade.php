@extends('layouts.app')
@section('title', 'Edit Position')
@section('content')
    <div class="organization-form-shell"><section class="page-heading"><div><p class="eyebrow">Organization · Positions</p><h1>Edit {{ $position->title }}</h1><p>{{ $position->employees_count }} {{ str('employee')->plural($position->employees_count) }} currently reference this position.</p></div></section>@include('partials.organization-feedback')@include('positions._form')</div>
@endsection
