{{-- Adding a position happens over the directory rather than on its own page,
     so the roles it joins stay in view behind the form. The full create page
     remains reachable at /positions/create as a no-JS fallback. --}}
@php($createFormFailed = $errors->any() && old('_form') === 'create-position')
<div class="modal fade" id="createPositionModal" tabindex="-1" aria-labelledby="createPositionModalLabel" aria-hidden="true" @if($createFormFailed) data-open-on-error @endif>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content schedule-modal-content">
            <form method="POST" action="{{ route('positions.store') }}">
                @csrf
                {{-- Marks the flashed old input as this form's, so a failed submit
                     reopens the modal instead of the directory's own filters. --}}
                <input type="hidden" name="_form" value="create-position">
                <div class="modal-header">
                    <div><p class="panel-kicker">Organization · Positions</p><h2 class="modal-title" id="createPositionModalLabel">Add position</h2></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body organization-modal-form">
                    <p class="organization-modal-intro">Create an approved role and assign it to the correct department.</p>
                    @include('positions._form-fields', ['position' => null])
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create position</button></div>
            </form>
        </div>
    </div>
</div>
