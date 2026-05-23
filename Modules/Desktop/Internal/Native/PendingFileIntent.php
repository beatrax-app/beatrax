<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Contracts\Clock;
use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;

/**
 * Session-scoped store for a pending file-open intent (D-04).
 *
 * The "naive" flow is: receive an OS file-open → emit
 * `FileOpenedFromOs` → route to the staging page → `auth` middleware
 * bounces to `/login` → intent is lost on the redirect.
 *
 * The remediated flow is: receive an OS file-open while no user is
 * authenticated → store the validated path + extension here →
 * authenticate the user → `ContinuePendingFileIntentAfterLogin`
 * subscribes to Laravel's `Login` event, reads the intent, and
 * navigates the focused window to the staging page bound to that file.
 *
 * Security:
 *
 *   - Session-scoped (`SESSION_DRIVER=database`, Phase 12 D-26). The
 *     intent is keyed to the session that received the file-open; a
 *     fresh user logging in on a different session never inherits a
 *     prior user's intent. The cross-user test in `FileOpenedFromOsTest`
 *     covers this case.
 *   - Short-lived. `pending()` re-checks `realpath()` so a stale entry
 *     (the underlying file was unmounted / deleted between
 *     double-click and login) is silently discarded.
 *   - Restricted to the same allow-list as `FileOpenIntake`. The
 *     intent stores `extension` separately so the staging page does
 *     not need to re-derive it; the value is constrained to the same
 *     {csv, eml} set the intake emits.
 *
 * The store holds at most ONE pending intent per session — a second
 * file-open while one is already pending overwrites the first; the
 * one-window UX (D-03) makes a queue unnecessary.
 *
 * Constructor DI: `Session` contract from Laravel, `Clock` for
 * timestamp recording. No facades.
 */
final class PendingFileIntent implements RemembersPendingFileIntent
{
    /** Session key under which the pending intent is stored. */
    public const SESSION_KEY = 'desktop.pending_file_intent';

    /** Extension allow-list — matches FileOpenIntake::SUPPORTED_EXTENSIONS. */
    private const ALLOWED_EXTENSIONS = ['csv', 'eml'];

    public function __construct(
        private readonly Session $session,
        private readonly Clock $clock,
    ) {}

    /**
     * Persist a validated file-open intent under the current session.
     *
     * The caller — `FileOpenIntake` via the `HandleFileOpenedFromOs`
     * listener on the unauthenticated boundary — is responsible for
     * having validated the path. `remember()` applies a final
     * defence-in-depth check on the extension allow-list so a future
     * caller cannot widen the set inadvertently.
     */
    public function remember(string $path, string $extension): void
    {
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return;
        }

        $this->session->put(self::SESSION_KEY, [
            'path' => $path,
            'extension' => $extension,
            'remembered_at' => $this->clock->now()->getTimestamp(),
        ]);
    }

    /**
     * Returns the pending intent (path + extension) when present and
     * the underlying file still resolves; otherwise null. A stale
     * intent is silently dropped on read so the staging page never
     * shows a non-existent file.
     *
     * @return array{path: string, extension: string}|null
     */
    public function pending(): ?array
    {
        $raw = $this->session->get(self::SESSION_KEY);
        if (! is_array($raw)) {
            return null;
        }
        $path = $raw['path'] ?? null;
        $extension = $raw['extension'] ?? null;
        if (! is_string($path) || ! is_string($extension)) {
            $this->clear();

            return null;
        }
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $this->clear();

            return null;
        }
        // Stale-path check: realpath returns false when the file no
        // longer exists. A flash drive that was unmounted between
        // the double-click and the login produces this case.
        $canonical = realpath($path);
        if ($canonical === false || ! is_file($canonical)) {
            $this->clear();

            return null;
        }

        return ['path' => $canonical, 'extension' => $extension];
    }

    /** Removes any pending intent from the session. */
    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }
}
