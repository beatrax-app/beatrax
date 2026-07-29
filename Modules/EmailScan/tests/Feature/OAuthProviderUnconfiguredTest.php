<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Exceptions\InboxNotConfiguredException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The secrets repository is user-scoped, so the lookup needs a guard to
    // read for before it can report the client as missing.
    $this->actingAs(User::query()->create([
        'username' => 'oauth-unconfigured',
        'password' => bcrypt('oauth-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]));
});

/*
 * Both OAuth providers need a client the operator registered through the
 * wizard before they can talk to anyone. Without one they refuse with a
 * message naming the wizard, rather than constructing a provider around nulls
 * and failing somewhere inside the league library where the cause is no longer
 * obvious.
 *
 * Nothing configures a client here, which is the whole setup: a fresh install
 * that has not been through the wizard is exactly this state.
 */
it('refuses to refresh a token before the OAuth client is configured', function (string $class): void {
    $provider = $this->app->make($class);

    expect(fn () => $provider->refreshAccessToken('any-refresh-token'))
        ->toThrow(InboxNotConfiguredException::class, 'is not configured');
})->with([GoogleOAuthProvider::class, MicrosoftOAuthProvider::class]);

it('refuses to read the account email before the OAuth client is configured', function (string $class): void {
    $provider = $this->app->make($class);

    expect(fn () => $provider->readEmail('any-access-token'))
        ->toThrow(InboxNotConfiguredException::class, 'is not configured');
})->with([GoogleOAuthProvider::class, MicrosoftOAuthProvider::class]);
