<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Http\Livewire\Concerns;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Sync\Public\Events\TransactionMutated;

// All collaborators arrive as method parameters — no constructor DI,
// per the Livewire strict-rules prohibition on Component subclasses.
/**
 * @link ../../../../../../.docs/features/ledger/architecture.md
 */
trait HandlesClearedStatus
{
    // Any id missing from the result set defaults to 'cleared' (the
    // column's DB default) — a missing row must render as the safe
    // default, never as falsy/uncleared.
    /**
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
            $result[$id] = is_string($status) ? $status : ClearedStatus::Cleared->value;
        }

        return $result;
    }

    // Dispatched by x-ledger::cleared-badge via wire:click, letting the
    // badge fire the same toggle from any component that mixes this
    // trait in without a direct method call keyed to a specific
    // component's own transactionId property.
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

    // Mirrors TransactionDetail::saveNote()'s raw-update +
    // dispatch-after-commit shape: read status scoped by id+user_id,
    // flip cleared<->uncleared, validate against Transaction::STATUSES,
    // write scoped by id+user_id, then dispatch after the write.
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
            return;
        }

        if ($current === ClearedStatus::Reconciled->value) {
            $this->dispatch('toast', message: Lang::get('ledger::common.badge.reconciled_hint'));

            return;
        }

        $next = $current === ClearedStatus::Cleared->value ? ClearedStatus::Uncleared->value : ClearedStatus::Cleared->value;

        if (! in_array($next, Transaction::STATUSES, true)) {
            return;
        }

        $db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->update([
                'status' => $next,
                // Raw QB updates don't auto-touch timestamps; bump
                // updated_at from the same injected Clock
                // ReconciliationWriter uses.
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
