@extends('layouts.app')
@section('title', 'Add Employee')
@section('content')
    <div class="organization-form-shell">
        <section class="page-heading"><div><p class="eyebrow">Organization · Employees</p><h1>Add employee</h1><p>Create a workforce profile and send a secure password setup link to the employee’s work email.</p></div></section>
        @include('partials.organization-feedback')
        @include('employees._form')
    </div>
@endsection
