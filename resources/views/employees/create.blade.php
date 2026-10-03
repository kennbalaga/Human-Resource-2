@extends('layouts.app')
@section('title', 'Add Employee')
@section('content')
    <div class="organization-form-shell">
        <section class="page-heading">
            <div>
                <p class="eyebrow">Organization · Employees</p>
                <h1>Add an employee</h1>
                <p>Create the workforce record and the account that signs in with it.</p>
            </div>
            {{-- The way back is a control in the action row, not a link hidden
                 in the eyebrow above it. --}}
            <a class="btn btn-light dashboard-action" href="{{ route('employees.index') }}">Back to directory</a>
        </section>
        @include('partials.organization-feedback')
        @include('employees._form')
    </div>
@endsection
