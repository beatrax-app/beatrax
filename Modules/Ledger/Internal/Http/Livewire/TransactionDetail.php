<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
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
 * Multi-user readiness: every Eloquent query carries an explicit
 * `where('user_id', $currentUser->user()->id)` predicate. A request
 * for a transaction owned by a different user resolves to 404 in
 * `mount()` before any data is exposed to the view.
 *
 * DI-only: this Livewire component has no constructor. Service
 * collaborators arrive as parameters on `mount()` and `render()` —
 * the strict-rules ruleset forbids property-based constructor
 * injection on Component subclasses, and `auth()` / `Auth::user()` /
 * facade lookups are out of bounds project-wide.
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
