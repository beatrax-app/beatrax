<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;

/*
 * DevOverviewPage rendering invariants.
 *
 * The page renders the /dev overview surface: a theme-locked dark
 * .console-pane (primary visual anchor; fixed #0b1220 background)
 * with a three-column head (worker heartbeat + queue counts +
 * last command), an 8-line redacted log tail with cursor blink,
 * plus "Recent runs" and "Open alerts" cards beneath the pane.
 * The sidebar's Doctor / SQL / System entries render enabled when
 * their routes are registered.
 */

function devOverviewUser(bool $isDeveloper, string $username = 'devov-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('renders the /dev overview page for an authenticated developer with the dev-shell sidebar', function (): void {
    $user = devOverviewUser(true, 'devov-dev');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertOk();
    $response->assertSee('Dev Console', escape: false); // sidebar heading
    $response->assertSee('Overview', escape: false); // page heading
});

it('returns 404 from /dev for an authenticated non-developer (EnsureDeveloperMode gate)', function (): void {
    devOverviewUser(true, 'devov-seed-for-gate');
    $user = devOverviewUser(false, 'devov-nondev');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertNotFound();
});

it('renders dev-sidebar nav items with every non-Horizon entry visible (Doctor / SQL / System routes land alongside this overview)', function (): void {
    $user = devOverviewUser(true, 'devov-nav');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertOk();
    $html = (string) $response->getContent();

    foreach (['Overview', 'Artisan', 'Audit', 'Logs', 'Queue', 'Doctor', 'SQL', 'System'] as $label) {
        expect(str_contains($html, $label))
            ->toBeTrue("Dev sidebar missing nav item: {$label}");
    }

    // Horizon is DOM-absent in the default test env (no
    // app.dev_mode + no Horizon package install). Every other
    // entry has a registered route → no nav-disabled markers.
    $disabledCount = substr_count($html, 'nav-disabled');
    expect($disabledCount)->toBe(
        0,
        "Expected zero nav-disabled entries (every dev route is registered), saw {$disabledCount}.",
    );

    expect(str_contains($html, '>Horizon<'))
        ->toBeFalse('Horizon nav item should be DOM-absent when dev.horizon is not registered.');
});

it('declares the 220px dev-shell sidebar width (--side-w-dev token or inline)', function (): void {
    $user = devOverviewUser(true, 'devov-width');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('--side-w-dev')
        ->and($html)->toContain('220px');
});

it('embeds the "Back to app" foot link routing to /', function (): void {
    $user = devOverviewUser(true, 'devov-back');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('Back to app')
        ->and($html)->toContain('dev-back-link');
});

it('renders the theme-locked dark console pane on /dev as the primary visual anchor', function (): void {
    $user = devOverviewUser(true, 'devov-console-pane');

    $response = $this->actingAs($user)->get('/dev');

    $response->assertOk();
    $html = (string) $response->getContent();

    // UI-SPEC § /dev overview surfaces locks the console pane as a
    // theme-locked dark inset (#0b1220 bg / #f1f5f9 text regardless of
    // theme). The pane must render with a literal inline style flag so
    // theme switches do not affect the dark inset.
    expect($html)->toContain('console-pane');
    expect($html)->toContain('#0b1220');
});

it('reads worker heartbeat from cache and renders the relative timestamp (or NOT RUNNING when missing)', function (): void {
    $user = devOverviewUser(true, 'devov-heartbeat');

    // Case 1: cache empty → must render NOT RUNNING.
    /** @var CacheRepository $cache */
    $cache = app(CacheRepository::class);
    $cache->forget(WriteWorkerHeartbeat::CACHE_KEY);

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    expect((string) $response->getContent())->toContain('NOT RUNNING');

    // Case 2: cache populated → "Ns ago · ttl 60s" formatted line.
    $cache->put(
        WriteWorkerHeartbeat::CACHE_KEY,
        Carbon::now()->subSeconds(7)->getTimestamp(),
        WriteWorkerHeartbeat::TTL_SECONDS,
    );
    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();
    expect($html)->toContain('ttl 60s');
    // Match the "Ns ago" pattern — relative seconds rendered server-side.
    expect(preg_match('/\d+s\s+ago/', $html))->toBe(1, 'Expected "Ns ago" relative-time label for a fresh heartbeat.');
});

