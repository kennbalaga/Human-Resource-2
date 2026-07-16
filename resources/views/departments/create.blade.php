@extends('layouts.app')
@section('title', 'Add Department')
@section('content')
    <div class="organization-form-shell"><section class="page-heading"><div><p class="eyebrow">Organization · Departments</p><h1>Add department</h1><p>Create an operational unit for employee and position assignments.</p></div></section>@include('partials.organization-feedback')@include('departments._form')</div>
@endsection
