@extends('layouts.app')
@section('title', 'Edit Department')
@section('content')
    <div class="organization-form-shell"><section class="page-heading"><div><p class="eyebrow">Organization · Departments</p><h1>Edit {{ $department->name }}</h1><p>{{ $department->employees_count }} employees and {{ $department->positions_count }} positions currently reference this department.</p></div></section>@include('partials.organization-feedback')@include('departments._form')</div>
@endsection
