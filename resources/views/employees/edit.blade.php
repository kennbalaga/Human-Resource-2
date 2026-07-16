@extends('layouts.app')
@section('title', 'Edit Employee')
@section('content')
    <div class="organization-form-shell">
        <section class="page-heading"><div><p class="eyebrow">Organization · Employees</p><h1>Edit {{ $employee->full_name }}</h1><p>Update identity, assignment, employment status, and contact information.</p></div></section>
        @include('partials.organization-feedback')
        @include('employees._form')
    </div>
@endsection