it('renders queue count tiles (pending / failed / batches) sourced from the framework queue tables', function (): void {
    $user = devOverviewUser(true, 'devov-queue-counts');

    // Seed two pending jobs.
    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{"a":1}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => 0, 'created_at' => 0],
        ['queue' => 'default', 'payload' => '{"a":2}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => 0, 'created_at' => 0],
    ]);
    // Seed one failed job.
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"err":1}',
        'exception' => 'boom',
        'failed_at' => Carbon::now()->toDateTimeString(),
    ]);
    // Seed one active batch.
    DB::table('job_batches')->insert([
        'id' => (string) Str::uuid(),
        'name' => 'active-batch',
        'total_jobs' => 3,
        'pending_jobs' => 2,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => null,
        'cancelled_at' => null,
        'finished_at' => null,
        'created_at' => Carbon::now()->getTimestamp(),
    ]);

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('data-testid="queue-tile-pending"');
    expect($html)->toContain('data-testid="queue-tile-failed"');
    expect($html)->toContain('data-testid="queue-tile-batches"');
    // Counts: 2 pending, 1 failed, 1 batch. Match across whitespace
    // and span tags between the testid marker and the rendered number.
    expect(preg_match('#data-testid="queue-tile-pending"[\s\S]*?>2<#', $html))->toBe(1);
    expect(preg_match('#data-testid="queue-tile-failed"[\s\S]*?>1<#', $html))->toBe(1);
    expect(preg_match('#data-testid="queue-tile-batches"[\s\S]*?>1<#', $html))->toBe(1);
});

it('shows the current developer\'s last 5 dev_mode_audit rows in the Recent runs card and links to /dev/audit?command=…', function (): void {
    $user = devOverviewUser(true, 'devov-recent-runs');

    // Seed 6 audit rows for the developer (so we can verify the cap to 5)
    // + 1 audit row for a different user (must NOT appear).
    $other = devOverviewUser(true, 'devov-other-runs');
    for ($i = 1; $i <= 6; $i++) {
        DB::table('dev_mode_audit')->insert([
            'log_name' => 'dev_mode',
            'description' => 'command_executed',
            'subject_type' => null,
            'subject_id' => null,
            'causer_type' => User::class,
            'causer_id' => $user->id,
            'event' => null,
            'attribute_changes' => null,
            'properties' => json_encode([
                'command' => 'route:list',
                'args' => [],
                'tier' => 'safe',
                'exit_code' => 0,
            ], JSON_THROW_ON_ERROR),
            'created_at' => Carbon::now()->subMinutes(10 - $i)->toDateTimeString(),
            'updated_at' => Carbon::now()->subMinutes(10 - $i)->toDateTimeString(),
        ]);
    }
    DB::table('dev_mode_audit')->insert([
        'log_name' => 'dev_mode',
        'description' => 'command_executed',
        'subject_type' => null,
        'subject_id' => null,
        'causer_type' => User::class,
        'causer_id' => $other->id,
        'event' => null,
        'attribute_changes' => null,
        'properties' => json_encode([
            'command' => 'cache:clear',
            'args' => [],
            'tier' => 'safe',
            'exit_code' => 0,
        ], JSON_THROW_ON_ERROR),
        'created_at' => Carbon::now()->toDateTimeString(),
        'updated_at' => Carbon::now()->toDateTimeString(),
    ]);

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    // Card heading is verbatim (UI-SPEC § Copywriting).
    expect($html)->toContain('Recent runs');
    // The developer's own runs surface (route:list shows up) inside
    // the Recent runs card. Other developers' runs may appear in the
    // system-wide "Last command" tile but MUST NOT appear in the
    // current developer's Recent runs card.
    expect($html)->toContain('route:list');

    // Carve out the Recent runs card body and assert cross-user
    // isolation against that subtree alone.
    $cardOffset = strpos($html, 'data-testid="recent-runs-card"');
    expect($cardOffset)->not->toBeFalse('Recent runs card not rendered.');
    $cardCloseOffset = strpos($html, '</ul>', is_int($cardOffset) ? $cardOffset : 0);
    $cardBody = $cardOffset !== false && $cardCloseOffset !== false
        ? substr($html, is_int($cardOffset) ? $cardOffset : 0, ($cardCloseOffset - $cardOffset) + 5)
        : '';
    expect($cardBody)->toContain('route:list');
    expect($cardBody)->not->toContain('cache:clear');

    // Row links navigate to /dev/audit?command=route:list — the
    // server-rendered href encodes the filter.
    expect($html)->toContain('/dev/audit?command=route%3Alist');
    // Cap at 5 → six rows seeded; only five rendered.
    $rowOccurrences = substr_count($html, 'data-testid="recent-run-row"');
    expect($rowOccurrences)->toBe(5);
});

