<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Models\LeaveAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveAttachmentController extends Controller
{
    use ScopesWorkforceAccess;

    /**
     * Leave attachments are fit notes and medical certificates. They are the
     * most sensitive thing an employee puts into this system, so a supervisor
     * reaches them only for their own unit; everyone else reaches only their
     * own.
     */
    public function download(Request $request, LeaveAttachment $leaveAttachment): StreamedResponse
    {
        $leaveAttachment->load('leaveRequest.employee');
        $employee = $leaveAttachment->leaveRequest->employee;

        // Written as an explicit "is this mine" rather than comparing the two
        // ids directly: an account with no employee row and an attachment with
        // no employee would otherwise compare null to null and match.
        $ownRecord = $employee !== null && $employee->id === $request->user()->employee?->id;

        if (! $ownRecord) {
            $this->requireSupervision($request, $employee);
        }

        return Storage::disk($leaveAttachment->disk)->download($leaveAttachment->path, $leaveAttachment->original_name);
    }
}
