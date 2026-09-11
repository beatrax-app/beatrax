<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Enums\OAuthAlertKind;
use Modules\Core\Public\Services\SystemAlertWriter;
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
    $scrubSet = new OAuthScrubSet($shield, app(SystemAlertWriter::class));

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
    $scrubSet = new OAuthScrubSet($shield, app(SystemAlertWriter::class));

    withOAuthSecretsTableHidden(function () use ($scrubSet): void {
        expect($scrubSet->all())->toBe([]);
        expect($scrubSet->compiledPattern())->toBeNull();
    });

    expect($scrubSet->all())->toContain('SECRET_AFTER_RECOVERY');
    expect($scrubSet->compiledPattern())->not->toBeNull();
});

// A backup that failed its integrity check records an event rather than a
// state, so nothing re-reads it and nothing may take it down. It rides along
// to catch a withdrawal that closed the table instead of the one kind whose
// cause cleared.
function scrubSetLatchingAlert(): int
{
    return (int) DB::table('system_alerts')->insertGetId([
        'user_id' => null,
        'dedup_key' => null,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'The backup written at 2026-09-12 09:00 failed integrity check.',
        'metadata' => null,
        'created_at' => '2026-09-12 09:00:00',
        'acknowledged_at' => null,
    ]);
}

/**
 * A ciphertext no key in this build can open — what a column encrypted under a
 * superseded APP_KEY looks like from here. Written past the model so the
 * `encrypted` cast throws on the way back out, exactly as it does on disk.
 */
function scrubSetKeylessRow(User $user): void
{
    $row = new OAuthSecret;
    $row->user_id = $user->id;
    $row->provider = 'gmail';
    $row->client_id = 'client-id-123';
    $row->client_secret = 'GOCSPX-readable-client-secret';
    $row->redirect_uri = 'http://localhost/callback';
    $row->tokens_blob = null;
    $row->save();

    DB::table('oauth_secrets')->where('id', $row->id)->update([
        'client_secret' => 'this-payload-no-key-in-this-build-can-open',
    ]);
}

function scrubSetOfflineAlertsOpen(): int
{
    return DB::table('system_alerts')
        ->where('kind', OAuthAlertKind::ScrubSetFailed->value)
        ->whereNull('acknowledged_at')
        ->count();
}

it('takes the redaction-is-offline banner down on the next successful load, as its copy says it will', function (): void {
    $user = scrubSetFailureUser('scrubset-withdraw');
    $this->actingAs($user);

    /** @var SecretShield $shield */
    $shield = app(SecretShield::class);
    $scrubSet = new OAuthScrubSet($shield, app(SystemAlertWriter::class));

    withOAuthSecretsTableHidden(function () use ($scrubSet): void {
        // Twice: the first load is the boot one, where an unreachable table is
        // expected and reported by nothing.
        expect($scrubSet->all())->toBe([]);
        expect($scrubSet->all())->toBe([]);
    });

    expect(scrubSetOfflineAlertsOpen())->toBe(1, 'The failed load should have left one open banner.');

    $latchingId = scrubSetLatchingAlert();

    // The next request rebuilds the container, so the recovery has to be read
    // off the database rather than off a flag this instance happens to hold.
    (new OAuthScrubSet($shield, app(SystemAlertWriter::class)))->all();

    expect(scrubSetOfflineAlertsOpen())
        ->toBe(0, 'The load the banner named as its remedy left the banner standing.');

    expect(DB::table('system_alerts')
        ->where('kind', OAuthAlertKind::ScrubSetFailed->value)
        ->whereNotNull('acknowledged_at')
        ->count())
        ->toBe(1, 'The row is resolved, not deleted — redaction really was offline for a while.');

    expect(DB::table('system_alerts')->where('id', $latchingId)->whereNull('acknowledged_at')->exists())
        ->toBeTrue('A corrupt-backup alert must survive an unrelated kind healing.');
});

it('leaves the banner up while a credential still will not decrypt', function (): void {
    $user = scrubSetFailureUser('scrubset-still-keyless');
    $this->actingAs($user);

    scrubSetKeylessRow($user);

    /** @var SecretShield $shield */
    $shield = app(SecretShield::class);

    // The load returns a set and throws nothing, which is exactly the pass a
    // memo-driven withdrawal would read as recovery: the credential it could
    // not open is still there, and its tokens are still reaching the log.
    (new OAuthScrubSet($shield, app(SystemAlertWriter::class)))->all();

    expect(scrubSetOfflineAlertsOpen())->toBe(1, 'The keyless row should have raised one open banner.');

    (new OAuthScrubSet($shield, app(SystemAlertWriter::class)))->all();

    expect(scrubSetOfflineAlertsOpen())
        ->toBe(1, 'A pass that still could not open the credential withdrew the banner anyway.');
});
