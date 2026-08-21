<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\EmailScan\Models\OAuthSecret;

function logStreamUser(string $username, bool $isDeveloper = true): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

function ensureDailyLogFile(string $contents): string
{
    // Without the NATIVEPHP_STORAGE_PATH override, dailyLogFile() falls back
    // to the project's storage/ and the test clobbers the developer's own
    // local log. afterEach drops the override again.
    $sandboxRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-storage-'.bin2hex(random_bytes(6));
    putenv('NATIVEPHP_STORAGE_PATH='.$sandboxRoot);

    $path = UserDataPathService::dailyLogFile();
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $contents);

    return $path;
}

afterEach(function (): void {
    // The sandbox dir is deliberately left behind for the OS to reap: a
    // recursive delete here would be one more chance to wipe the wrong path
    // if the env was already empty.
    putenv('NATIVEPHP_STORAGE_PATH');
});

it('returns 404 from /dev/logs/poll for a non-developer (EnsureDeveloperMode gate)', function (): void {
    logStreamUser('logs-poll-seed', true);
    $user = logStreamUser('logs-poll-nondev', false);

    $response = $this->actingAs($user)->getJson('/dev/logs/poll?since=0');

    $response->assertNotFound();
});

it('returns 404 from /dev/logs/stats for a non-developer (EnsureDeveloperMode gate)', function (): void {
    logStreamUser('logs-stats-seed', true);
    $user = logStreamUser('logs-stats-nondev', false);

    $response = $this->actingAs($user)->getJson('/dev/logs/stats');

    $response->assertNotFound();
});

it('returns per-severity counts + total lines + sizes from /dev/logs/stats for a developer', function (): void {
    $user = logStreamUser('logs-stats-developer');
    $body = implode("\n", [
        '[2026-05-28 10:00:00] local.INFO: alpha',
        '[2026-05-28 10:00:01] local.INFO: beta',
        '[2026-05-28 10:00:02] local.WARNING: gamma',
        '[2026-05-28 10:00:03] local.ERROR: delta',
    ])."\n";
    $path = ensureDailyLogFile($body);

    $response = $this->actingAs($user)->getJson('/dev/logs/stats');

    $response->assertOk();
    $response->assertJsonPath('today.exists', true);
    $response->assertJsonPath('today.sizeBytes', strlen($body));
    $response->assertJsonPath('today.totalLines', 4);
    $response->assertJsonPath('today.parsedLines', 4);
    $response->assertJsonPath('today.perSeverity.INFO', 2);
    $response->assertJsonPath('today.perSeverity.WARNING', 1);
    $response->assertJsonPath('today.perSeverity.ERROR', 1);
    $response->assertJsonPath('today.perSeverity.DEBUG', 0);
    $response->assertJsonPath('allFiles.count', 1);
    $response->assertJsonPath('allFiles.totalBytes', strlen($body));

    expect($path)->toBeFile();
});

it('returns the new chunk + newOffset + inode from /dev/logs/poll for a developer', function (): void {
    $user = logStreamUser('logs-poll-developer');
    $body = "[2026-05-24 10:00:00] local.INFO: line one\n[2026-05-24 10:00:01] local.WARNING: line two\n";
    $path = ensureDailyLogFile($body);

    $response = $this->actingAs($user)->getJson('/dev/logs/poll?since=0');

    $response->assertOk();
    $data = $response->json();
    expect($data['chunk'])->toContain('line one');
    expect($data['chunk'])->toContain('line two');
    expect($data['newOffset'])->toBe(strlen($body));
    expect($data['reset'])->toBeFalse();
    expect($data['inode'])->toBeInt();
});

it('signals reset=true when /dev/logs/poll receives a since offset past the current file size', function (): void {
    $user = logStreamUser('logs-poll-reset');
    $body = "[2026-05-24 10:00:00] local.INFO: short body\n";
    ensureDailyLogFile($body);
    $bigOffset = strlen($body) + 9_999_999;

    $response = $this->actingAs($user)->getJson('/dev/logs/poll?since='.$bigOffset);

    $response->assertOk();
    $data = $response->json();
    expect($data['reset'])->toBeTrue();
    expect($data['chunk'])->toContain('short body');
    expect($data['newOffset'])->toBe(strlen($body));
});

