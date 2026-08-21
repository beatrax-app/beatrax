<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Modules\Sync\Internal\OpLog\OpLogRebuilder;

final class HistoryReprojector
{
    public function __construct(
        private readonly OpLogRebuilder $rebuilder,
    ) {}

    // Re-projects every persisted op-log entry for $userId against the
    // CURRENT (now possibly newly-populated) GDK keyring. Idempotent — safe
    // to call more than once, though callers should still gate repeated
    // calls behind their own cursor to avoid unneeded full-history replay cost.
    /**
     * @throws \Throwable re-thrown from `OpLogRebuilder::rebuild()` on a
     *                    transaction failure (rolled back — never a
     *                    partial rebuild).
     */
    public function reproject(int $userId): void
    {
        $this->rebuilder->rebuild($userId);
    }
}
