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
        // The whole listener exists to invoke `pending()` for its
        // side-effect: the store's `pending()` accessor runs a
        // realpath() / is_file() recheck on the recorded path and
        // discards a stale entry (e.g. a flash drive that was
        // unmounted between the double-click and the login).
        // Calling it here on Login guarantees that the next
        // /desktop/file-staging navigation either finds a still-valid
        // intent (and renders the PRESENT state bound to it) or
        // finds nothing (and renders the empty state) — never a
        // stale row pointing at a vanished file.
        //
        // The return value is intentionally discarded; the staging
        // page reads the same store on its next render and consumes
        // the intent there. Removing this call would silently
        // re-introduce stale-intent renders.
        $this->intent->pending();
    }
}
