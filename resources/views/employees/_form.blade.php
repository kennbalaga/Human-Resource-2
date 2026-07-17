@php($editing = isset($employee))
@php($selectedDepartmentId = old('department_id', $employee->department_id ?? null))
@php($selectedDepartmentCode = $departments->firstWhere('id', (int) $selectedDepartmentId)?->code)
<form class="panel organization-form" method="POST" action="{{ $editing ? route('employees.update', $employee) : route('employees.store') }}" data-employee-assignment>
    @csrf
    @if($editing) @method('PUT') @endif

    <fieldset class="organization-fieldset">
        <legend>Account and identity</legend>
        @if($editing)
            <label class="organization-field"><span>Employee ID</span><input type="text" value="{{ $employee->employee_number }}" readonly aria-readonly="true"><small>Permanent identity value. Department changes do not rename an existing employee ID.</small></label>
        @elseif($employeeNumberAutoGenerate)
            <label class="organization-field"><span>Employee ID</span><input type="text" value="{{ $selectedDepartmentCode ? $selectedDepartmentCode.'-[next]' : 'Select a department first' }}" readonly aria-readonly="true" data-generated-employee-number><small>Generated securely from the department code when the employee is created.</small></label>
        @else
            <label class="organization-field"><span>Employee ID</span><input type="text" name="employee_number" maxlength="50" required value="{{ old('employee_number') }}" placeholder="HR-0003">@error('employee_number')<small class="organization-error">{{ $message }}</small>@enderror</label>
        @endif
        <label class="organization-field"><span>Work email</span><input type="email" name="email" maxlength="255" required value="{{ old('email', isset($employee) ? $employee->user?->email : '') }}" placeholder="employee@hospital.test">@error('email')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>First name</span><input type="text" name="first_name" maxlength="100" required value="{{ old('first_name', $employee->first_name ?? '') }}">@error('first_name')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Middle name</span><input type="text" name="middle_name" maxlength="100" value="{{ old('middle_name', $employee->middle_name ?? '') }}">@error('middle_name')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Last name</span><input type="text" name="last_name" maxlength="100" required value="{{ old('last_name', $employee->last_name ?? '') }}">@error('last_name')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Suffix</span><input type="text" name="suffix" maxlength="20" value="{{ old('suffix', $employee->suffix ?? '') }}" placeholder="Jr., III">@error('suffix')<small class="organization-error">{{ $message }}</small>@enderror</label>
    </fieldset>

    <fieldset class="organization-fieldset">
        <legend>Work assignment</legend>
        <label class="organization-field"><span>Department</span><select name="department_id" required data-department-select><option value="">Select department</option>@foreach($departments as $department)<option value="{{ $department->id }}" data-department-code="{{ $department->code }}" @selected($selectedDepartmentId == $department->id)>{{ $department->name }}</option>@endforeach</select>@error('department_id')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Position</span><select name="position_id" required data-position-select><option value="">Select position</option>@foreach($positions as $position)<option value="{{ $position->id }}" data-department-id="{{ $position->department_id }}" @selected(old('position_id', $employee->position_id ?? null) == $position->id)>{{ $position->title }} · {{ $position->department?->code }}</option>@endforeach</select>@error('position_id')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Supervisor</span><select name="supervisor_id"><option value="">No direct supervisor</option>@foreach($supervisors as $supervisor)@if(!isset($employee) || $employee->id !== $supervisor->id)<option value="{{ $supervisor->id }}" @selected(old('supervisor_id', $employee->supervisor_id ?? null) == $supervisor->id)>{{ $supervisor->full_name }} · {{ $supervisor->employee_number }}</option>@endif @endforeach</select>@error('supervisor_id')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Employment status</span><select name="employment_status" required>@foreach(['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive', 'terminated' => 'Terminated'] as $value => $label)<option value="{{ $value }}" @selected(old('employment_status', $employee->employment_status ?? 'active') === $value)>{{ $label }}</option>@endforeach</select>@error('employment_status')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Hire date</span><input type="date" name="hire_date" required value="{{ old('hire_date', isset($employee) ? $employee->hire_date?->format('Y-m-d') : now()->format('Y-m-d')) }}">@error('hire_date')<small class="organization-error">{{ $message }}</small>@enderror</label>
    </fieldset>

    <fieldset class="organization-fieldset">
        <legend>Contact information</legend>
        <label class="organization-field"><span>Contact number</span><input type="text" name="contact_number" maxlength="30" value="{{ old('contact_number', $employee->contact_number ?? '') }}" placeholder="+63 900 000 0000">@error('contact_number')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field organization-field-full"><span>Home address</span><textarea name="address" maxlength="1000" placeholder="Complete residential address">{{ old('address', $employee->address ?? '') }}</textarea>@error('address')<small class="organization-error">{{ $message }}</small>@enderror</label>
    </fieldset>

    <div class="organization-form-footer"><a class="btn btn-light" href="{{ $editing ? route('employees.show', $employee) : route('employees.index') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $editing ? 'Save changes' : 'Create employee' }}</button></div>
</form>
