{{-- Adding a department happens over the directory rather than on its own page,
     so the units it joins stay in view behind the form. The full create page
     remains reachable at /departments/create as a no-JS fallback. --}}
@php($createFormFailed = $errors->any() && old('_form') === 'create-department')
<div class="modal fade" id="createDepartmentModal" tabindex="-1" aria-labelledby="createDepartmentModalLabel" aria-hidden="true" @if($createFormFailed) data-open-on-error @endif>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content schedule-modal-content">
            <form method="POST" action="{{ route('departments.store') }}">
                @csrf
                {{-- Marks the flashed old input as this form's, so a failed submit
                     reopens the modal instead of the directory's own filters. --}}
                <input type="hidden" name="_form" value="create-department">
                <div class="modal-header">
                    <div><p class="panel-kicker">Organization · Departments</p><h2 class="modal-title" id="createDepartmentModalLabel">Add department</h2></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body organization-modal-form">
                    <p class="organization-modal-intro">Create an operational unit for employee and position assignments.</p>
                    @include('departments._form-fields', ['department' => null])
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create department</button></div>
            </form>
        </div>
    </div>
</div>
