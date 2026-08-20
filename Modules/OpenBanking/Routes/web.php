<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\OpenBanking\Internal\Http\Controllers\OpenBankingCallbackController;
use Modules\OpenBanking\Internal\Http\Controllers\OpenBankingConnectController;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/oauth/connect/open-banking', OpenBankingConnectController::class)
        ->name('oauth.open-banking.connect');
    Route::get('/oauth/callback/open-banking', OpenBankingCallbackController::class)
        ->name('oauth.open-banking.callback');

    Route::get('/settings/open-banking', OpenBankingSettingsPage::class)
        ->name('settings.open-banking');
});
