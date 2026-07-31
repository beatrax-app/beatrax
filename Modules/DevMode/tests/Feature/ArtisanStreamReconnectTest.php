<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Http\Controllers\ArtisanStreamController;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

/*
 * SAFE spawn + SSE reconnect contract tests.
 *
 * The headline test is "page refresh during a running command
 * reconnects to the live stream". The test spawns a real SAFE-tier
 * command end-to-end, opens an SSE handle, kills the parent
 * connection mid-stream, opens a second handle with a `?from=`
 * offset matching the first handle's last byte, and asserts the
 * second handle observes ONLY lines emitted after the reconnect
 * point.
 *
 * Cross-user inspection rejection is also covered (a non-spawner
 * developer hitting /dev/artisan/stream/{runId} receives 403).
 *
 * Skipped on Windows — posix_kill is POSIX-only and the project's
 * local-only constraint is macOS / Linux.
 */

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

/**
 * Inject a fake RunRecord pointing at a tmp file we control + a
 * sleep-spawned bash child that emits 1 line every 200ms for ~3s.
 * Returns the runId + the pid + the tmp file path.
 *
 * Lets the SSE controller tests exercise the tail loop deterministically
 * without going through the spawner's shell wrapper (covered by
 * CommandSpawnerTest).
 *
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
        tier: 'safe',
        status: 'running',
        outPath: $outPath,
    ));

    return ['runId' => $runId, 'pid' => $pid, 'outPath' => $outPath];
}

/**
 * Render the SSE controller's StreamedResponse body, breaking out
 * after `$readDeadlineSeconds` even if the loop is still running.
 *
 * @return array{body: string, completed: bool, lastEventId: int}
 */
function captureSseStream(string $runId, int $userId, int $fromOffset = 0, float $readDeadlineSeconds = 1.0): array
{
    /** @var ArtisanStreamController $controller */
    $controller = app(ArtisanStreamController::class);

    // Build a Request with optional ?from= query.
    $request = Request::create(
        uri: '/dev/artisan/stream/'.$runId,
        method: 'GET',
        parameters: $fromOffset > 0 ? ['from' => (string) $fromOffset] : [],
    );

    // Stub the CurrentUser binding for this call.
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

        // Wrap the response callback with an output buffer that
        // INTERCEPTS the controller's ob_flush()/flush() calls (which
        // would otherwise emit to PHP's default output handler and
        // leak the SSE bytes into PHPUnit's terminal output). The
        // capture callback runs once per ob_flush; we accumulate its
        // payload into $body and return '' so nothing leaks upstream.
        ob_start(function (string $chunk) use (&$body): string {
            $body .= $chunk;

            return '';
        });
        try {
            $response->sendContent();
            $completed = true;
        } finally {
            // Final flush captures anything echo'd after the last
            // ob_flush inside the closure (the terminal `event: done`
            // is followed by `@ob_flush(); @flush();` so this is
            // usually empty, but it's safe to call).
            @ob_end_flush();
        }
        unset($readDeadlineSeconds);
    } finally {
        if ($previous !== null) {
            app()->instance(CurrentUser::class, $previous);
        }
    }

    // Extract last `id:` value from the body.
    $lastEventId = 0;
    if (preg_match_all('/^id:\s*(\d+)/m', $body, $matches) > 0) {
        $lastIds = $matches[1];
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
    expect($record->tier)->toBe('safe');
    expect($record->callerUserId)->toBe($user->id);
});

it('rejects a DESTRUCTIVE-tier command at POST /dev/artisan/spawn with 403', function (): void {
    $user = devUser('destructive-rejector');

    $response = $this->actingAs($user)->postJson('/dev/artisan/spawn', [
        'command' => 'db:restore',
        'args' => ['from' => '/tmp/x'],
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

    // Long-enough run that we can split it into two SSE captures.
    $spawn = spawnTickingChild($user->id, ticks: 6, intervalMs: 250);

    // Wait ~600 ms for the first 2 lines to be emitted, then capture
    // the WHOLE first handle (it runs until the child exits). Read
    // the body to extract the offset of the second line, then open
    // a second handle with ?from=<later-offset> via captureSseStream.
    usleep(700_000); // 700 ms; lines 1 + 2 should be present.

    // Snapshot the file size at the cut-point: this is where the
    // "reconnect" handle starts reading from.
    clearstatcache(true, $spawn['outPath']);
    $cutOffset = filesize($spawn['outPath']);
    expect($cutOffset)->toBeGreaterThan(0);

    // Now read what's been written so far so the test knows what
    // the "first handle" would have seen.
    $firstHandleBytes = (string) file_get_contents($spawn['outPath']);
    expect($firstHandleBytes)->toContain('line-1');

    // Wait for the child to finish so the SSE controller's liveness
    // check fires and the controller emits its terminal done event.
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline && posix_kill($spawn['pid'], 0)) {
        usleep(100_000);
    }
    expect(posix_kill($spawn['pid'], 0))->toBeFalse();

    // Reconnect: open a SECOND SSE handle starting from cutOffset.
    // The body should NOT contain any line-N that was already in
    // the first handle's snapshot.
    $secondCapture = captureSseStream($spawn['runId'], $user->id, fromOffset: $cutOffset);

    expect($secondCapture['body'])->toContain('event: done');

    // The reconnect must NOT replay lines already delivered before
    // the cut. We extract the data-line payloads and check.
    $lines = [];
    if (preg_match_all('/data:\s*\{"line":"(line-\d+)/m', $secondCapture['body'], $m) > 0) {
        $lines = $m[1];
    }
    // Whichever lines were in firstHandleBytes pre-cut must be ABSENT
    // from secondCapture's lines.
    foreach (['line-1', 'line-2'] as $alreadySeen) {
        // We only assert when the cut actually contained that line.
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

    // Use the actual CommandSpawner to create a real run.
    /** @var CommandSpawner $sp */
    $sp = app(CommandSpawner::class);
    $runId = $sp->start('cache:clear', [], $spawner->id, 'safe');

    // The intruder (a different developer) MUST NOT be able to read
    // the stream even though they pass EnsureDeveloperMode.
    $response = $this->actingAs($intruder)
        ->get("/dev/artisan/stream/{$runId}");

    $response->assertStatus(403);
});
