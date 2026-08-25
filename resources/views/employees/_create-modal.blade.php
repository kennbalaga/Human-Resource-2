{{-- Adding an employee happens over the directory rather than on its own page,
     so the list the new hire joins stays in view behind the form. The full
     create page remains reachable at /employees/create as a no-JS fallback. --}}
@php($createFormFailed = $errors->any() && old('_form') === 'create-employee')
<div class="modal fade" id="createEmployeeModal" tabindex="-1" aria-labelledby="createEmployeeModalLabel" aria-hidden="true" @if($createFormFailed) data-open-on-error @endif>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content schedule-modal-content">
            <form method="POST" action="{{ route('employees.store') }}" data-employee-assignment>
                @csrf
                {{-- Marks the flashed old input as this form's, so a failed submit
                     reopens the modal instead of the directory's own filters. --}}
                <input type="hidden" name="_form" value="create-employee">
                <div class="modal-header">
                    <div><p class="panel-kicker">Organization · Employees</p><h2 class="modal-title" id="createEmployeeModalLabel">Add employee</h2></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body organization-modal-form">
                    <p class="organization-modal-intro">Create a workforce profile and send a secure password setup link to the employee’s work email.</p>
                    @include('employees._form-fields', ['employee' => null])
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create employee</button></div>
            </form>
        </div>
    </div>
</div>
