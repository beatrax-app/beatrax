<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Modules\Sync\Internal\OpLog\OpLogRebuilder;

/**
 * Narrow Public seam letting a non-Sync module trigger a full op-log
 * re-projection — without reaching into `Modules\Sync\Internal\*` directly
 * (CLAUDE.md modular-architecture constraint; enforced by
 * `App\PhpStan\Rules\BoundaryRule`, which has no per-file ignore
 * mechanism).
 *
 * Added for Phase 15 import-join (Task 4, O1): once a fresh phone's GDK
 * keyring gains the desktop's delivered epochs, every op-log entry that
 * arrived (and was quarantined `gdk_decrypt_failed`) BEFORE the keyring was
 * populated must be replayed again so it projects into readable rows —
 * `OpLogRebuilder::rebuild()` is the existing re-projection primitive
 * (`Modules\Mobile\Internal\Sync\InitialSyncPuller` drives it via this
 * seam). No new trust decision: `rebuild()` itself is unchanged — scoped to
 * one user, replays only the ALREADY-persisted, ALREADY-signature-verified
 * op-log via the SAME production `OpLogReplayer` the incremental path uses.
 */
final class HistoryReprojector
{
    public function __construct(
        private readonly OpLogRebuilder $rebuilder,
    ) {}

    /**
     * Re-project every persisted op-log entry for $userId against the
     * CURRENT (now possibly newly-populated) GDK keyring. Idempotent
     * (upsert-by-identity replay) — safe to call more than once, though
     * callers should still gate repeated calls behind their own cursor
     * (e.g. `InitialSyncPuller`'s `reprojected_at` flag) to avoid unneeded
     * full-history replay cost.
     *
     * @throws \Throwable re-thrown from `OpLogRebuilder::rebuild()` on a
     *                    transaction failure (rolled back — never a
     *                    partial rebuild).
     */
    public function reproject(int $userId): void
    {
        $this->rebuilder->rebuild($userId);
    }
}