it('renders Open alerts card from the unacknowledged system_alerts feed', function (): void {
    $user = devOverviewUser(true, 'devov-alerts');

    SystemAlert::query()->create([
        'user_id' => null,
        'kind' => 'backup_overdue',
        'severity' => 'warning',
        'message' => 'Backup is overdue by 50h',
        'metadata' => ['hours_old' => 50],
    ]);

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('Open alerts');
    expect($html)->toContain('Backup is overdue by 50h');
});

it('renders the empty-state copy when no recent runs exist for the current developer', function (): void {
    $user = devOverviewUser(true, 'devov-empty-runs');

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('Recent runs');
    // UI-SPEC § Copywriting verbatim.
    expect($html)->toContain('No runs yet. Press ⌘K to run a command.');
});

it('renders the empty-state copy when no open alerts exist', function (): void {
    $user = devOverviewUser(true, 'devov-empty-alerts');

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('Open alerts');
    expect($html)->toContain('No open alerts.');
});

it('renders the last 5 structured log entries in the console pane as clickable rows that drill into /dev/logs', function (): void {
    $user = devOverviewUser(true, 'devov-log-tail');

    // Write a synthetic daily log file with a known secret literal so we
    // can verify the redaction layer is wired before render.
    $logFile = UserDataPathService::dailyLogFile();
    $logDir = dirname($logFile);
    if (! is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    $lines = [];
    for ($i = 1; $i <= 7; $i++) {
        $lines[] = "[2026-05-24 12:00:0{$i}] testing.INFO: line number {$i}";
    }
    // Add one line carrying a Bearer header — the row render path must
    // scrub it via the on-stream RedactSecretsProcessor.
    $lines[] = '[2026-05-24 12:00:08] testing.WARNING: Authorization: Bearer abcdefghi1234567890';
    file_put_contents($logFile, implode("\n", $lines)."\n");

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    // Container marker still anchors the test layer.
    expect($html)->toContain('data-testid="console-pane-tail"');
    // Cap at 5 rows even when 8 entries exist in the file.
    $rowCount = substr_count($html, 'data-testid="recent-log-entry-row"');
    expect($rowCount)->toBe(5);
    // The newest 5 entries appear (lines 4-7 + the WARNING). The cap
    // drops the oldest three. The WARNING row's deep-link must carry
    // the severity filter pre-populated.
    expect($html)->toContain('line number 7') // newest INFO surfaces
        ->and($html)->toContain('line number 4')
        ->and($html)->not->toContain('line number 1') // older entries dropped by cap
        ->and($html)->not->toContain('line number 2')
        ->and($html)->not->toContain('line number 3');
    // The Bearer literal MUST NOT appear raw in any row.
    expect($html)->not->toContain('abcdefghi1234567890');
    // The scrubbed marker must appear inside the redacted row.
    expect($html)->toContain('[REDACTED]');
    // Row hrefs deep-link to /dev/logs with severity + contains pre-set.
    expect($html)->toContain('/dev/logs?severities=INFO&amp;contains=')
        ->and($html)->toContain('/dev/logs?severities=WARNING&amp;contains=');

    @unlink($logFile);
});

it('renders the empty-state copy in the console pane when the daily log file is missing', function (): void {
    $user = devOverviewUser(true, 'devov-log-empty');

    $logFile = UserDataPathService::dailyLogFile();
    if (is_file($logFile)) {
        @unlink($logFile);
    }

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('data-testid="console-pane-tail"');
    expect($html)->toContain('Waiting for log lines…');
    expect(substr_count($html, 'data-testid="recent-log-entry-row"'))->toBe(0);
});

it('folds continuation lines into the preceding entry so a stack trace renders as one row, not many', function (): void {
    $user = devOverviewUser(true, 'devov-log-fold');

    $logFile = UserDataPathService::dailyLogFile();
    $logDir = dirname($logFile);
    if (! is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    $lines = [
        '[2026-05-24 12:00:01] testing.ERROR: boom uncaught',
        '#0 /app/foo.php(12): doStuff()',
        '#1 /app/bar.php(34): foo()',
        '#2 /app/baz.php(56): bar()',
        '[2026-05-24 12:00:02] testing.INFO: subsequent entry',
    ];
    file_put_contents($logFile, implode("\n", $lines)."\n");

    $response = $this->actingAs($user)->get('/dev');
    $response->assertOk();
    $html = (string) $response->getContent();

    // The stack-trace continuation lines fold into the ERROR row's
    // message — the row count reflects two real entries, not five.
    $rowCount = substr_count($html, 'data-testid="recent-log-entry-row"');
    expect($rowCount)->toBe(2);

    @unlink($logFile);
});
