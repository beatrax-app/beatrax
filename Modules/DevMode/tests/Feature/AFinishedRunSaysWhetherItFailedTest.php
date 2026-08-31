<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunExitCodeFile;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;

function exitCodeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function storeRunWithExitFile(User $user, string $runId, ?string $exitFileContents): string
{
    $outPath = UserDataPathService::appPath('dev_mode/runs/'.$runId.'.out');
    @mkdir(dirname($outPath), 0700, true);
    file_put_contents($outPath, "Error: something blew up\n");
    if ($exitFileContents !== null) {
        file_put_contents(RunExitCodeFile::pathFor($outPath), $exitFileContents);
    }

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $registry->store(new RunRecord(
        runId: $runId,
        pid: 999_999,
        command: 'cache:clear',
        args: [],
        startedAt: CarbonImmutable::now(),
        callerUserId: $user->id,
        tier: CommandTier::Safe,
        status: 'running',
        outPath: $outPath,
    ));

    return $outPath;
}

afterEach(function (): void {
    foreach (glob(UserDataPathService::appPath('dev_mode/runs').'/*.exit') ?: [] as $file) {
        @unlink($file);
    }
});

it('records the exit code the detached child actually returned, instead of leaving it unknown', function (): void {
    $user = exitCodeUser('exit-code-recorded');
    $runId = (string) Str::uuid();
    storeRunWithExitFile($user, $runId, "3\n");

    /** @var FinalizeRunAudit $finalize */
    $finalize = app(FinalizeRunAudit::class);
    // Null is what both callers have: a vanished PID is all either of them saw.
    ($finalize)($runId, null, false);

    $row = DB::table('dev_mode_audit')->where('properties->run_id', $runId)->first();
    $properties = json_decode((string) $row->properties, true);

    expect($properties['exit_code'] ?? null)->toBe(3);
});

it('lists a run that blew up under the Failed filter', function (): void {
    $user = exitCodeUser('exit-code-filter');
    $runId = (string) Str::uuid();
    storeRunWithExitFile($user, $runId, "3\n");

    /** @var FinalizeRunAudit $finalize */
    $finalize = app(FinalizeRunAudit::class);
    ($finalize)($runId, null, false);

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class, ['filter' => 'failed'])
        ->assertSee('data-run-id="'.$runId.'"', escape: false)
        ->assertSee('data-run-status="failed"', escape: false);
});

it('leaves a clean run out of the Failed filter', function (): void {
    $user = exitCodeUser('exit-code-filter-clean');
    $runId = (string) Str::uuid();
    storeRunWithExitFile($user, $runId, "0\n");

    /** @var FinalizeRunAudit $finalize */
    $finalize = app(FinalizeRunAudit::class);
    ($finalize)($runId, null, false);

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class, ['filter' => 'failed'])
        ->assertDontSee('data-run-id="'.$runId.'"', escape: false);
});

it('writes the exit code of a real detached run beside its output file', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-wait');
    }

    $user = exitCodeUser('exit-code-detached');

    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);
    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);

    // config:show on a key no config file defines exits 1 — the case the
    // console exists to show, and the one the timeline used to call Done.
    $runId = $spawner->start('config:show', ['config' => 'zzznotarealkey'], $user->id, CommandTier::Safe);
    $record = $registry->find($runId);

    $deadline = microtime(true) + 30.0;
    while (microtime(true) < $deadline) {
        if (RunExitCodeFile::read($record->outPath) !== null) {
            break;
        }
        usleep(50_000);
    }

    expect(RunExitCodeFile::read($record->outPath))->toBe(1);
    // The published pid is still artisan's own, which is what cancel signals.
    expect(posix_kill($record->pid, 0))->toBeFalse();
});
