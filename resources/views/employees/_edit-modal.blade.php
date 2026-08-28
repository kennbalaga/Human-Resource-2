{{-- One shell for every row's Edit button: the form inside is fetched for
     whichever employee was clicked, so the directory does not carry fifteen
     rendered forms it will almost certainly never show.

     The exception is a submit that came back with errors. That response is a
     fresh render of the directory, so the form has to be here already for the
     messages and the rejected input to survive — the same trick the add-employee
     modal uses. --}}
<div class="modal fade" id="editEmployeeModal" tabindex="-1" aria-labelledby="editEmployeeModalLabel" aria-hidden="true" @if($editEmployee) data-open-on-error @endif>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        {{-- The loading state lives out here rather than as the shell's starting
             content, because on the error render the shell starts out holding a
             form and the script would take that for its placeholder. --}}
        <template data-employee-edit-loading>
            <div class="modal-body organization-modal-form">
                <p class="employee-panel-status"><span class="employee-panel-spinner" aria-hidden="true"></span> Loading employee form…</p>
            </div>
        </template>
        <div class="modal-content schedule-modal-content" data-employee-edit-body>
            @if($editEmployee)
                @include('employees._edit-modal-form', ['employee' => $editEmployee])
            @endif
        </div>
    </div>
</div>
