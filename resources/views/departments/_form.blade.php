@php($editing = isset($department))
<form class="panel organization-form" method="POST" action="{{ $editing ? route('departments.update', $department) : route('departments.store') }}">
    @csrf
    @if($editing) @method('PUT') @endif
    <fieldset class="organization-fieldset">
        <legend>Department details</legend>
        <label class="organization-field"><span>Department group</span><select name="category" required>@foreach($categories as $value => $label)<option value="{{ $value }}" @selected(old('category', $department->category ?? \App\Models\Department::CATEGORY_ADMINISTRATIVE) === $value)>{{ $label }}</option>@endforeach</select>@error('category')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Department code</span><input type="text" name="code" maxlength="20" required value="{{ old('code', $department->code ?? '') }}" placeholder="HR">@error('code')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Department name</span><input type="text" name="name" maxlength="150" required value="{{ old('name', $department->name ?? '') }}" placeholder="Human Resources">@error('name')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Bed capacity</span><input type="number" name="bed_capacity" min="0" max="9999" value="{{ old('bed_capacity', $department->bed_capacity ?? '') }}" placeholder="Leave blank for a non-bedded unit">@error('bed_capacity')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Nurse-to-patient ratio</span><input type="number" name="nurse_patient_ratio" min="1" max="100" value="{{ old('nurse_patient_ratio', $department->nurse_patient_ratio ?? '') }}" placeholder="{{ \App\Models\Department::DEFAULT_NURSE_PATIENT_RATIO }}"><small>Patients per nurse on duty. The DOH general-ward standard is 12; critical units are far lower.</small>@error('nurse_patient_ratio')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field organization-field-full"><span>Description</span><textarea name="description" maxlength="1000" placeholder="Purpose and responsibilities of this department">{{ old('description', $department->description ?? '') }}</textarea>@error('description')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-checkbox organization-field-full"><input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $department->is_active ?? true))><div><strong>Active department</strong><small>Active departments can be used for new employee and position assignments.</small></div></label>
    </fieldset>
    <div class="organization-form-footer"><a class="btn btn-light" href="{{ route('departments.index') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $editing ? 'Save changes' : 'Create department' }}</button></div>
</form>
