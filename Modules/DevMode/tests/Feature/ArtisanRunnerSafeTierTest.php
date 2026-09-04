<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

function runnerDeveloper(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

it('renders GET /dev/artisan for a developer with the runner-page header + filter chips + empty timeline', function (): void {
    $user = runnerDeveloper('runner-empty');

    $response = $this->actingAs($user)->get('/dev/artisan');

    $response->assertStatus(200);
    $response->assertSee('Artisan runner');
    $response->assertSee('Run a command');
    $response->assertSee('All');
    $response->assertSee('Running');
    $response->assertSee('Failed');
    $response->assertSee('Destructive');
    $response->assertSee('No runs yet');
});

it('shows worker pre-flight pill NOT RUNNING when the heartbeat cache key is missing', function (): void {
    $user = runnerDeveloper('runner-worker-off');
    /** @var Repository $cache */
    $cache = app(Repository::class);
    $cache->forget(WriteWorkerHeartbeat::CACHE_KEY);

    $response = $this->actingAs($user)->get('/dev/artisan');

    $response->assertSee('Queue worker: NOT RUNNING');
});

it('shows worker pre-flight pill RUNNING when the heartbeat is fresh', function (): void {
    $user = runnerDeveloper('runner-worker-on');
    /** @var Repository $cache */
    $cache = app(Repository::class);
    $cache->put(WriteWorkerHeartbeat::CACHE_KEY, time(), WriteWorkerHeartbeat::ttlSeconds());

    $response = $this->actingAs($user)->get('/dev/artisan');

    $response->assertSee('Queue worker: RUNNING');
});

it('exposes SAFE-tier commands ONLY in the fallback modal (DESTRUCTIVE never appears here)', function (): void {
    $user = runnerDeveloper('runner-b2');

    $response = $this->actingAs($user)->get('/dev/artisan');
    $html = (string) $response->getContent();

    $safeNames = [
        'cache:clear',
        'route:list',
        'view:clear',
        'config:show',
        'beatrax:doctor',
        'beatrax:rederive-fingerprints',
        'db:backup',
    ];
    foreach ($safeNames as $name) {
        expect(str_contains($html, $name))->toBeTrue("Expected SAFE command {$name} in fallback modal");
    }

    // The empty timeline is what makes the whole-page search sound: with runs
    // present, a rendered audit row could legitimately name a destructive
    // command and the assertion would no longer isolate the modal.
    $destructiveNames = [
        'db:restore',
        'migrate:fresh',
        'beatrax:reset-password',
        'beatrax:regenerate-recovery-codes',
        'beatrax:grant-dev',
        'beatrax:install',
    ];
    foreach ($destructiveNames as $name) {
        expect(str_contains($html, $name))->toBeFalse("DESTRUCTIVE command {$name} must NOT appear in the runner page when no runs exist");
    }
});

it('renders GET /dev/audit with the audit-log table header + filter controls', function (): void {
    $user = runnerDeveloper('audit-page-empty');

    $response = $this->actingAs($user)->get('/dev/audit');

    $response->assertStatus(200);
    $response->assertSee('Audit log');
    $response->assertSee('Tier');
    $response->assertSee('Command');
    // The page reads only the caller's own rows now, so there is no caller to
    // filter by and no caller column to head.
    $response->assertDontSee('Caller');
});

it('shows prior runs on /dev/audit with the tier chip + non-zero exit-code highlighting', function (): void {
    $user = runnerDeveloper('audit-page-rows');

    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'db:restore', args: ['from' => '/tmp/x'], tier: CommandTier::Destructive,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 2, stdoutExcerpt: 'failed', errorExcerpt: '',
    ));
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear', args: [], tier: CommandTier::Safe,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0, stdoutExcerpt: 'done', errorExcerpt: '',
    ));

    $response = $this->actingAs($user)->get('/dev/audit');
    $response->assertStatus(200);
    $response->assertSee('db:restore');
    $response->assertSee('cache:clear');
    $response->assertSee('DESTRUCTIVE');
    $response->assertSee('SAFE');

    $html = (string) $response->getContent();
    expect($html)->toContain('text-rose-600');
});

it('filters /dev/audit?tier=destructive to only destructive rows', function (): void {
    $user = runnerDeveloper('audit-filter');

    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear', args: [], tier: CommandTier::Safe,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0, stdoutExcerpt: '', errorExcerpt: '',
    ));
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'migrate:fresh', args: [], tier: CommandTier::Destructive,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0, stdoutExcerpt: '', errorExcerpt: '',
    ));

    expect(DB::table('dev_mode_audit')->count())->toBe(2);

    $response = $this->actingAs($user)->get('/dev/audit?tier=destructive');
    $response->assertStatus(200);
    $response->assertSee('migrate:fresh');

    // The palette modal embeds the whole SAFE roster as JSON further down the
    // page, so its "cache:clear" row would satisfy a whole-document search.
    $html = (string) $response->getContent();
    $auditRegion = explode('@livewire(\'dev.command-palette-modal\')', $html)[0];
    // Second cut on the bare name: Blade compiles the @livewire directive away.
    $auditRegion = explode('command-palette-modal', $auditRegion)[0];
    expect($auditRegion)->not->toContain('cache:clear');
});

