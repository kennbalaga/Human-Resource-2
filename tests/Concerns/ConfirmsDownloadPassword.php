<?php

namespace Tests\Concerns;

use App\Http\Middleware\ConfirmPasswordForDownload;

/**
 * For tests about what a download contains or who may take it: the password
 * re-entry in front of every download is covered on its own in
 * DownloadSecurityTest, so these switch it off rather than satisfy it.
 *
 * Switching the middleware off rather than seeding a confirmed timestamp in the
 * session, because several of these tests pin or travel the clock -- a
 * timestamp written during setUp expires the moment a test moves time further
 * than the timeout, which made passing depend on the hour the suite ran.
 *
 * Picked up by name through Laravel's setUpTraits().
 */
trait ConfirmsDownloadPassword
{
    protected function setUpConfirmsDownloadPassword(): void
    {
        $this->withoutMiddleware(ConfirmPasswordForDownload::class);
    }
}