it('returns 404 from /dev/logs/context for a non-developer (EnsureDeveloperMode gate)', function (): void {
    logStreamUser('logs-ctx-seed', true);
    $user = logStreamUser('logs-ctx-nondev', false);

    $response = $this->actingAs($user)->getJson('/dev/logs/context?line=0&radius=10');

    $response->assertNotFound();
});

it('GET /dev/logs/context returns the requested ±radius lines, all redacted', function (): void {
    $user = logStreamUser('logs-ctx-dev');

    ensureDailyLogFile(implode("\n", [
        'line 0: hello',
        'line 1: Authorization: Bearer SUPERSECRETTOKEN',
        'line 2: world',
        'line 3: third',
        'line 4: fourth',
    ]).PHP_EOL);

    $response = $this->actingAs($user)->getJson('/dev/logs/context?line=1&radius=1');

    $response->assertOk();
    $body = $response->json();

    expect($body['line'])->toBe(1);
    expect($body['radius'])->toBe(1);
    expect($body['lines'])->toHaveCount(3); // indices 0..2
    expect($body['lines'][0]['index'])->toBe(0);
    expect($body['lines'][1]['text'])->toContain('Authorization: Bearer [REDACTED]');
    expect($body['lines'][1]['text'])->not->toContain('SUPERSECRETTOKEN');
});

it('GET /dev/logs/context clamps radius at MAX_CONTEXT_RADIUS = 50', function (): void {
    $user = logStreamUser('logs-ctx-clamp');

    ensureDailyLogFile(str_repeat("x\n", 200));

    $response = $this->actingAs($user)->getJson('/dev/logs/context?line=100&radius=500');

    // 422, not a silently clamped 200: the bound lives at the parse layer.
    $response->assertUnprocessable();
});

it('GET /dev/logs/context returns empty lines when the daily file does not exist', function (): void {
    $user = logStreamUser('logs-ctx-nofile');

    // No file at all is the state before the day's first Monolog write.
    $path = UserDataPathService::dailyLogFile();
    if (is_file($path)) {
        @unlink($path);
    }

    $response = $this->actingAs($user)->getJson('/dev/logs/context?line=0&radius=10');

    $response->assertOk();
    expect($response->json('lines'))->toBe([]);
    expect($response->json('total'))->toBe(0);
});

it('GET /dev/logs/context redacts oauth_secret literals in returned lines (full scrub-set)', function (): void {
    $user = logStreamUser('logs-ctx-oauth');
    $this->actingAs($user);

    OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'cid',
        'client_secret' => 'CONTEXT_LEAK_SECRET',
        'redirect_uri' => 'https://example.test/cb',
        'tokens_blob' => null,
    ]);

    ensureDailyLogFile("line 0: CONTEXT_LEAK_SECRET appeared in log\n");

    $response = $this->getJson('/dev/logs/context?line=0&radius=0');

    $response->assertOk();
    expect($response->json('lines.0.text'))->toContain('[REDACTED]');
    expect($response->json('lines.0.text'))->not->toContain('CONTEXT_LEAK_SECRET');
});

it('rejects /dev/logs/context payloads with a non-integer line or radius (validator-bound)', function (): void {
    $user = logStreamUser('logs-ctx-validator');

    $response = $this->actingAs($user)->getJson('/dev/logs/context?line=abc&radius=10');
    $response->assertUnprocessable();

    $response = $this->actingAs($user)->getJson('/dev/logs/context?line=0&radius=abc');
    $response->assertUnprocessable();

    $response = $this->actingAs($user)->getJson('/dev/logs/context?line=-1&radius=10');
    $response->assertUnprocessable();
});
