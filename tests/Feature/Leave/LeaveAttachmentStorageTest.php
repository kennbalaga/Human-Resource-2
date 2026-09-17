<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveAttachment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\ConfirmsDownloadPassword;
use Tests\TestCase;

/**
 * Several computers run the app against one shared database, so an attachment
 * has to open on every one of them, not only on the one that took the upload.
 */
class LeaveAttachmentStorageTest extends TestCase
{
    use ConfirmsDownloadPassword, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_an_attachment_opens_on_a_computer_that_never_had_the_file(): void
    {
        // Set here rather than read from the environment: CI builds its .env
        // from .env.example, and this test is about the database disk itself.
        config(['workforce.attachment_disk' => 'database']);
        Storage::fake('local');
        $employee = $this->employee();
        $certificate = UploadedFile::fake()->create('fit-note.pdf', 50, 'application/pdf');
        $contents = (string) file_get_contents($certificate->getRealPath());

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => LeaveType::query()->where('code', 'SICK')->value('id'),
            'start_date' => '2027-07-02',
            'end_date' => '2027-07-02',
            'reason' => 'Medical rest advised by physician.',
            'attachments' => [$certificate],
        ])->assertSessionHasNoErrors();

        // Another computer: its own empty filesystem, the same database.
        Storage::fake('local');
        $attachment = LeaveAttachment::query()->firstOrFail();

        $this->actingAs($employee)->get(route('leave-attachments.download', $attachment))
            ->assertOk()
            ->assertStreamedContent($contents);
        $this->assertDatabaseHas('leave_attachment_contents', ['leave_attachment_id' => $attachment->id]);
    }

    public function test_an_older_attachment_missing_from_this_computer_is_not_found_rather_than_an_error(): void
    {
        Storage::fake('local');
        $employee = $this->employee();
        $attachment = $this->localAttachment($employee, 'uploaded-elsewhere.pdf');

        $this->actingAs($employee)->get(route('leave-attachments.download', $attachment))->assertNotFound();
    }

    public function test_moving_attachments_copies_the_files_this_computer_has_and_names_the_rest(): void
    {
        Storage::fake('local');
        $employee = $this->employee();
        $here = $this->localAttachment($employee, 'on-this-laptop.pdf');
        Storage::disk('local')->put($here->path, 'certificate bytes');
        $elsewhere = $this->localAttachment($employee, 'on-a-groupmates-laptop.pdf');

        $this->artisan('leave-attachments:move-to-database')
            ->expectsOutputToContain('Moved: #'.$here->id)
            ->expectsOutputToContain('on-a-groupmates-laptop.pdf')
            ->assertSuccessful();

        $this->assertSame('database', $here->refresh()->disk);
        $this->assertSame('local', $elsewhere->refresh()->disk);

        // The copy is what is served now, even with the original gone.
        Storage::disk('local')->delete($here->path);
        $this->actingAs($employee)->get(route('leave-attachments.download', $here))
            ->assertOk()
            ->assertStreamedContent('certificate bytes');
    }

    public function test_a_dry_run_moves_nothing(): void
    {
        Storage::fake('local');
        $employee = $this->employee();
        $attachment = $this->localAttachment($employee, 'on-this-laptop.pdf');
        Storage::disk('local')->put($attachment->path, 'certificate bytes');

        $this->artisan('leave-attachments:move-to-database', ['--dry-run' => true])
            ->expectsOutputToContain('Would move: #'.$attachment->id)
            ->assertSuccessful();

        $this->assertSame('local', $attachment->refresh()->disk);
        $this->assertDatabaseCount('leave_attachment_contents', 0);
    }

    private function employee(): User
    {
        return User::query()->where('email', 'employee@hrms.local')->firstOrFail();
    }

    /** An attachment row from before files were kept in the database. */
    private function localAttachment(User $employee, string $name): LeaveAttachment
    {
        $leave = LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'SICK')->value('id'),
            'start_date' => '2027-07-0'.random_int(1, 9),
            'end_date' => '2027-07-09',
            'requested_days' => 1,
            'reason' => 'Medical rest advised by physician.',
            'status' => 'pending',
        ]);

        return LeaveAttachment::query()->create([
            'leave_request_id' => $leave->id,
            'disk' => 'local',
            'path' => 'leave-attachments/'.$leave->uuid.'/'.Str::uuid().'.pdf',
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size_bytes' => 17,
            'uploaded_by' => $employee->id,
        ]);
    }
}
