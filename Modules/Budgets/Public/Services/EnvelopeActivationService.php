<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Pots\Public\Services\PotWriter;

/**
 * The D-13 hard cutover: makes "cutover" and "envelope activation" the same
 * atomic event. For every user not yet activated, archives every ACTIVE
 * category-linked pot via the existing release-to-unallocated path
 * (`PotWriter::archive`) and stamps `users.envelope_activated_at` — the
 * genesis anchor `CarryoverQuery` folds forward from (Plan 03).
 *
 * NO balance is converted/seeded into any envelope: archiving a pot already
 * releases its balance to unallocated (Pitfall 4 in `PotWriter::archive`), so
 * genesis `carried_in` stays 0 for every envelope everywhere (D-13). There is
 * no "convert pot balance into an assignment" step — that would double-count
 * money that already lives in the account's unallocated pool once the pot is
 * archived.
 *
 * Goal-linked pots (`category_id IS NULL`) are entirely untouched (D-16) —
 * only `status='active' AND category_id IS NOT NULL` pots are ever selected.
 *
 * Idempotency (T-13.2-06-02): per-user, `envelope_activated_at` is claimed via
 * an atomic `whereNull(...)->update(...)` BEFORE the pot walk — the exact
 * claim-then-walk mutex shape `BackfillAnomaliesJob` uses for
 * `anomaly_backfilled_at`. A user whose claim update affects 0 rows was
 * already activated (by this call or an earlier one) and is skipped entirely,
 * so a second `activate()` call never re-archives a pot or re-stamps a user.
 *
 * Cross-user safety (T-13.2-06-01): every pot is archived individually via
 * `PotWriter::archive($user, $potId)` — which re-verifies ownership against
 * the passed `$user` internally — never via a single unscoped
 * `UPDATE pots SET status='archived'` across all users' rows.
 */
final class EnvelopeActivationService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PotWriter $potWriter,
        private readonly Clock $clock,
    ) {}

    /**
     * Run the cutover for every user not yet activated. Safe to call
     * repeatedly (idempotent) — already-activated users are skipped, and
     * within an activation, no pot is archived twice.
     */
    public function activate(): void
    {
        $userIds = $this->db->connection()
            ->table('users')
            ->whereNull('envelope_activated_at')
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        foreach ($userIds as $userId) {
            $this->activateForUser($userId);
        }
    }

    /**
     * Claim + archive + stamp for a single user. A no-op if another caller
     * already claimed (and thus already fully processed) this user.
     */
    private function activateForUser(int $userId): void
    {
        // Atomic claim BEFORE the walk (BackfillAnomaliesJob precedent,
        // T-13.2-06-02): exactly one caller can flip this row from NULL, so a
        // concurrent or repeated activate() call never double-archives.
        $claimed = $this->db->connection()
            ->table('users')
            ->where('id', $userId)
            ->whereNull('envelope_activated_at')
            ->update(['envelope_activated_at' => $this->clock->now()->toDateTimeString()]);

        if ($claimed === 0) {
            return;
        }

        // WR-02: the claim + pot-archive walk is deliberately NOT wrapped in a
        // spanning DB transaction. `PotWriter::archive()` opens its OWN inner
        // transaction and dispatches its events AFTER that inner commit
        // (emit-after-commit contract) — an outer transaction would defer those
        // events until the outer commit and break that contract. Instead we use
        // unclaim-on-failure: if the walk throws part-way, reset this user's
        // claim back to NULL so a subsequent activate() re-runs the FULL cutover
        // rather than skipping a stamped-but-partially-archived user forever.
        // Re-running is safe: PotWriter::archive already skips non-active pots,
        // so pots archived before the failure are never double-archived.
        try {
            /** @var User|null $user */
            $user = User::query()->where('id', $userId)->first();
            if ($user === null) {
                return;
            }

            $potIds = $this->db->connection()
                ->table('pots')
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->whereNotNull('category_id')
                ->pluck('id');

            foreach ($potIds as $potId) {
                $potIdInt = is_numeric($potId) ? (int) $potId : 0;
                if ($potIdInt <= 0) {
                    continue;
                }

                // Per-user, per-pot archive via the existing release-to-
                // unallocated path (T-13.2-06-01) — never a bulk unscoped UPDATE.
                $this->potWriter->archive($user, $potIdInt);
            }
        } catch (\Throwable $e) {
            // Release the claim so the cutover is retryable end-to-end.
            $this->db->connection()
                ->table('users')
                ->where('id', $userId)
                ->update(['envelope_activated_at' => null]);

            throw $e;
        }
    }
}