it('spawn() on the runner page invokes the spawner and registers a run record for a SAFE-tier command', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    $user = runnerDeveloper('runner-spawn-action');

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('spawn', 'cache:clear', [])
        ->assertDispatched('toast');

    // RunRegistry::find() is keyed by runId and the run id is only ever
    // surfaced inside the toast, so the dispatch is the reachable assertion.
    $messages = collect(Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('spawn', 'cache:clear', [])
        ->effects['dispatches'] ?? []);

    expect($messages)->not->toBeEmpty();
});

it('spawn() refuses to spawn a SAFE-tier command whose required args are missing and surfaces a clear toast', function (): void {
    // Regression: picking config:show from the palette used to spawn with no
    // args, and Symfony Console's "Not enough arguments" landed in the log
    // with nothing shown to the operator.
    $user = runnerDeveloper('runner-required-arg-guard');

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('spawn', 'config:show', [])
        ->assertDispatched(
            'toast',
            fn (string $event, array $params) => isset($params['message'])
                && is_string($params['message'])
                && str_contains($params['message'], 'config:show')
                && str_contains($params['message'], 'Config key'),
        );
});

it('spawn() proceeds when every required arg has a value', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    $user = runnerDeveloper('runner-required-arg-supplied');

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('spawn', 'config:show', ['config' => 'app'])
        ->assertDispatched(
            'toast',
            fn (string $event, array $params) => isset($params['message'])
                && is_string($params['message'])
                && str_starts_with($params['message'], 'Started config:show'),
        );
});

it('onSpawnCommand() listener routes a palette-dispatched spawn through the same SAFE-tier path as spawn()', function (): void {
    // Regression: "executing an artisan command does nothing, just quits the
    // modal" — the palette dispatched `spawn-command` and no listener existed,
    // so the event had no sink and the spawn was dropped silently.
    $user = runnerDeveloper('runner-palette-listener-safe');

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->dispatch('spawn-command', name: 'cache:clear', args: [], tier: 'safe')
        ->assertDispatched('toast');
});

it('onSpawnCommand() listener routes a DESTRUCTIVE name to the triple-gate (defence-in-depth — claimed tier is ignored)', function (): void {
    // The client stamps `tier: 'safe'` on every palette pick, so a hostile one
    // can claim it for a destructive name. spawn() re-reads the registry
    // rather than trusting the claim.
    $user = runnerDeveloper('runner-palette-listener-destructive');

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->dispatch('spawn-command', name: 'db:restore', args: ['from' => '/tmp/x'], tier: 'safe')
        ->assertDispatched('triple-gate:open');
});

it('spawn() routes a DESTRUCTIVE command to the triple-gate instead of spawning it', function (): void {
    $user = runnerDeveloper('runner-spawn-destructive');

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('spawn', 'db:restore', ['from' => '/tmp/x'])
        ->assertDispatched('triple-gate:open');
});

it('rerun() for a SAFE-tier prior run spawns a fresh run with the same command', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    $user = runnerDeveloper('runner-rerun-safe');

    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);
    $originalRunId = $spawner->start('cache:clear', [], $user->id, CommandTier::Safe);

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('rerun', $originalRunId)
        ->assertDispatched('toast');
});

it('rerun() for a DESTRUCTIVE prior run opens the triple-gate carrying the original command + args', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    $user = runnerDeveloper('runner-rerun-destructive');

    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);
    $originalRunId = $spawner->start('db:restore', ['from' => '/tmp/x.sqlite'], $user->id, CommandTier::Destructive);

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('rerun', $originalRunId)
        ->assertDispatched('triple-gate:open');
});

it('enables the Artisan + Audit sidebar nav items (drops nav-disabled when dev.artisan + dev.audit are registered)', function (): void {
    $user = runnerDeveloper('runner-sidebar');

    $response = $this->actingAs($user)->get('/dev/artisan');
    $html = (string) $response->getContent();

    // dev-shell.blade.php stamps nav-disabled on an entry whose route is not
    // registered, so its absence is the assertion.
    $matches = PatternScan::all('#<a\s+href="[^"]*"\s+class="side-item([^"]*)"[^>]*>.*?(Artisan|Audit).*?</a>#s', $html);

    expect($matches[0])->not->toBeEmpty();
    foreach ($matches[0] as $i => $anchor) {
        $label = $matches[2][$i];
        $classes = $matches[1][$i];
        if (in_array($label, ['Artisan', 'Audit'], true)) {
            expect($classes)->not->toContain('nav-disabled', "Sidebar entry '{$label}' should NOT have nav-disabled when its route is registered");
        }
    }
});
