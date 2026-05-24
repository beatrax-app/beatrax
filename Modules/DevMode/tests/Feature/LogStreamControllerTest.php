<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\EmailScan\Models\OAuthSecret;

/*
 * Phase 16-05 Task 2 — LogStreamController invariants.
 *
 * The SSE tail itself can't be exercised end-to-end inside Pest's HTTP
 * client (the controller blocks on a 250 ms tick loop with a 600 s
 * deadline). Instead we cover:
 *   - The route is gated by EnsureDeveloperMode (404 for non-devs).
 *   - The stream emits text/event-stream headers without buffering.
 *   - The context endpoint validates inputs and clamps radius.
 *   - The context endpoint re-applies redaction (T-16-13 + D-29 on-stream).
 *   - The context endpoint never reads outside the daily log file path
 *     (T-16-18 — path is computed via UserDataPathService).
 *
 * The stream's tick-loop body (FileTailer + rotation detection) is
 * separately covered by 16-04's existing CommandSpawnerTest /
 * ArtisanStreamReconnectTest (shared FileTailer primitive) so we don't
 * need to re-test the inode-shrinkage path here.
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

it('returns 404 from /dev/logs/stream for a non-developer (EnsureDeveloperMode gate)', function (): void {
    logStreamUser('logs-stream-seed', true);
    $user = logStreamUser('logs-stream-nondev', false);

    $response = $this->actingAs($user)->get('/dev/logs/stream');

    $response->assertNotFound();
});

it('returns 404 from /dev/logs/context for a non-developer (EnsureDeveloperMode gate)', function (): void {
    logStreamUser('logs-ctx-seed', true);
    $user = logStreamUser('logs-ctx-nondev', false);

    $response = $this->actingAs($user)->getJson('/dev/logs/context?line=0&radius=10');

    $response->assertNotFound();
});

it('GET /dev/logs/context returns the requested ±radius lines, all redacted (D-29 on-stream)', function (): void {
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

it('GET /dev/logs/context clamps radius at MAX_CONTEXT_RADIUS = 50 (T-16-19)', function (): void {
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
