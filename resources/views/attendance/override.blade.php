@extends('layouts.app')

@section('title', 'Attendance Override')

@section('content')
    <section class="page-heading attendance-heading">
        <div>
            <p class="eyebrow">Workforce Management</p>
            <h1>Attendance Override</h1>
            <p>Authorise a punch for an employee with no published shift covering the current time. Every override is logged against your account with the reason you give.</p>
        </div>
        <a class="btn btn-outline-primary dashboard-action" href="{{ route('attendance.index') }}">
            <x-icon name="clock" /> Back to attendance
        </a>
    </section>

    @if (session('success'))
        <div class="attendance-alert attendance-alert-success" role="status">
            <x-icon name="check-circle" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="attendance-alert attendance-alert-danger" role="alert">
            <x-icon name="close" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <section class="panel attendance-clock-panel">
        <form method="GET" action="{{ route('attendance.override.index') }}" class="attendance-form">
            <label class="attendance-note">
                <span>Find employee</span>
                <input type="text" name="q" value="{{ $query }}" placeholder="Employee number or name..." maxlength="100">
            </label>
            <button class="btn btn-outline-primary" type="submit">
                <x-icon name="search" /> <span>Search</span>
            </button>
        </form>
    </section>

    @if ($query !== '')
        <section class="panel attendance-history-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-kicker">Results</p>
                    <h2>{{ $employees->count() }} employee(s) found</h2>
                </div>
            </div>

            <div class="table-responsive">
                <table class="dashboard-table attendance-table">
                    <thead>
                        <tr>
                            <th>Employee #</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Reason</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($employees as $employee)
                            <tr>
                                <td>{{ $employee->employee_number }}</td>
                                <td>{{ $employee->full_name }}</td>
                                <td>{{ $employee->department?->name ?? '—' }}</td>
                                <td>
                                    <input type="text" form="override-reason-{{ $employee->id }}" name="reason" maxlength="500" placeholder="Reason for authorising this punch" required style="width: 100%;">
                                </td>
                                <td>
                                    <div style="display:flex; gap:.5rem;">
                                        <form id="override-reason-{{ $employee->id }}" method="POST" action="{{ route('attendance.override.check-in') }}">
                                            @csrf
                                            <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                                            <input type="hidden" name="office_location_id" value="{{ $office->id }}">
                                        </form>
                                        <button class="btn btn-primary" type="submit" form="override-reason-{{ $employee->id }}" formaction="{{ route('attendance.override.check-in') }}">
                                            <x-icon name="log-in" /> Check in
                                        </button>
                                        <button class="btn btn-light" type="submit" form="override-reason-{{ $employee->id }}" formaction="{{ route('attendance.override.check-out') }}">
                                            <x-icon name="log-out" /> Check out
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="empty-table-cell">
                                    <x-icon name="clock" />
                                    <strong>No matching employees</strong>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
