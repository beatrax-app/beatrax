<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Http\Livewire\Concerns;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Events\TransactionMutated;

/**
 * Reusable Livewire trait for the cleared/uncleared/reconciled badge +
 * toggle (SC-1, D-11). Mirrors `Modules\Tax\Public\Http\Livewire\Concerns\
 * HandlesTaxTagging` exactly: a batch-load helper for per-row rendering
 * plus an `#[On(...)]`-wired toggle action so the badge works uniformly
 * from any Livewire component that mixes this trait in (`TransactionsList`,
 * `TransactionDetail`), regardless of nesting depth.
 *
 * All collaborators arrive as method parameters (no constructor DI —
 * Livewire strict-rules prohibition on Component subclasses).
 *
 * Security:
 *  - clearedStatusFor() scopes its single query by `user_id` before
 *    `whereIn('id', ...)` — cannot leak another user's status (T-13.3-07).
 *  - toggleClearedStatus() reads AND writes scoped by `id` + `user_id`
 *    (I2 guard, T-13.3-06 IDOR mitigation). A foreign/unknown
 *    transactionId resolves to a silent no-op — the scoped read returns
 *    null and the method returns before any write.
 *  - The next status value is computed server-side (cleared<->uncleared)
 *    and validated against `Transaction::STATUSES` before the write —
 *    the client never supplies the target string (T-13.3-08).
 *
 * Pitfall prevention:
 *  - N+1 guard: clearedStatusFor() issues ONE `whereIn` query for the
 *    full batch of ids passed in — never per-row (mirrors taxTagStateFor).
 *  - D-08 warn-first: toggleClearedStatus() short-circuits on a
 *    `reconciled` current status with a toast and no write, before any
 *    read of the "next" value.
 */
trait HandlesClearedStatus
{
    /**
     * Batch-load the cleared/uncleared/reconciled status for an array of
     * transaction ids.
     *
     * Issues ONE `whereIn` query scoped by `user_id` (no N+1). Any id
     * missing from the result set (row not found, or — defensively —
     * scoped away) defaults to `'cleared'`, the column's DB default: a
     * missing row must render as the safe default, never as falsy/
     * uncleared.
     *
     * @param  array<int>  $transactionIds
     * @return array<int, string>
     */
    public function clearedStatusFor(
        array $transactionIds,
        DatabaseManager $db,
        CurrentUser $u,
    ): array {
        if ($transactionIds === []) {
            return [];
        }

        $statuses = $db->connection()
            ->table('transactions')
            ->where('user_id', $u->user()->id)
            ->whereIn('id', $transactionIds)
            ->pluck('status', 'id');

        $result = [];
        foreach ($transactionIds as $id) {
            $status = $statuses[$id] ?? null;
            $result[$id] = is_string($status) ? $status : 'cleared';
        }

        return $result;
    }

    /**
     * Event-wired toggle entry point — dispatched by `x-ledger::cleared-
     * badge` via `wire:click="$dispatch('cleared-toggle', { id: ... })"`.
     * Lets the badge fire the same toggle from any component that mixes
     * this trait in (list rows, the detail page) without a direct method
     * call keyed to a specific component's own transactionId property.
     */
    #[On('cleared-toggle')]
    public function toggleClearedRow(
        int $id,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        Clock $clock,
    ): void {
        $this->toggleClearedStatus($id, $currentUser, $db, $events, $clock);
    }

    /**
     * Warn-first, sync-safe cleared<->uncleared toggle (D-08/D-10/D-11).
     *
     * Mirrors `TransactionDetail::saveNote()`'s raw-update + dispatch-
     * after-commit shape: read current status scoped by id+user_id, flip
     * cleared<->uncleared, validate the next value against
     * `Transaction::STATUSES`, write scoped by id+user_id, then dispatch
     * `TransactionMutated('edit', dirtyFields: ['status' => $next])`
     * AFTER the write (WR-06).
     *
     * A `reconciled` current status short-circuits with a toast and no
     * write (D-08 — editing a reconciled row requires an explicit
     * un-reconcile first). A cross-user or unknown transactionId resolves
     * to a silent no-op (I2 guard) — the scoped read returns null.
     */
    public function toggleClearedStatus(
        int $transactionId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        Clock $clock,
    ): void {
        $userId = $currentUser->user()->id;

        $current = $db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('status');

        if ($current === null) {
            // Cross-user or unknown id — silent no-op (I2 guard).
            return;
        }

        if ($current === 'reconciled') {
            $this->dispatch('toast', message: 'Reconciled — un-reconcile first to change status.');

            return;
        }

        $next = $current === 'cleared' ? 'uncleared' : 'cleared';

        if (! in_array($next, Transaction::STATUSES, true)) {
            return;
        }

        $db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)   // I2 guard
            ->update([
                'status' => $next,
                // IN-02: raw QB updates don't auto-touch timestamps; bump
                // updated_at from the same injected Clock ReconciliationWriter
                // uses so any updated_at-based ordering/cache sees a fresh row.
                'updated_at' => $clock->now(),
            ]);

        $events->dispatch(new TransactionMutated(
            transactionId: $transactionId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: ['status' => $next],
        ));
    }
}
