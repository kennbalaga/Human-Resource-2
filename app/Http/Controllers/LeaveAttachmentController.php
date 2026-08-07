<?php

namespace App\Http\Controllers;

use App\Models\LeaveAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveAttachmentController extends Controller
{
    public function download(Request $request, LeaveAttachment $leaveAttachment): StreamedResponse
    {
        $leaveAttachment->load('leaveRequest');
        $canManage = Gate::forUser($request->user())->allows('workforce.view');
        abort_unless($canManage || $leaveAttachment->leaveRequest->employee_id === $request->user()->employee?->id, 403);

        return Storage::disk($leaveAttachment->disk)->download($leaveAttachment->path, $leaveAttachment->original_name);
    }
}
