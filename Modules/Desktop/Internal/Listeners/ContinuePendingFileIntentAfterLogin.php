<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Auth\Events\Login;
use Modules\Desktop\Internal\Native\PendingFileIntent;

/**
 * Drives the D-04 pending-intent → staging-page continuation after
 * a logged-out file-open round-trip.
 *
 * Flow (the remediated path Pitfall 3 in RESEARCH.md describes):
 *
 *   1. The OS hands diederik a file while no user is authenticated.
 *   2. `FileOpenIntake` validates the path; the listener bundle
 *      stores it in `PendingFileIntent` (session-scoped).
 *   3. The user navigates to / is bounced to `/login`.
 *   4. After the user successfully authenticates, Laravel fires the
 *      `Illuminate\Auth\Events\Login` event.
 *   5. This listener fires. It reads the pending intent — which
 *      auto-discards if stale (the underlying file was deleted /
 *      unmounted between double-click and login).
 *   6. Nothing else needs to be done at this stage: the next page
 *      navigation that hits `/desktop/file-staging` will render the
 *      PRESENT-state staging page bound to the intent, and the
 *      "Start import" button consumes the intent.
 *
 * Security (RESEARCH.md V3 / V4 / T-15-10):
 *
 *   - The intent is session-scoped. User A logs out (or the session
 *     expires) → the session-scoped store is gone. User B logging
 *     in on a different session sees no intent. The cross-user test
 *     in `FileOpenedFromOsTest` covers this case directly.
 *   - A non-resolvable path (stale intent) is discarded on read by
 *     `PendingFileIntent::pending()` so the staging page never shows
 *     a non-existent file.
 *
 * The listener does NOT perform the actual navigation. Navigation in
 * a Livewire / Blade UI is naturally driven by the next request the
 * browser makes after the login redirect — the user lands on
 * `/dashboard` (the default login redirect), and from there the
 * NativePHP-bundle close-intercept / file-staging seam navigates the
 * focused window via `Window::current()->url(...)` (the JS glue lives
 * in the published Electron main.js / the staging page itself). The
 * session-scoped intent guarantees the staging page renders bound to
 * the file the next time it is requested.
 */
final class ContinuePendingFileIntentAfterLogin
{
    public function __construct(
        private readonly PendingFileIntent $intent,
    ) {}

    public function handle(Login $event): void
    {
        // Reading `pending()` triggers the stale-path realpath check
        // inside the store. A null return means either (a) no intent
        // was set on this session, or (b) the intent's recorded path
        // no longer resolves. Both cases let login proceed normally —
        // the staging page would simply render its empty state.
        $pending = $this->intent->pending();
        if ($pending === null) {
            return;
        }

        // No-op beyond surfacing the intent's presence. The
        // session-scoped store keeps the intent alive across this
        // request boundary so the next /desktop/file-staging request
        // — driven by the focused window navigating there — renders
        // the PRESENT state.
    }
}
