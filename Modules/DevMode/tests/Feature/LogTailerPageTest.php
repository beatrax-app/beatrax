<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Http\Livewire\LogTailerPage;

// The polling consumer and the Alpine ring buffer are client-side, so nothing
// here can reach them — these assertions stop at the rendered markup.
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

it('wires the poll URL + the context URL into the page payload', function (): void {
    $user = logTailerUser('log-tailer-wiring');

    $response = $this->actingAs($user)->get('/dev/logs');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('/dev/logs/poll');
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

    // The nav item wraps its icon in a nested span, so the anchor is only
    // identifiable by the label text right before its closing tag.
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

it('renders the truncate button, totals strip, and stats URL on /dev/logs', function (): void {
    $user = logTailerUser('log-tailer-totals');

    $response = $this->actingAs($user)->get('/dev/logs');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('data-testid="log-truncate-button"');
    expect($html)->toContain('data-testid="log-totals-strip"');
    expect($html)->toContain('/dev/logs/stats');
});

// The tailer bound `logs-truncated` while truncate() dispatched
// `logs:truncated`, so handleTruncated() never ran: the operator emptied the
// file and watched the old buffer sit there until the next poll round-trip.
it('binds the tail reset to the exact name truncate() dispatches', function (): void {
    $user = logTailerUser('log-tailer-reset-binding');

    $html = (string) $this->actingAs($user)->get('/dev/logs')->getContent();

    expect($html)->toContain('x-on:'.LogTailerPage::TRUNCATED_EVENT.'.window="handleTruncated()"');
});

it('truncate() empties today\'s log file and dispatches the logs:truncated reset event', function (): void {
    // Sandbox the log path: truncate() would otherwise empty the developer's
    // own local log.
    $sandboxRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-truncate-'.bin2hex(random_bytes(6));
    putenv('NATIVEPHP_STORAGE_PATH='.$sandboxRoot);

    $path = UserDataPathService::dailyLogFile();
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, str_repeat('y', 8192));
    expect(filesize($path))->toBe(8192);

    $user = logTailerUser('log-tailer-truncate');

    Livewire::actingAs($user)
        ->test(LogTailerPage::class)
        ->call('truncate')
        ->assertDispatched('logs:truncated')
        ->assertDispatched(
            'toast',
            fn (string $event, array $params) => is_string($params['message'] ?? null)
                && str_contains($params['message'], 'Log truncated'),
        );

    clearstatcache(true, $path);
    expect(filesize($path))->toBe(0);
    expect(is_file($path))->toBeTrue();

    putenv('NATIVEPHP_STORAGE_PATH');
});
