<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/transactions', 'ledger::transactions')->name('transactions.index');
});
