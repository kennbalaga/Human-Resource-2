@extends('layouts.app')
@section('title', $employee->full_name)

@section('content')
    <section class="page-heading">
        <div><p class="eyebrow">Organization · Employees</p><h1>Employee profile</h1><p>Verified workforce identity and current organizational assignment.</p></div>
        <div class="organization-heading-actions"><a class="btn btn-light dashboard-action" href="{{ route('employees.index') }}">Back to directory</a></div>
    </section>

    @include('partials.organization-feedback')

    @include('employees._record')
@endsection
