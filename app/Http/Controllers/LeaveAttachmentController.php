<?php

namespace App\Http\Controllers;

use App\Models\LeaveAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveAttachmentController extends Controller
{
    public function download(Request $request, LeaveAttachment $leaveAttachment): StreamedResponse
    {
        $leaveAttachment->load('leaveRequest');
        $canManage = $request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty();
        abort_unless($canManage || $leaveAttachment->leaveRequest->employee_id === $request->user()->employee?->id, 403);

        return Storage::disk($leaveAttachment->disk)->download($leaveAttachment->path, $leaveAttachment->original_name);
    }
}
