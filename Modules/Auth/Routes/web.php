<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(static function (): void {
    // Routes are registered as the authentication, recovery-code,
    // password-reset, and impersonation pages are built out.
});
