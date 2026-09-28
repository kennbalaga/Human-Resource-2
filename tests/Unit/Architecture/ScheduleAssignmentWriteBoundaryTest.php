<?php

namespace Tests\Unit\Architecture;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Layer 3 of the write-boundary invariant: a build-time check that nothing
 * outside the known, RosterWriteContext-guarded call sites writes to
 * `schedule_assignments`. See ScheduleAssignmentWriteVisitor for what "writes"
 * means here and its documented limits.
 *
 * The behavioral half of Layer 3 — "RecommendationLifecycleService::apply()
 * writes zero ScheduleAssignment rows" — is already asserted by
 * tests/Feature/Schedule/AiScheduleRecommendationLifecycleTest.php::test_apply_revalidates_and_returns_only_the_employee_field_without_saving_a_schedule,
 * so it isn't duplicated here.
 */
class ScheduleAssignmentWriteBoundaryTest extends TestCase
{
    /**
     * Every file allowed to write a ScheduleAssignment row, and why. A new
     * write path anywhere else fails this test — add it here only alongside
     * wiring it through RosterWriteContext::allow()/allowUnattended().
     */
    private const ALLOWED_FILES = [
        'app/Services/ScheduleService.php' => 'manual create/update, recurring schedule create',
        'app/Services/Scheduling/RosterDraftService.php' => 'roster publish',
        'app/Services/ShiftSwapService.php' => 'shift swap approval',
        'app/Services/Scheduling/RoomAssignmentService.php' => 'room placement -- writes room_id on an existing row, never creates or deletes one',
        'app/Services/Scheduling/RoomBookingService.php' => 'theatre list -- writes room_booking_id on an existing row, never creates or deletes one',
        'app/Http/Controllers/Schedule/ScheduleAssignmentController.php' => 'manual delete (web)',
        'app/Http/Controllers/Api/V1/ScheduleController.php' => 'manual delete (API)',
        'app/Http/Controllers/Schedule/RecurringScheduleController.php' => 'recurring series cancellation (per-row delete, so each is audited)',
        'database/seeders/ShiftScheduleSeeder.php' => 'demo data, allowUnattended() only',
    ];

    public function test_only_known_sites_write_schedule_assignment_rows(): void
    {
        $root = base_path();
        $parser = (new ParserFactory)->createForHostVersion();
        $violations = [];

        foreach ($this->phpFiles($root) as $absolutePath) {
            $relativePath = str_replace('\\', '/', ltrim(str_replace($root, '', $absolutePath), '/\\'));

            $ast = $parser->parse(file_get_contents($absolutePath));
            if ($ast === null) {
                continue;
            }

            $visitor = new ScheduleAssignmentWriteVisitor;
            $traverser = new NodeTraverser;
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            if ($visitor->flaggedLines === []) {
                continue;
            }

            if (! array_key_exists($relativePath, self::ALLOWED_FILES)) {
                foreach ($visitor->flaggedLines as $line) {
                    $violations[] = "{$relativePath}:{$line}";
                }
            }
        }

        $this->assertSame([], $violations, "Unexpected ScheduleAssignment write(s) outside the allow-listed call sites:\n".implode("\n", $violations));
    }

    public function test_every_allow_listed_file_actually_contains_a_flagged_write(): void
    {
        // Guards the allow-list itself against going stale — if a refactor
        // removes the write from one of these files, this test says so instead
        // of the allow-list silently over-permitting forever.
        $root = base_path();
        $parser = (new ParserFactory)->createForHostVersion();

        foreach (array_keys(self::ALLOWED_FILES) as $relativePath) {
            $absolutePath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $this->assertFileExists($absolutePath);

            $ast = $parser->parse(file_get_contents($absolutePath));
            $visitor = new ScheduleAssignmentWriteVisitor;
            $traverser = new NodeTraverser;
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $this->assertNotEmpty($visitor->flaggedLines, "{$relativePath} is allow-listed but no ScheduleAssignment write was detected in it anymore.");
        }
    }

    /** @return array<int, string> */
    private function phpFiles(string $root): array
    {
        $files = [];
        foreach (['app', 'database/seeders'] as $dir) {
            $finder = (new Finder)->files()->in($root.'/'.$dir)->name('*.php');
            foreach ($finder as $file) {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }
}
