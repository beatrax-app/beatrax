<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Http\Livewire\Concerns;

use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Ledger\Public\Services\TransactionStatusWriter;

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
        int|string $id,
        CurrentUser $currentUser,
        DatabaseManager $db,
        TransactionStatusWriter $writer,
    ): void {
        $this->toggleClearedStatus(DerivedRowId::fromWire($id), $currentUser, $db, $writer);
    }

    public function toggleClearedStatus(
        int $transactionId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        TransactionStatusWriter $writer,
    ): void {
        $current = $db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $currentUser->user()->id)
            ->value('status');

        if ($current === null) {
            return;
        }

        // Read here as well as inside the writer, which refuses a locked row of
        // its own accord: the toast is what turns that refusal into something
        // the reader can act on, and only this side can raise one.
        if (TransactionStatusQuery::locksEdits($current)) {
            $this->toast(Lang::get('ledger::common.badge.reconciled_hint'));

            return;
        }

        $writer->toggleCleared($currentUser->user(), $transactionId);
    }
}
