<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Models\Transaction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The `/transactions/{transactionId}` detail page.
 *
 * Renders a calm two-column `<dl>` of the row's headline metadata
 * (date, counterparty, native amount, settled-EUR amount) plus a
 * conditional "Effective rate" row that appears only when the
 * transaction carries a non-null `fx_rate_used` value.
 *
 * Adds the Wave-3 Reclassify control: a single-click type override
 * that atomically breaks the `pair_transaction_id` relationship on
 * both sides when the new type is non-transfer (D-78). Transfer-to-
 * transfer reclassifies preserve the pair — that path remains a
 * one-sided type swap.
 *
 * Multi-user readiness: every Eloquent query carries an explicit
 * `where('user_id', $currentUser->user()->id)` predicate. A request
 * for a transaction owned by a different user resolves to 404 in
 * `mount()` before any data is exposed to the view; the reclassify
 * action enforces the same scoping via `firstOrFail()` on a query
 * filtered by `user_id`.
 *
 * DI-only: this Livewire component has no constructor. Service
 * collaborators arrive as parameters on `mount()`, `render()`, and
 * action methods — the strict-rules ruleset forbids property-based
 * constructor injection on Component subclasses, and `auth()` /
 * `Auth::user()` / facade lookups are out of bounds project-wide.
 *
 * Page-shell wiring: `render()` calls `$view->extends('layouts.app', ...)`
 * so this component can be wired directly as a `Route::get(...,
 * TransactionDetail::class)` page handler without a separate Blade
 * wrapper. The macro is registered by Livewire's SupportPageComponents
 * feature and produces a `@extends('layouts.app') @section('content')`
 * envelope identical to every other diederik page.
 */
final class TransactionDetail extends Component
{
    public int $transactionId = 0;

    /**
     * The pending dropdown selection driven by `wire:model.live` on the
     * Reclassify select. Reset to `''` after every successful reclassify
     * so the dropdown returns to "Choose a type…" and the just-applied
     * value is hidden from the option list (the Blade filters out the
     * transaction's current type).
     */
    public string $reclassifyType = '';

    public function mount(int $transactionId, CurrentUser $currentUser, DatabaseManager $db): void
    {
        $this->transactionId = $transactionId;

        // The raw Query Builder `exists()` call is used here instead of
        // Eloquent's `Transaction::query()->exists()` to clear PHPStan
        // strict-rules `staticMethod.dynamicCall` — Eloquent's exists()
        // is a magic forward over Builder's instance method, which the
        // strict ruleset rejects on a freshly resolved query. Same
        // pattern as UpdateTransactionCategory's category-visibility
        // pre-check.
        $exists = $db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $currentUser->user()->id)
            ->exists();

        if (! $exists) {
            throw new NotFoundHttpException(sprintf(
                'Transaction %d not found.',
                $transactionId,
            ));
        }
    }

    /**
     * Manually override the transaction's `type`. The user-facing entry
     * point for Wave 3's reclassify action (D-78).
     *
     * Allow-listed via `Transaction::TYPES` — any other value raises
     * `InvalidArgumentException` before any DB read. Same-user scoping
     * is enforced by `firstOrFail()` on a query filtered by `user_id`;
     * a cross-user invocation raises `NotFoundHttpException` (404).
     *
     * When the new type is not `transfer_out` / `transfer_in` and the
     * row currently carries a `pair_transaction_id`, the pair is
     * broken atomically: both the row and its partner have
     * `pair_transaction_id` set to `NULL` inside the same DB
     * transaction. Transfer-to-transfer reclassifies preserve the
     * pair (re-pairing is the listener's job at import time).
     */
    public function reclassify(
        string $newType,
        CurrentUser $currentUser,
        DatabaseManager $db,
    ): void {
        if (! in_array($newType, Transaction::TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                "Invalid transaction type: '%s'",
                $newType,
            ));
        }

        $user = $currentUser->user();

        /** @var Transaction $tx */
        $tx = Transaction::query()
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $partnerId = $tx->pair_transaction_id;
        $breaksPair = $partnerId !== null
            && ! in_array($newType, ['transfer_out', 'transfer_in'], true);

        $db->connection()->transaction(static function () use ($tx, $newType, $partnerId, $user, $breaksPair): void {
            $tx->type = $newType;
            if (! in_array($newType, ['transfer_out', 'transfer_in'], true)) {
                $tx->pair_transaction_id = null;
            }
            $tx->save();

            if ($breaksPair) {
                // Symmetric break — partner's pair_transaction_id is
                // cleared atomically in the same transaction. The
                // partner's own `type` is preserved; reclassify never
                // re-types the partner.
                Transaction::query()
                    ->where('user_id', $user->id)
                    ->where('id', $partnerId)
                    ->update(['pair_transaction_id' => null]);
            }
        });

        $message = $breaksPair
            ? sprintf('Reclassified to %s — pair removed', $newType)
            : sprintf('Reclassified to %s', $newType);

        $this->dispatch('toast', message: $message);

        $this->reclassifyType = '';
    }

    public function render(CurrentUser $currentUser, ViewFactory $views): View
    {
        $transaction = Transaction::query()
            ->where('id', $this->transactionId)
            ->where('user_id', $currentUser->user()->id)
            ->firstOrFail();

        $view = $views->make('ledger::livewire.transaction-detail', [
            'transaction' => $transaction,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Transaction · diederik']);

        return $view;
    }
}
