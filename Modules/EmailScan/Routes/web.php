<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\EmailScan\Internal\Http\Controllers\OAuthCallbackController;
use Modules\EmailScan\Internal\Http\Controllers\OAuthConnectController;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;

// The session from 'web' carries the OAuth state nonce across the round-trip;
// 'auth' binds the callback to the user who started the connect.
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/inboxes', InboxesPage::class)->name('inboxes.index');
    Route::get('/oauth/connect/{provider}', OAuthConnectController::class)->name('oauth.connect');
    Route::get('/oauth/callback/{provider}', OAuthCallbackController::class)->name('oauth.callback');
});
