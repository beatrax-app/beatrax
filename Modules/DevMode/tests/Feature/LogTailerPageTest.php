<?php

declare(strict_types=1);

use Modules\Core\Models\User;

/*
 * /dev/logs page rendering invariants.
 *
 * Covers:
 *   - GET /dev/logs renders the page with severity chips + channel
 *     filter + contains filter + pause button + 10k-line scrollback
 *     for a developer.
 *   - GET /dev/logs returns 404 for a non-developer
 *     (EnsureDeveloperMode).
 *   - The dev-shell sidebar's Logs nav entry renders WITHOUT the
 *     nav-disabled class once the dev.logs route is registered.
 *   - The page is wired to the SSE stream URL + the context URL.
 *
 * The actual EventSource consumer + Alpine ring-buffer behaviour
 * is client-side JS the server tests cannot exercise; that is
 * covered manually.
 */

function logTailerUser(string $username, bool $isDeveloper = true): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('returns 200 from GET /dev/logs for an authenticated developer with the page header + filters + scrollback', function (): void {
    $user = logTailerUser('log-tailer-dev');

    $response = $this->actingAs($user)->get('/dev/logs');

    $response->assertOk();
    $response->assertSee('Logs', escape: false);
    $response->assertSee('Live tail of the current day', escape: false);
    // Pause button + scrollback testid markers.
    $response->assertSee('data-testid="log-pause-button"', escape: false);
    $response->assertSee('data-testid="log-scrollback"', escape: false);
    $response->assertSee('data-testid="log-channel-input"', escape: false);
    $response->assertSee('data-testid="log-contains-input"', escape: false);
});

it('renders all 8 Monolog severity chips on /dev/logs', function (): void {
    $user = logTailerUser('log-tailer-severity');

    $response = $this->actingAs($user)->get('/dev/logs');

    $response->assertOk();
    foreach (['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'] as $sev) {
        $response->assertSee('data-severity-chip="'.$sev.'"', escape: false);
    }
});

it('wires the SSE stream URL + the context URL into the page payload', function (): void {
    $user = logTailerUser('log-tailer-wiring');

    $response = $this->actingAs($user)->get('/dev/logs');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('/dev/logs/stream');
    expect($html)->toContain('/dev/logs/context');
});

it('returns 404 from GET /dev/logs for an authenticated non-developer (EnsureDeveloperMode gate)', function (): void {
    logTailerUser('log-tailer-seed', true);
    $user = logTailerUser('log-tailer-nondev', false);

    $response = $this->actingAs($user)->get('/dev/logs');

    $response->assertNotFound();
});

it('renders the Logs sidebar item WITHOUT the nav-disabled class once dev.logs is registered', function (): void {
    $user = logTailerUser('log-tailer-sidebar');

    $response = $this->actingAs($user)->get('/dev/logs');

    $response->assertOk();
    $html = (string) $response->getContent();

    // Locate the Logs sidebar anchor — the dev-shell renders each
    // nav item as `<a href="..." class="side-item{disabled}"> <span
    // class="ic" ...>{icon}</span> Logs</a>`. We match the anchor
    // that closes with the literal "Logs</a>" and capture its class.
    $matches = [];
    $found = preg_match('/<a[^>]*class="([^"]*)"[^>]*>[\s\S]*?Logs\s*<\/a>/', $html, $matches) === 1;
    expect($found)->toBeTrue('Could not locate the Logs sidebar anchor in /dev/logs HTML');
    expect($matches[1])->not->toContain('nav-disabled');
});

it('renders an empty-state cursor for the empty scrollback', function (): void {
    $user = logTailerUser('log-tailer-empty');

    $response = $this->actingAs($user)->get('/dev/logs');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('Waiting for log lines');
    expect($html)->toContain('cursor-blink');
});
