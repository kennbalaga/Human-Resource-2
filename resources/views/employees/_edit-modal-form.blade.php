{{-- The edit form as a bare fragment, fetched into the shared modal shell. Also
     rendered straight into that shell by the directory when a submit came back
     with errors, which is why it carries its own header and footer rather than
     leaving them to the shell. --}}
<form method="POST" action="{{ route('employees.update', $employee) }}" data-employee-assignment>
    @csrf
    @method('PUT')
    {{-- Marks the flashed old input as this employee's edit form, so a failed
         submit reopens the modal on the right record instead of the directory's
         own filters or the add-employee modal. --}}
    <input type="hidden" name="_form" value="edit-employee">
    <input type="hidden" name="_form_employee" value="{{ $employee->id }}">
    <div class="modal-header">
        <div><p class="panel-kicker">Existing record</p><h2 class="modal-title" id="editEmployeeModalLabel">{{ $employee->full_name }}</h2></div>
        <span class="status-badge status-secondary"><span class="status-dot"></span>Saved record</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body organization-modal-form">
        <p class="organization-modal-intro">Update identity, assignment, employment status, and contact information.</p>
        @include('employees._form-fields')
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save changes</button></div>
</form>
