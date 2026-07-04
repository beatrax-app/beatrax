<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/transactions', 'ledger::transactions')->name('transactions.index');
    Route::get('/transactions/{transactionId}', TransactionDetail::class)
        ->whereNumber('transactionId')
        ->name('transactions.show');
    Route::get('/reconcile', ReconcilePage::class)->name('reconcile.index');
});
