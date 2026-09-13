<?php

namespace App\Console\Commands;

use App\Models\LeaveAttachment;
use App\Services\Leave\LeaveAttachmentStorage;
use Illuminate\Console\Command;

/**
 * Brings leave attachments uploaded before they were kept in the database
 * across from the laptop that has the file.
 *
 * Each file exists only on the computer that took the upload, so this is run on
 * every computer that ever did. Whatever this one does not have is named and
 * left for the computer that does. Files are copied, not deleted.
 */
class MoveLeaveAttachmentsToDatabaseCommand extends Command
{
    protected $signature = 'leave-attachments:move-to-database
        {--dry-run : Report what would be moved without touching anything}';

    protected $description = 'Copy leave attachment files stored on this computer into the shared database.';

    public function handle(LeaveAttachmentStorage $storage): int
    {
        $pending = LeaveAttachment::query()
            ->where('disk', '!=', LeaveAttachmentStorage::DATABASE_DISK)
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Every leave attachment is already in the shared database.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $elsewhere = [];

        foreach ($pending as $attachment) {
            $label = "#{$attachment->id} {$attachment->original_name}";

            // A dry run only asks the filesystem; it writes nothing.
            $result = $dryRun
                ? ($storage->isOnThisComputer($attachment) ? 'moved' : 'missing')
                : $storage->moveToDatabase($attachment);

            if ($result === 'missing') {
                $elsewhere[] = $label;

                continue;
            }

            $moved++;
            $this->line(($dryRun ? 'Would move: ' : 'Moved: ').$label);
        }

        $this->info($dryRun
            ? "{$moved} attachment(s) would be moved into the shared database."
            : "{$moved} attachment(s) moved into the shared database.");

        if ($elsewhere !== []) {
            $this->warn(count($elsewhere).' attachment(s) are not on this computer. Run this command on the laptop that uploaded them:');
            foreach ($elsewhere as $line) {
                $this->warn('  · '.$line);
            }
        }

        return self::SUCCESS;
    }
}
