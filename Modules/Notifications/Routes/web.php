<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Internal\Http\Livewire\NotificationsPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/notifications', NotificationsPage::class)->name('notifications.index');
});
