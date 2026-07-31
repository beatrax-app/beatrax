<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

/*
 * ArtisanRunnerPage::render now drives a pending-row sweep before
 * reading the audit log. This test covers the two new behaviours
 * the page gained alongside CommandSpawner's eager audit write:
 *
 *   1. A pending audit row (exit_code=null, finished_at=null) whose
 *      RunRegistry entry has a dead PID is finalized synchronously
 *      during render.
 *   2. A pending audit row whose RunRegistry entry has a live PID
 *      stays pending — the page should NOT prematurely finalize
 *      anything that is still in flight.
 */

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

    // Eager audit row — what CommandSpawner writes today.
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear',
        args: [],
        tier: 'safe',
        callerUserId: $user->getKey(),
        startedAt: $clock->now(),
        finishedAt: null,
        exitCode: null,
        stdoutExcerpt: '',
        errorExcerpt: '',
        runId: $runId,
    ));

    // RunRegistry record with a guaranteed-dead PID. 999999 is well
    // above the macOS PID range cap (~99998) so posix_kill returns
    // false and the sweep treats the row as ready to finalize.
    $registry->store(new RunRecord(
        runId: $runId,
        pid: 999999,
        command: 'cache:clear',
        args: [],
        startedAt: $clock->now(),
        callerUserId: $user->getKey(),
        tier: 'safe',
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

    // The sweep handed exit_code=null because the bash detach lost
    // it; finished_at was the signal we care about — the row is no
    // longer "pending".
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
        tier: 'safe',
        callerUserId: $user->getKey(),
        startedAt: $clock->now(),
        finishedAt: null,
        exitCode: null,
        stdoutExcerpt: '',
        errorExcerpt: '',
        runId: $runId,
    ));

    // Use the test runner's own PID — guaranteed alive for the
    // duration of this assertion.
    $registry->store(new RunRecord(
        runId: $runId,
        pid: getmypid(),
        command: 'cache:clear',
        args: [],
        startedAt: $clock->now(),
        callerUserId: $user->getKey(),
        tier: 'safe',
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
        tier: 'safe',
        callerUserId: $user->getKey(),
        startedAt: $clock->now(),
        finishedAt: null,
        exitCode: null,
        stdoutExcerpt: '',
        errorExcerpt: '',
        runId: 'render-pending-1',
    ));
    // Stash a RunRegistry record with the current pid so the sweep
    // does NOT finalize the row out from under us before the render
    // assertion runs.
    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $registry->store(new RunRecord(
        runId: 'render-pending-1',
        pid: getmypid(),
        command: 'cache:clear',
        args: [],
        startedAt: CarbonImmutable::now(),
        callerUserId: $user->getKey(),
        tier: 'safe',
        status: 'running',
        outPath: sys_get_temp_dir().'/render-pending-1.out',
    ));

    $component = Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class);

    $html = $component->html();

    // RunCard renders data-run-id="<uuid>" + data-run-status="running"
    // for pending rows.
    expect($html)->toContain('data-run-id="render-pending-1"');
    expect($html)->toContain('data-run-status="running"');
});
