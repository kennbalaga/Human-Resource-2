<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The bytes of leave attachments stored on the `database` disk.
     *
     * Kept apart from leave_attachments so listing a leave request's
     * attachments never drags their contents along with it.
     */
    public function up(): void
    {
        Schema::create('leave_attachment_contents', function (Blueprint $table): void {
            $table->foreignId('leave_attachment_id')->primary()->constrained('leave_attachments')->cascadeOnDelete();
            $table->binary('contents');
        });

        // Laravel's binary column is a BLOB on MySQL, which stops at 64 KB.
        // Attachments are allowed up to WORKFORCE_ATTACHMENT_MAX_KB (5 MB).
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE leave_attachment_contents MODIFY contents LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_attachment_contents');
    }
};
