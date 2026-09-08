<?php

namespace Tests\Feature\Profile;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Every notification the app shows has to leave by email as well.
 *
 * An employee who is not signed in never sees the bell, so a notification
 * that only ever lands in the notifications table reaches nobody until they
 * happen to open HRMS — which for a day off, a shift change or a security
 * event is exactly too late.
 *
 * Two services hold that guarantee: PreferenceNotificationService writes the
 * in-app record and sends the preference-governed mail, and
 * SecurityAlertService does the same for security events that ignore the
 * preferences. Both are covered by their own tests. This one asserts against
 * the source that nothing else writes a notification directly, because the
 * failure it guards against is silent: a new controller calling
 * `$user->notifications()->create()` looks completely correct, works in the
 * UI, and quietly emails no one.
 */
class EveryNotificationIsEmailedTest extends TestCase
{
    /**
     * The services allowed to write an in-app notification, each because it
     * sends the matching email itself.
     *
     * @var array<int, string>
     */
    private const SANCTIONED = [
        'app/Services/PreferenceNotificationService.php',
        'app/Services/Security/SecurityAlertService.php',
    ];

    public function test_only_the_services_that_also_send_mail_write_notifications(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

            if (in_array(str_replace(DIRECTORY_SEPARATOR, '/', $relative), self::SANCTIONED, true)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (preg_match('/notifications\(\)\s*->\s*create\(/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These write an in-app notification directly, so no email goes with it:',
            ...$offenders,
            'Send it through PreferenceNotificationService (preference-governed) or',
            'SecurityAlertService::alertUser() (always sent) instead.',
        ]));
    }

    /** @return array<int, SplFileInfo> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
