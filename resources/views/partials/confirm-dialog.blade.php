{{-- The one confirmation prompt, filled in by confirm-actions.js for whatever
     asked. A native <dialog> rather than a Bootstrap modal: showModal() puts it
     in the top layer, so it opens cleanly over the employee record's offcanvas
     or another modal without a stacking fight. The icons are all here, hidden,
     so the script only has to choose one. --}}
<dialog class="confirm-dialog" id="confirmDialog" aria-labelledby="confirmDialogTitle" aria-describedby="confirmDialogMessage" data-confirm-dialog>
    <form method="dialog" class="confirm-dialog-card">
        <div class="confirm-dialog-body">
            <div class="confirm-dialog-icon" aria-hidden="true">
                <span data-confirm-icon="neutral"><x-icon name="check-circle" /></span>
                <span data-confirm-icon="caution"><x-icon name="alert" /></span>
                <span data-confirm-icon="danger"><x-icon name="trash" /></span>
            </div>
            <p class="confirm-dialog-kicker" data-confirm-kicker>Please confirm</p>
            <h2 class="confirm-dialog-title" id="confirmDialogTitle" data-confirm-title></h2>
            <p class="confirm-dialog-message" id="confirmDialogMessage" data-confirm-message></p>
        </div>
        <div class="confirm-dialog-actions">
            <button class="btn btn-light" type="submit" value="cancel" data-confirm-cancel>Cancel</button>
            <button class="btn btn-primary" type="submit" value="ok" data-confirm-ok>Confirm</button>
        </div>
    </form>
</dialog>
