<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

function sweepUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

it('sweep finalizes a pending audit row whose underlying PID has exited', function (): void {
    $user = sweepUser('sweep-dead-pid');

    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);
    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    /** @var Clock $clock */
    $clock = app(Clock::class);

    $runId = 'sweep-dead-1';

    // Shaped like CommandSpawner's eager write: pending, no outcome yet.
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear',
        args: [],
        tier: CommandTier::Safe,
        callerUserId: $user->getKey(),
        startedAt: $clock->now(),
        finishedAt: null,
        exitCode: null,
        stdoutExcerpt: '',
        errorExcerpt: '',
        runId: $runId,
    ));

    // 999999 sits above the macOS PID cap (~99998), so it can never be a live
    // process and posix_kill is reliably false.
    $registry->store(new RunRecord(
        runId: $runId,
        pid: 999999,
        command: 'cache:clear',
        args: [],
        startedAt: $clock->now(),
        callerUserId: $user->getKey(),
        tier: CommandTier::Safe,
        status: 'running',
        outPath: sys_get_temp_dir().'/sweep-dead-1.out',
    ));

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->assertOk();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('dev_mode_audit')
        ->where('properties->run_id', $runId)
        ->first();

    $props = json_decode((string) $row->properties, true);

    // exit_code stays null — the bash detach loses it — so finished_at is the
    // only signal that the row is no longer pending.
    expect($props['finished_at'])->not->toBeNull();
});

it('sweep leaves a pending row alone when the underlying PID is still alive', function (): void {
    $user = sweepUser('sweep-live-pid');

    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);
    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    /** @var Clock $clock */
    $clock = app(Clock::class);

    $runId = 'sweep-live-1';

    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear',
        args: [],
        tier: CommandTier::Safe,
        callerUserId: $user->getKey(),
        startedAt: $clock->now(),
        finishedAt: null,
        exitCode: null,
        stdoutExcerpt: '',
        errorExcerpt: '',
        runId: $runId,
    ));

    // The test runner's own PID is the one guaranteed alive throughout.
    $registry->store(new RunRecord(
        runId: $runId,
        pid: getmypid(),
        command: 'cache:clear',
        args: [],
        startedAt: $clock->now(),
        callerUserId: $user->getKey(),
        tier: CommandTier::Safe,
        status: 'running',
        outPath: sys_get_temp_dir().'/sweep-live-1.out',
    ));

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->assertOk();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('dev_mode_audit')
        ->where('properties->run_id', $runId)
        ->first();

    $props = json_decode((string) $row->properties, true);

    expect($props['finished_at'])->toBeNull();
    expect($props['exit_code'])->toBeNull();
});

it('renders a pending audit row with status=running so the RunCard opens the SSE tail', function (): void {
    $user = sweepUser('sweep-render-pending');

    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);
    /** @var Clock $clock */
    $clock = app(Clock::class);

    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear',
        args: [],
        tier: CommandTier::Safe,
        callerUserId: $user->getKey(),
        startedAt: $clock->now(),
        finishedAt: null,
        exitCode: null,
        stdoutExcerpt: '',
        errorExcerpt: '',
        runId: 'render-pending-1',
    ));
    // A live PID, or the sweep finalizes the row out from under the render.
    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $registry->store(new RunRecord(
        runId: 'render-pending-1',
        pid: getmypid(),
        command: 'cache:clear',
        args: [],
        startedAt: CarbonImmutable::now(),
        callerUserId: $user->getKey(),
        tier: CommandTier::Safe,
        status: 'running',
        outPath: sys_get_temp_dir().'/render-pending-1.out',
    ));

    $component = Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class);

    $html = $component->html();

    expect($html)->toContain('data-run-id="render-pending-1"');
    expect($html)->toContain('data-run-status="running"');
});
