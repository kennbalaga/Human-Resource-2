<?php

namespace App\Services\Leave;

use App\Models\LeaveAttachment;
use App\Models\LeaveAttachmentContent;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Where a leave attachment's file lives, and how it is read back.
 *
 * The app runs on several laptops against one shared database, so a file
 * written to whichever laptop took the upload was missing on every other one:
 * the leave request everybody could see pointed at a certificate only one of
 * them could open. The `database` disk keeps the file in the same shared
 * database as the row describing it.
 *
 * Rows written to a filesystem disk before that are still read from it, and
 * `leave-attachments:move-to-database` brings them across from the laptop that
 * has the file.
 */
class LeaveAttachmentStorage
{
    public const DATABASE_DISK = 'database';

    public function store(UploadedFile $file, LeaveRequest $leave, User $uploader): LeaveAttachment
    {
        $disk = (string) config('workforce.attachment_disk');
        $directory = 'leave-attachments/'.$leave->uuid;
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();

        $attributes = [
            'leave_request_id' => $leave->id,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $uploader->id,
        ];

        if ($disk !== self::DATABASE_DISK) {
            return LeaveAttachment::query()->create($attributes + [
                'path' => $file->storeAs($directory, $filename, $disk),
            ]);
        }

        return DB::transaction(function () use ($attributes, $directory, $filename, $file): LeaveAttachment {
            $attachment = LeaveAttachment::query()->create($attributes + ['path' => $directory.'/'.$filename]);

            LeaveAttachmentContent::query()->create([
                'leave_attachment_id' => $attachment->id,
                'contents' => (string) file_get_contents($file->getRealPath()),
            ]);

            return $attachment;
        });
    }

    public function download(LeaveAttachment $attachment): StreamedResponse
    {
        $name = $attachment->original_name;
        $headers = ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'];

        if ($attachment->disk === self::DATABASE_DISK) {
            $contents = LeaveAttachmentContent::query()->whereKey($attachment->id)->value('contents');

            abort_if($contents === null, 404, 'This attachment file could not be found.');

            return response()->streamDownload(function () use ($contents): void {
                echo $contents;
            }, $name, $headers);
        }

        // An older row whose file was uploaded on another laptop and has not
        // been moved into the database yet. Named for what it is, rather than
        // surfacing as a server error.
        abort_unless(
            $this->isOnThisComputer($attachment),
            404,
            'This attachment was uploaded on another computer and has not been moved to the shared database yet.',
        );

        return Storage::disk($attachment->disk)->download($attachment->path, $name, $headers);
    }

    /**
     * Copy a filesystem attachment into the database, if this computer has it.
     * The original file is left where it is.
     *
     * @return 'moved'|'missing'|'already'
     */
    public function moveToDatabase(LeaveAttachment $attachment): string
    {
        if ($attachment->disk === self::DATABASE_DISK) {
            return 'already';
        }

        if (! $this->isOnThisComputer($attachment)) {
            return 'missing';
        }

        $contents = (string) Storage::disk($attachment->disk)->get($attachment->path);

        DB::transaction(function () use ($attachment, $contents): void {
            LeaveAttachmentContent::query()->updateOrCreate(
                ['leave_attachment_id' => $attachment->id],
                ['contents' => $contents],
            );

            $attachment->forceFill(['disk' => self::DATABASE_DISK])->save();
        });

        return 'moved';
    }

    /**
     * Whether this computer holds the file for a filesystem attachment. Always
     * true for one kept in the database.
     */
    public function isOnThisComputer(LeaveAttachment $attachment): bool
    {
        if ($attachment->disk === self::DATABASE_DISK) {
            return true;
        }

        try {
            return Storage::disk($attachment->disk)->exists($attachment->path);
        } catch (Throwable) {
            // A disk this computer has no configuration or credentials for.
            return false;
        }
    }
}
