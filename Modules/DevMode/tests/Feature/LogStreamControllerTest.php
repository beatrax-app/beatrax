<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\EmailScan\Models\OAuthSecret;

/*
 * LogStreamController invariants.
 *
 * The poll endpoint replaces the earlier SSE handler so the request
 * returns immediately and PHP's single-threaded built-in dev server
 * (which NativePHP uses) does not stall every other in-app request
 * while a tail is active. We cover:
 *   - Both routes are gated by EnsureDeveloperMode (404 for non-devs).
 *   - The poll endpoint returns JSON with chunk + newOffset + inode,
 *     applies redaction, and signals reset on a stale `since` offset.
 *   - The context endpoint validates inputs and clamps radius.
 *   - The context endpoint re-applies the on-stream redaction.
 *   - The context endpoint never reads outside the daily log file
 *     path (path is computed via UserDataPathService).
 */

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
    $path = UserDataPathService::dailyLogFile();
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $contents);

    return $path;
}

it('returns 404 from /dev/logs/poll for a non-developer (EnsureDeveloperMode gate)', function (): void {
    logStreamUser('logs-poll-seed', true);
    $user = logStreamUser('logs-poll-nondev', false);

    $response = $this->actingAs($user)->getJson('/dev/logs/poll?since=0');

    $response->assertNotFound();
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

    // Validator rejects radius > 50 as 422 (validation failure), proving
    // the clamp lives at the parse layer not deep inside the handler.
    $response->assertUnprocessable();
});

it('GET /dev/logs/context returns empty lines when the daily file does not exist', function (): void {
    $user = logStreamUser('logs-ctx-nofile');

    // Delete any pre-existing daily log file to simulate a fresh boot
    // before the first Monolog write of the day.
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
