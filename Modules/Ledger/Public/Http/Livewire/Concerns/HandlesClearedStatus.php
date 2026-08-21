<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Http\Livewire\Concerns;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Sync\Public\Events\TransactionMutated;

trait HandlesClearedStatus
{
    use DispatchesToast;

    // An id missing from the result set defaults to the column's DB default,
    // 'cleared' — never to a falsy/uncleared render.
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

    // x-ledger::cleared-badge dispatches this, so the badge works in any
    // component mixing the trait in, whatever it calls its own row id.
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
            $this->toast(Lang::get('ledger::common.badge.reconciled_hint'));

            return;
        }

        // Both branches are ClearedStatus cases, so the value is a member of
        // Transaction::STATUSES by construction — hence no membership guard.
        $next = $current === ClearedStatus::Cleared->value ? ClearedStatus::Uncleared->value : ClearedStatus::Cleared->value;

        $db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->update([
                'status' => $next,
                // Raw QB updates don't auto-touch timestamps; this is the
                // same injected Clock ReconciliationWriter bumps from.
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
