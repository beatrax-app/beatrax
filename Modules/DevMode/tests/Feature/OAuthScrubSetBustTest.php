<?php

declare(strict_types=1);

use Illuminate\Log\LogManager;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Logging\PushRedactProcessor;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\EmailScan\Models\OAuthSecret;

/*
 * OAuthScrubSet end-to-end + cache-bust invariants.
 *
 * Per-test ephemeral log channel built via LogManager::build() so
 * parallel-Pest workers don't race on the shared
 * laravel-{date}.log. The same PushRedactProcessor tap is applied
 * to the ephemeral channel, exercising the same wiring the real
 * `daily` channel resolves in config/logging.php.
 *
 * The compiled-pattern bust observer is registered by
 * DevModeServiceProvider::boot() via OAuthSecret::observe(
 * BustOAuthScrubSetOnSecretChange::class). Every save/delete fires
 * the observer; the next compiledPattern() call rebuilds from the
 * live table.
 */

function scrubSetUser(string $username, bool $isDeveloper = true): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

function logWithRedactionToTempFile(string $message): string
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'scrub-set-').'.log';

    /** @var LogManager $manager */
    $manager = app(LogManager::class);

    $channel = $manager->build([
        'driver' => 'single',
        'path' => $tmpPath,
        'level' => 'debug',
    ]);

    // LogManager::build() bypasses tap resolution for ondemand
    // channels so we apply the SAME PushRedactProcessor that
    // config/logging.php applies for the real channels. The
    // processor resolves via the container so the constructor-DI
    // upgrade to inject OAuthScrubSet propagates into this test.
    (new PushRedactProcessor)($channel);

    $channel->info($message);

    foreach ($channel->getLogger()->getHandlers() as $handler) {
        if (method_exists($handler, 'close')) {
            $handler->close();
        }
    }

    $contents = (string) file_get_contents($tmpPath);
    @unlink($tmpPath);

    return $contents;
}

it('busts the OAuthScrubSet cache when an OAuthSecret is saved', function (): void {
    $user = scrubSetUser('scrubset-save');
    $this->actingAs($user);

    // Seed an oauth_secret with a distinctive client_secret value
    OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'cid',
        'client_secret' => 'SECRET_AAA',
        'redirect_uri' => 'https://example.test/cb',
        'tokens_blob' => null,
    ]);

    // Sanity: the container singleton sees the new row after the
    // observer-driven bust.
    /** @var OAuthScrubSet $scrubSet */
    $scrubSet = app(OAuthScrubSet::class);
    $secrets = $scrubSet->all();
    expect($secrets)->toContain('SECRET_AAA');

    // End-to-end: log the secret literal — the on-write redaction
    // path scrubs it before the bytes hit disk.
    $contents = logWithRedactionToTempFile('Used SECRET_AAA today');

    expect($contents)->toContain('[REDACTED]');
    expect($contents)->not->toContain('SECRET_AAA');
});

it('busts the cache on UPDATE so a rotated secret is scrubbed on the next log line (Test 5)', function (): void {
    $user = scrubSetUser('scrubset-rotate');
    $this->actingAs($user);

    /** @var OAuthSecret $secret */
    $secret = OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'cid',
        'client_secret' => 'OLD_SECRET',
        'redirect_uri' => 'https://example.test/cb',
        'tokens_blob' => null,
    ]);

    /** @var OAuthScrubSet $scrubSet */
    $scrubSet = app(OAuthScrubSet::class);
    expect($scrubSet->all())->toContain('OLD_SECRET');

    // First write — old secret is scrubbed.
    $first = logWithRedactionToTempFile('Used OLD_SECRET');
    expect($first)->toContain('[REDACTED]');
    expect($first)->not->toContain('OLD_SECRET');

    // Rotate the secret. The observer fires; the next log line
    // recomputes the compiled pattern from the live table.
    $secret->client_secret = 'NEW_SECRET';
    $secret->save();

    $second = logWithRedactionToTempFile('Used NEW_SECRET');
    expect($second)->toContain('[REDACTED]');
    expect($second)->not->toContain('NEW_SECRET');
});

it('scrubs every leaf string inside an OAuthSecret tokens_blob (refresh tokens, access tokens, scope)', function (): void {
    $user = scrubSetUser('scrubset-tokensblob');
    $this->actingAs($user);

    OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'cid',
        'client_secret' => 'CLIENT_SECRET_X',
        'redirect_uri' => 'https://example.test/cb',
        'tokens_blob' => json_encode([
            '1' => [
                'id' => 1,
                'provider' => 'gmail',
                'email' => 'alice@example.test',
                'refresh_token' => 'REFRESH_TOKEN_VALUE',
                'scope' => 'gmail.readonly',
                'expires_at' => '2026-12-31T23:59:59+00:00',
                'access_token' => 'ACCESS_TOKEN_VALUE',
            ],
        ]),
    ]);

    /** @var OAuthScrubSet $scrubSet */
    $scrubSet = app(OAuthScrubSet::class);
    $secrets = $scrubSet->all();

    // The client_secret + each non-empty leaf string in tokens_blob
    // is in the set — the email + scope qualify too because the
    // scrub set is intentionally aggressive: every string value in
    // tokens_blob is treated as a potential secret.
    expect($secrets)->toContain('CLIENT_SECRET_X');
    expect($secrets)->toContain('REFRESH_TOKEN_VALUE');
    expect($secrets)->toContain('ACCESS_TOKEN_VALUE');

    $contents = logWithRedactionToTempFile('refresh: REFRESH_TOKEN_VALUE, access: ACCESS_TOKEN_VALUE');
    expect($contents)->not->toContain('REFRESH_TOKEN_VALUE');
    expect($contents)->not->toContain('ACCESS_TOKEN_VALUE');
});

it('runs the OAuth scrub-set BEFORE Bearer + JWT so an OAuth secret that LOOKS like a JWT reads as [REDACTED] not [JWT_REDACTED]', function (): void {
    $user = scrubSetUser('scrubset-jwt-shape');
    $this->actingAs($user);

    $jwtShapedToken = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI5OTk5OTk5OTk5In0BBB.aaaaaaaaaaaaaaaaaaaaaaaaa';

    OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'cid',
        'client_secret' => $jwtShapedToken,
        'redirect_uri' => 'https://example.test/cb',
        'tokens_blob' => null,
    ]);

    $contents = logWithRedactionToTempFile("token: {$jwtShapedToken}");

    expect($contents)->toContain('[REDACTED]');
    expect($contents)->not->toContain('[JWT_REDACTED]');
    expect($contents)->not->toContain($jwtShapedToken);
});
