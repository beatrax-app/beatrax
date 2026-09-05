<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Http\Controllers\ArtisanStreamController;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required');
    }

    $runsDir = UserDataPathService::appPath('dev_mode/runs');
    if (is_dir($runsDir)) {
        foreach (glob($runsDir.'/*.out') ?: [] as $file) {
            @unlink($file);
        }
    }
});

function devUser(string $username = 'dev-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

// A hand-built child on a known tick interval, so the tail loop is
// deterministic without the spawner's shell wrapper in the way.
/**
 * @return array{runId: string, pid: int, outPath: string}
 */
function spawnTickingChild(int $callerUserId, int $ticks = 6, int $intervalMs = 200): array
{
    $runId = (string) Str::uuid();
    $outPath = UserDataPathService::appPath('dev_mode/runs/'.$runId.'.out');
    @mkdir(dirname($outPath), 0700, true);
    @touch($outPath);

    $intervalSeconds = $intervalMs / 1000;
    $script = sprintf(
        'for i in $(seq 1 %d); do echo "line-$i" >> %s; sleep %s; done',
        $ticks,
        escapeshellarg($outPath),
        escapeshellarg(number_format($intervalSeconds, 3)),
    );
    $detach = 'bash -c '.escapeshellarg($script.'').' < /dev/null > /dev/null 2>&1 & echo $!';

    $proc = Process::fromShellCommandline($detach);
    $proc->setTimeout(5.0);
    $proc->run();
    $pid = (int) trim($proc->getOutput());

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $registry->store(new RunRecord(
        runId: $runId,
        pid: $pid,
        command: 'beatrax:emit-ticks',
        args: ['count' => $ticks],
        startedAt: CarbonImmutable::now(),
        callerUserId: $callerUserId,
        tier: CommandTier::Safe,
        status: 'running',
        outPath: $outPath,
    ));

    return ['runId' => $runId, 'pid' => $pid, 'outPath' => $outPath];
}

/**
 * @return array{body: string, completed: bool, lastEventId: int}
 */
function captureSseStream(string $runId, int $userId, int $fromOffset = 0, float $readDeadlineSeconds = 1.0): array
{
    /** @var ArtisanStreamController $controller */
    $controller = app(ArtisanStreamController::class);

    $request = Request::create(
        uri: '/dev/artisan/stream/'.$runId,
        method: 'GET',
        parameters: $fromOffset > 0 ? ['from' => (string) $fromOffset] : [],
    );

    $previous = app()->getBindings()[CurrentUser::class] ?? null;
    $stub = new class($userId) implements CurrentUser
    {
        public function __construct(private readonly int $id) {}

        public function id(): int
        {
            return $this->id;
        }

        public function user(): User
        {
            /** @var User $u */
            $u = User::query()->findOrFail($this->id);

            return $u;
        }

        public function periodStartDay(): int
        {
            return 1;
        }

        public function isAuthenticated(): bool
        {
            return true;
        }
    };
    app()->instance(CurrentUser::class, $stub);

    try {
        /** @var StreamedResponse $response */
        $response = $controller->__invoke($runId, $request, $stub);

        $body = '';
        $completed = false;

        // The buffer intercepts the controller's ob_flush()/flush(); without
        // it the SSE bytes reach PHP's default output handler and spray
        // across PHPUnit's terminal. Returning '' is what stops them.
        ob_start(function (string $chunk) use (&$body): string {
            $body .= $chunk;

            return '';
        });
        try {
            $response->sendContent();
            $completed = true;
        } finally {
            @ob_end_flush();
        }
        unset($readDeadlineSeconds);
    } finally {
        if ($previous !== null) {
            app()->instance(CurrentUser::class, $previous);
        }
    }

    $lastEventId = 0;
    $lastIds = PatternScan::all('/^id:\s*(\d+)/m', $body)[1];
    if ($lastIds !== []) {
        $lastEventId = (int) end($lastIds);
    }

    return ['body' => $body, 'completed' => $completed, 'lastEventId' => $lastEventId];
}

it('returns 202 + run_id from POST /dev/artisan/spawn for a SAFE-tier command', function (): void {
    $user = devUser('spawn-user');

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);

    $response = $this->actingAs($user)->postJson('/dev/artisan/spawn', [
        'command' => 'cache:clear',
    ]);

    $response->assertStatus(202);
    $response->assertJsonStructure(['run_id', 'pid']);

    $runId = $response->json('run_id');
    expect($runId)->toBeString();

    $record = $registry->find($runId);
    expect($record)->not->toBeNull();
    expect($record->command)->toBe('cache:clear');
    expect($record->tier)->toBe(CommandTier::Safe);
    expect($record->callerUserId)->toBe($user->id);
});

