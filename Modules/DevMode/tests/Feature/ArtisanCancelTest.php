<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required');
    }
});

function cancelUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

it('returns 404 for an unknown runId', function (): void {
    $user = cancelUser('cancel-unknown');

    $response = $this->actingAs($user)
        ->postJson('/dev/artisan/cancel/00000000-0000-0000-0000-000000000000');

    $response->assertStatus(404);
});

it('returns 204 idempotently when the process has already exited', function (): void {
    $user = cancelUser('cancel-already-exited');

    $runId = (string) Str::uuid();
    $outPath = UserDataPathService::appPath('dev_mode/runs/'.$runId.'.out');
    @mkdir(dirname($outPath), 0700, true);
    @touch($outPath);

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $registry->store(new RunRecord(
        runId: $runId,
        pid: 999_999_999, // Never-allocated PID; posix_kill(pid, 0) is false.
        command: 'cache:clear',
        args: [],
        startedAt: CarbonImmutable::now(),
        callerUserId: $user->id,
        tier: 'safe',
        status: 'running',
        outPath: $outPath,
    ));

    $response = $this->actingAs($user)->postJson("/dev/artisan/cancel/{$runId}");

    $response->assertStatus(204);
    $record = $registry->find($runId);
    expect($record?->status)->toBe('cancelled');
});

it('cancels a real long-running child via SIGTERM within the 3s grace', function (): void {
    $user = cancelUser('cancel-real');

    $runId = (string) Str::uuid();
    $outPath = UserDataPathService::appPath('dev_mode/runs/'.$runId.'.out');
    @mkdir(dirname($outPath), 0700, true);
    @touch($outPath);
    $proc = Process::fromShellCommandline(
        'sleep 30 > '.escapeshellarg($outPath).' 2>&1 < /dev/null & echo $!',
    );
    $proc->setTimeout(5.0);
    $proc->run();
    $pid = (int) trim($proc->getOutput());
    expect($pid)->toBeGreaterThan(0);
    expect(posix_kill($pid, 0))->toBeTrue();

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $registry->store(new RunRecord(
        runId: $runId,
        pid: $pid,
        command: 'cache:clear',
        args: [],
        startedAt: CarbonImmutable::now(),
        callerUserId: $user->id,
        tier: 'safe',
        status: 'running',
        outPath: $outPath,
    ));

    $response = $this->actingAs($user)->postJson("/dev/artisan/cancel/{$runId}");
    $response->assertStatus(204);

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline && posix_kill($pid, 0)) {
        usleep(100_000);
    }
    expect(posix_kill($pid, 0))->toBeFalse();

    $record = $registry->find($runId);
    expect($record?->status)->toBe('cancelled');
});

it('rejects cross-user cancel with 403', function (): void {
    $owner = cancelUser('cancel-owner');
    $intruder = cancelUser('cancel-intruder');

    $runId = (string) Str::uuid();
    $outPath = UserDataPathService::appPath('dev_mode/runs/'.$runId.'.out');
    @mkdir(dirname($outPath), 0700, true);
    @touch($outPath);

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $registry->store(new RunRecord(
        runId: $runId,
        pid: 999_999_999,
        command: 'cache:clear',
        args: [],
        startedAt: CarbonImmutable::now(),
        callerUserId: $owner->id,
        tier: 'safe',
        status: 'running',
        outPath: $outPath,
    ));

    $response = $this->actingAs($intruder)->postJson("/dev/artisan/cancel/{$runId}");
    $response->assertStatus(403);

    $record = $registry->find($runId);
    expect($record?->status)->toBe('running');
});
