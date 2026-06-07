<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/budgets', BudgetsPage::class)->name('budgets.index');
});
