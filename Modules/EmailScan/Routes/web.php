<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\EmailScan\Internal\Http\Controllers\OAuthCallbackController;
use Modules\EmailScan\Internal\Http\Controllers\OAuthConnectController;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;

// The OAuth routes carry the 'web' middleware so the session cookie +
// state-CSRF round-trip work; 'auth' ensures the callback is bound to
// the same authenticated user that initiated the connect step.
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/inboxes', InboxesPage::class)->name('inboxes.index');
    Route::get('/oauth/connect/{provider}', OAuthConnectController::class)->name('oauth.connect');
    Route::get('/oauth/callback/{provider}', OAuthCallbackController::class)->name('oauth.callback');
});
