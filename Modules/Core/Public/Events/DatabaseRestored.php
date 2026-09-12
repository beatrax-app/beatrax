<?php

declare(strict_types=1);

namespace Modules\Core\Public\Events;

// A restore has just replaced every row on this install. Raised after the swap
// and its verification, so a listener reads the restored database rather than
// the one it replaced. It exists for the state a backup cannot carry: rows
// under a key that lives beside the database arrive as bytes nothing opens.
final readonly class DatabaseRestored
{
    public function __construct(public string $preRestoreSnapshotPath) {}
}
