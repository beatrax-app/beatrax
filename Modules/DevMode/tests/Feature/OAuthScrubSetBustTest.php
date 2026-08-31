<?php

declare(strict_types=1);

use Illuminate\Log\LogManager;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Logging\PushRedactProcessor;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\EmailScan\Models\OAuthSecret;

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

// A per-call ephemeral channel, so parallel Pest workers do not race on the
// shared laravel-{date}.log.
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

    // LogManager::build() skips tap resolution for on-demand channels, so the
    // tap config/logging.php would have applied is applied by hand here.
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

    OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'cid',
        'client_secret' => 'SECRET_AAA',
        'redirect_uri' => 'https://example.test/cb',
        'tokens_blob' => null,
    ]);

    /** @var OAuthScrubSet $scrubSet */
    $scrubSet = app(OAuthScrubSet::class);
    $secrets = $scrubSet->all();
    expect($secrets)->toContain('SECRET_AAA');

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

    $first = logWithRedactionToTempFile('Used OLD_SECRET');
    expect($first)->toContain('[REDACTED]');
    expect($first)->not->toContain('OLD_SECRET');

    // The first write already compiled a pattern, so this is the bust under
    // test: the rotation has to invalidate it.
    $secret->client_secret = 'NEW_SECRET';
    $secret->save();

    $second = logWithRedactionToTempFile('Used NEW_SECRET');
    expect($second)->toContain('[REDACTED]');
    expect($second)->not->toContain('NEW_SECRET');
});

it('collects the access and refresh tokens out of an OAuthSecret tokens_blob', function (): void {
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

it('leaves the non-secret tokens_blob fields out of the set, so an ordinary log line survives it', function (): void {
    // Every leaf string used to be a needle, so "EmailScan: gmail inbox 7
    // finished" read back as "EmailScan: [REDACTED] inbox 7 finished" — and
    // GMAIL survived on the same line, because the pattern is case-sensitive.
    $user = scrubSetUser('scrubset-nonsecret-fields');
    $this->actingAs($user);

    OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'cid',
        'client_secret' => 'CLIENT_SECRET_Y',
        'redirect_uri' => 'https://example.test/cb',
        'tokens_blob' => json_encode([
            '7' => [
                'id' => 7,
                'provider' => 'gmail',
                'email' => 'alice@example.test',
                'refresh_token' => 'REFRESH_TOKEN_Y',
                'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
                'expires_at' => '2026-08-27T10:00:00+00:00',
            ],
        ]),
    ]);

    /** @var OAuthScrubSet $scrubSet */
    $scrubSet = app(OAuthScrubSet::class);
    $secrets = $scrubSet->all();

    expect($secrets)->toContain('REFRESH_TOKEN_Y');
    expect($secrets)->not->toContain('gmail');
    expect($secrets)->not->toContain('alice@example.test');
    expect($secrets)->not->toContain('https://www.googleapis.com/auth/gmail.readonly');
    expect($secrets)->not->toContain('2026-08-27T10:00:00+00:00');

    $contents = logWithRedactionToTempFile('EmailScan: gmail inbox 7 finished at 2026-08-27T10:00:00+00:00');

    expect($contents)->toContain('EmailScan: gmail inbox 7 finished at 2026-08-27T10:00:00+00:00');
});
