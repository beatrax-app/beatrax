<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Enums\OAuthAlertKind;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\EmailScan\Models\OAuthSecret;

function scrubSetFailureUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

// Renaming the table away is the load failure the module actually fears: a
// missing table during boot, and the identical throw once the app is serving.
function withOAuthSecretsTableHidden(callable $body): void
{
    Schema::rename('oauth_secrets', 'oauth_secrets_hidden');
    try {
        $body();
    } finally {
        Schema::rename('oauth_secrets_hidden', 'oauth_secrets');
    }
}

it('raises the redaction-is-offline alert on a load that fails after boot, even though the first load failed too', function (): void {
    $user = scrubSetFailureUser('scrubset-alert');
    $this->actingAs($user);

    /** @var SecretShield $shield */
    $shield = app(SecretShield::class);
    $scrubSet = new OAuthScrubSet($shield);

    withOAuthSecretsTableHidden(function () use ($scrubSet): void {
        expect($scrubSet->all())->toBe([]);
        expect(DB::table('system_alerts')->count())->toBe(0);

        expect($scrubSet->all())->toBe([]);
        expect($scrubSet->all())->toBe([]);
    });

    $alerts = DB::table('system_alerts')
        ->where('kind', OAuthAlertKind::ScrubSetFailed->value)
        ->get();

    // One alert, not one per call: compiledPattern() runs on every log record.
    expect($alerts)->toHaveCount(1);
});

it('scrubs again once the cause clears, rather than caching the empty set a failed load returned', function (): void {
    $user = scrubSetFailureUser('scrubset-retry');
    $this->actingAs($user);

    OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'cid',
        'client_secret' => 'SECRET_AFTER_RECOVERY',
        'redirect_uri' => 'https://example.test/cb',
        'tokens_blob' => null,
    ]);

    /** @var SecretShield $shield */
    $shield = app(SecretShield::class);
    $scrubSet = new OAuthScrubSet($shield);

    withOAuthSecretsTableHidden(function () use ($scrubSet): void {
        expect($scrubSet->all())->toBe([]);
        expect($scrubSet->compiledPattern())->toBeNull();
    });

    expect($scrubSet->all())->toContain('SECRET_AFTER_RECOVERY');
    expect($scrubSet->compiledPattern())->not->toBeNull();
});