it('rejects a DESTRUCTIVE-tier command at POST /dev/artisan/spawn with 403', function (): void {
    $user = devUser('destructive-rejector');

    $response = $this->actingAs($user)->postJson('/dev/artisan/spawn', [
        'command' => 'db:restore',
        'args' => ['path' => '/tmp/x'],
    ]);

    $response->assertStatus(403);
    $response->assertJson(['error' => 'destructive_requires_triple_gate']);
});

it('streams text/event-stream from /dev/artisan/stream/{run_id} with data + done events', function (): void {
    $user = devUser('stream-user');

    $spawn = spawnTickingChild($user->id, ticks: 3, intervalMs: 150);

    $capture = captureSseStream($spawn['runId'], $user->id, fromOffset: 0);

    expect($capture['body'])->toContain('data: ');
    expect($capture['body'])->toContain('event: done');
    expect($capture['body'])->toContain('line-1');
    expect($capture['body'])->toContain('line-3');
});

it('honors ?from= for page-refresh-reconnect — second handle observes only later lines', function (): void {
    $user = devUser('reconnect-user');

    // 6 ticks at 250 ms leaves room to cut the run in two.
    $spawn = spawnTickingChild($user->id, ticks: 6, intervalMs: 250);

    usleep(700_000); // Past ticks 1 and 2, short of tick 3.

    // The cut offset stands in for where a first handle stopped reading.
    clearstatcache(true, $spawn['outPath']);
    $cutOffset = filesize($spawn['outPath']);
    expect($cutOffset)->toBeGreaterThan(0);

    $firstHandleBytes = (string) file_get_contents($spawn['outPath']);
    expect($firstHandleBytes)->toContain('line-1');

    // The controller only emits its terminal done event once the liveness
    // check sees the child gone.
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline && posix_kill($spawn['pid'], 0)) {
        usleep(100_000);
    }
    expect(posix_kill($spawn['pid'], 0))->toBeFalse();

    $secondCapture = captureSseStream($spawn['runId'], $user->id, fromOffset: $cutOffset);

    expect($secondCapture['body'])->toContain('event: done');

    $lines = PatternScan::all('/data:\s*\{"line":"(line-\d+)/m', $secondCapture['body'])[1];
    foreach (['line-1', 'line-2'] as $alreadySeen) {
        // Timing-tolerant: only assert on a line the cut actually contained.
        if (str_contains($firstHandleBytes, $alreadySeen.\PHP_EOL)) {
            expect($lines)->not->toContain($alreadySeen,
                "Reconnect must not replay {$alreadySeen} (line was present in the first handle's snapshot at offset {$cutOffset})");
        }
    }
});

it('writes session key dev_mode.advanced via POST /dev/advanced-toggle and returns 204', function (): void {
    $user = devUser('toggler');

    $response = $this->actingAs($user)->postJson('/dev/advanced-toggle', [
        'value' => true,
    ]);

    $response->assertStatus(204);
    expect(session('dev_mode.advanced'))->toBeTrue();

    $response2 = $this->actingAs($user)->postJson('/dev/advanced-toggle', [
        'value' => false,
    ]);
    $response2->assertStatus(204);
    expect(session('dev_mode.advanced'))->toBeFalse();
});

it('rejects cross-user inspection on /dev/artisan/stream/{run_id} with 403', function (): void {
    $spawner = devUser('cross-user-spawner');
    $intruder = devUser('cross-user-intruder');

    /** @var CommandSpawner $sp */
    $sp = app(CommandSpawner::class);
    $runId = $sp->start('cache:clear', [], $spawner->id, CommandTier::Safe);

    // The intruder is a developer and clears EnsureDeveloperMode; the
    // per-run owner check is the only thing stopping them.
    $response = $this->actingAs($intruder)
        ->get("/dev/artisan/stream/{$runId}");

    $response->assertStatus(403);
});
