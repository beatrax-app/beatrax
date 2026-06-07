<?php

declare(strict_types=1);

/*
 * CashBook module route — `/cash`, the manual / cash-entry surface.
 * Behind `auth` + `web`; cross-user isolation is enforced inside the
 * page component + the recording action (every read/write scopes by user).
 */

use Illuminate\Support\Facades\Route;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/cash', CashBookPage::class)->name('cashbook.index');
});
