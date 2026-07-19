<?php

declare(strict_types=1);

/*
 * OpenBanking module routes (19-05 Wave 1).
 *
 * The consent/SCA loopback dance:
 *  - GET /oauth/connect/open-banking  — kicks off the Enable Banking
 *    `/auth` call and redirects the browser to the bank's hosted
 *    consent page.
 *  - GET /oauth/callback/open-banking — the loopback redirect target
 *    the bank/aggregator sends the user back to with `?state=...&
 *    code=...` after SCA completes.
 *
 * Unlike EmailScan's `{provider}` wildcard (gmail|microsoft), Open
 * Banking has exactly one provider (Enable Banking), so the path
 * segment is a literal 'open-banking', not a route parameter.
 *
 * Both routes carry the bare 'web' middleware so the session-cookie +
 * state-CSRF round-trip work; 'auth' binds the callback to the same
 * authenticated user that initiated the connect step.
 *
 * Gate 0/A2 (19-05): Enable Banking's Control Panel requires an
 * HTTPS-only registered redirect URI (no bare-HTTP loopback exception,
 * unlike Google/Microsoft) — the redirect URI both controllers compute
 * via `LoopbackRedirectUri::forProvider('open-banking', scheme: 'https')`
 * therefore resolves to `https://127.0.0.1:PORT/...`. Actually
 * terminating TLS on that loopback listener (a self-signed local
 * certificate) is tracked as deferred follow-up infrastructure work —
 * see 19-05-SUMMARY.md.
 *
 * Route::facade is permitted in module Routes files (BoundaryArchTest
 * exempts Modules\*\Routes).
 */

use Illuminate\Support\Facades\Route;
use Modules\OpenBanking\Internal\Http\Controllers\OpenBankingCallbackController;
use Modules\OpenBanking\Internal\Http\Controllers\OpenBankingConnectController;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/oauth/connect/open-banking', OpenBankingConnectController::class)
        ->name('oauth.open-banking.connect');
    Route::get('/oauth/callback/open-banking', OpenBankingCallbackController::class)
        ->name('oauth.open-banking.callback');
});
