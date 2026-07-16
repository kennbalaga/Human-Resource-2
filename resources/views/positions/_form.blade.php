@php($editing = isset($position))
<form class="panel organization-form" method="POST" action="{{ $editing ? route('positions.update', $position) : route('positions.store') }}">
    @csrf
    @if($editing) @method('PUT') @endif
    <fieldset class="organization-fieldset">
        <legend>Position details</legend>
        <label class="organization-field"><span>Position code</span><input type="text" name="code" maxlength="30" required value="{{ old('code', $position->code ?? '') }}" placeholder="HR-OFFICER">@error('code')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field"><span>Position title</span><input type="text" name="title" maxlength="150" required value="{{ old('title', $position->title ?? '') }}" placeholder="HR Officer">@error('title')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field organization-field-full"><span>Department</span><select name="department_id" required><option value="">Select department</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id', $position->department_id ?? null) == $department->id)>{{ $department->name }}</option>@endforeach</select>@error('department_id')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-field organization-field-full"><span>Description</span><textarea name="description" maxlength="1000" placeholder="Responsibilities and purpose of this position">{{ old('description', $position->description ?? '') }}</textarea>@error('description')<small class="organization-error">{{ $message }}</small>@enderror</label>
        <label class="organization-checkbox organization-field-full"><input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $position->is_active ?? true))><div><strong>Active position</strong><small>Active positions are available for employee assignments.</small></div></label>
    </fieldset>
    <div class="organization-form-footer"><a class="btn btn-light" href="{{ route('positions.index') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $editing ? 'Save changes' : 'Create position' }}</button></div>
</form>
