<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Services\AppLockKeyService;

/**
 * Locks the session immediately when the native window is hidden or closed (D-06).
 *
 * Subscribes to both NativePHP's WindowHidden and WindowClosed events and calls
 * AppLockKeyService->withhold() with no grace period — the app-switcher OS
 * snapshot must not show financial data.
 *
 * Security contract (T-05-24): the window hide/close path locks BEFORE the OS
 * composites the next app-switcher frame. No grace delay is permitted.
 *
 * ⚠ OPEN RISK — session context inside the bundle (WR-09, DEFERRED): NativePHP
 * dispatches WindowHidden/WindowClosed into Laravel from the Electron main
 * process over its own HTTP channel. If that request does not carry the user
 * window's session cookie, the request-scoped Session injected here is a
 * DIFFERENT (anonymous) session and withhold() writes the lock flag where the
 * UI never reads it — the D-06 guarantee would silently not hold. This cannot
 * be verified outside the desktop bundle (tests share one session, so they
 * pass either way). Required follow-up before relying on D-06 in production:
 * log/compare session IDs in both contexts inside the bundle; if they differ,
 * lock via a session-independent per-user signal (e.g. a locked_at marker in
 * user_app_lock_configs that AppLockMiddleware consults). The client-side
 * privacy veil (D-07) and the grace-window server lock (lock.js) still cover
 * the backgrounding case meanwhile.
 *
 * Module-boundary rule (T-05-28): this listener imports ONLY the Public contract
 * (Modules\Auth\Public\Services\AppLockKeyService). It must NEVER import any
 * Modules\Auth\Internal class — the Auth Internal namespace is off-limits to
 * all other modules.
 *
 * The listener accepts `object $event` so both WindowHidden and WindowClosed
 * can route through the same handle() method without importing either NativePHP
 * event class into the listener body — keeping the NativePHP dependency contained
 * to the registration site in DesktopServiceProvider.
 *
 * NativePHP carve-out: this listener does NOT call any NativePHP facade, so it
 * does not need a phpstan.neon allowlist entry. The registration in
 * DesktopServiceProvider imports the NativePHP event classes; this file does not.
 */
final class LockOnWindowHideOrClose
{
    public function __construct(
        private readonly AppLockKeyService $keyService,
        private readonly Session $session,
    ) {}

    /**
     * Lock the session immediately. No grace period (D-06).
     *
     * Accepts both WindowHidden and WindowClosed events via the object type hint.
     * The event payload is not inspected — any window-hide/close trigger locks
     * the session unconditionally.
     */
    public function handle(object $event): void
    {
        $this->keyService->withhold($this->session);
    }
}
