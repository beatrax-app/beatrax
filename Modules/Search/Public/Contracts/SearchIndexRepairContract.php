<?php

declare(strict_types=1);

namespace Modules\Search\Public\Contracts;

// Public so Core's sealed-ledger recovery can drain the index gaps a keyless
// process left without reaching into Search Internal. It is the same seam as
// the op-log re-projection beside it: a web request is the only place on a
// desktop that holds the app-lock key, so it does the work nothing else can.
interface SearchIndexRepairContract
{
    // The fingerprint is the caller's own keyring hash, and it is what bounds
    // the retry: a column sealed to an epoch whose wrap never arrived cannot be
    // rebuilt under this keyring however often it is tried, so a pass that
    // failed under one is not asked again until key material moves.
    public function hasWork(int $userId, ?string $keyringFingerprint): bool;

    // Rebuilds a bounded batch of the index docs a keyless process refused to
    // write. Returns how many were rebuilt. The caller MUST hold key material
    // — a keyless run refuses each row again and rebuilds nothing.
    public function repair(int $userId, ?string $keyringFingerprint): int;

    // Records that this row's index doc is owed, so the drain above collects
    // it. A replay whose index write threw used to end at a log line naming
    // `search:reindex`, which is a console command — and neither a phone nor a
    // desktop window has a console to run it in.

    // Idempotent by coordinate: the queue holds one row per transaction, so a
    // failure reported twice is one debt.
    public function owe(int $userId, int $transactionId): void;
}
