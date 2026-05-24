<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\FileTailer;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;

/*
 * CommandSpawner architecture-(b) shell-redirect + escapeshellarg
 * invariants.
 *
 * The spawner detaches via `bash -c '... > out 2>&1 < /dev/null &'`
 * so the foregrounded HTTP request returns within milliseconds; the
 * child writes its full stdout + stderr into a per-run tmp file the
 * SSE controller tails via fseek + clearstatcache.
 *
 * The injection-resistance regression below attempts a classic
 * shell-metachar payload as the `from` arg of db:restore and asserts
 * the spawner-controlled tree is unmodified (PWNED side-effect file
 * never created) even though the spawn call succeeds. The three
 * independent guards (whitelist + escape + validate) ensure the
 * injection attempt becomes inert path data that the artisan command
 * rejects as a missing file.
 *
 * The option-token regression below covers the `--name=value` shape:
 * a single `--queue=high` argv item must reach artisan unchanged so
 * the option parser splits on the first `=` and the value is `high`,
 * not `=high` or `==high`.
 *
 * Skipped on Windows (`posix_kill` is POSIX-only); the project's
 * local-only constraint is macOS so this is fine.
 */

beforeEach(function (): void {
    $runsDir = UserDataPathService::appPath('dev_mode/runs');
    if (is_dir($runsDir)) {
        foreach (glob($runsDir.'/*.out') ?: [] as $file) {
            @unlink($file);
        }
    }
});

/**
 * Wait for a backgrounded child to finish, polling via posix_kill(pid, 0).
 * Returns true if the process exited inside the timeout; false otherwise.
 */
function awaitProcessExit(int $pid, float $timeoutSeconds = 6.0): bool
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        if (! posix_kill($pid, 0)) {
            return true;
        }
        usleep(50_000);
    }

    return false;
}

it('spawns cache:clear and writes stdout into a per-run tmp file', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);
    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);

    $runId = $spawner->start('cache:clear', [], 99, 'safe');

    expect($runId)->toBeString();
    expect(preg_match('/^[0-9a-f-]{36}$/', $runId))->toBe(1);

    $record = $registry->find($runId);
    expect($record)->not->toBeNull();
    expect($record->command)->toBe('cache:clear');
    expect($record->tier)->toBe('safe');
    expect($record->callerUserId)->toBe(99);
    expect($record->pid)->toBeGreaterThan(0);
    expect($record->outPath)->toContain('/dev_mode/runs/'.$runId.'.out');

    // Wait for the child to finish then assert the tmp file is non-empty.
    expect(awaitProcessExit($record->pid))->toBeTrue();

    clearstatcache(true, $record->outPath);
    expect(is_file($record->outPath))->toBeTrue();
    expect(filesize($record->outPath))->toBeGreaterThan(0);
});

it('rejects an injection-attempt path via escapeshellarg discipline', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);
    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);

    // Classic injection sentinel: if escapeshellarg fails to neutralise
    // the metachars, a shell evaluating this string would touch a
    // sentinel file outside the spawner-controlled tree.
    $sentinelPath = sys_get_temp_dir().'/PWNED-'.bin2hex(random_bytes(6));
    $maliciousArg = "/tmp/'; touch ".$sentinelPath."; '";

    // Pre-check the sentinel does not exist.
    @unlink($sentinelPath);
    expect(is_file($sentinelPath))->toBeFalse();

    // Spawn db:restore with the malicious path. The artisan command
    // itself will fail (the file does not exist as a SQLite backup),
    // but that is the CORRECT behaviour — the spawner must hand the
    // arg through as inert data, not let the shell evaluate it.
    $runId = $spawner->start('db:restore', ['from' => $maliciousArg], 99, 'destructive');

    $record = $registry->find($runId);
    expect($record)->not->toBeNull();

    // Wait for the child to finish (artisan command will error out fast).
    expect(awaitProcessExit($record->pid))->toBeTrue();

    // The CRITICAL assertion: the sentinel was NEVER created. The shell
    // metachars in the path were neutralised by escapeshellarg before
    // the bash wrapper saw them.
    clearstatcache(true, $sentinelPath);
    expect(is_file($sentinelPath))->toBeFalse();
});

it('FileTailer returns new bytes when the file grows between calls', function (): void {
    /** @var FileTailer $tailer */
    $tailer = app(FileTailer::class);

    $tmpFile = tempnam(sys_get_temp_dir(), 'tailer-test-');
    expect($tmpFile)->toBeString();

    try {
        // First write + tail.
        file_put_contents($tmpFile, "line-1\n");
        $first = $tailer->tailOnce($tmpFile, 0);
        expect($first['chunk'])->toBe("line-1\n");
        expect($first['newOffset'])->toBe(7);

        // Second tail from the same offset — file has not grown, expect empty.
        $second = $tailer->tailOnce($tmpFile, $first['newOffset']);
        expect($second['chunk'])->toBe('');
        expect($second['newOffset'])->toBe(7);

        // Append + tail again.
        file_put_contents($tmpFile, "line-2\n", FILE_APPEND);
        $third = $tailer->tailOnce($tmpFile, $first['newOffset']);
        expect($third['chunk'])->toBe("line-2\n");
        expect($third['newOffset'])->toBe(14);
    } finally {
        @unlink($tmpFile);
    }
});

it('spawns beatrax:failed-jobs with the positional action arg and the artisan command resolves cleanly', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);
    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);

    $runId = $spawner->start('beatrax:failed-jobs', ['action' => 'prune'], 99, 'safe');

    $record = $registry->find($runId);
    expect($record)->not->toBeNull();

    expect(awaitProcessExit($record->pid))->toBeTrue();

    clearstatcache(true, $record->outPath);
    $captured = is_file($record->outPath) ? (string) file_get_contents($record->outPath) : '';

    // The smoking gun for the original bug: if the command name was
    // rendered as the literal token `beatrax:failed-jobs prune`
    // (one shell arg containing a space) Symfony Console would emit
    // "Command ... is not defined". With the corrected name +
    // positional action arg the command resolves cleanly — the
    // captured stdout never contains that error string regardless
    // of what the child's own DB connection finds in failed_jobs.
    expect($captured)->not->toContain('is not defined');
    expect($captured)->not->toContain('Unknown action');
});

it('renders an --option=value arg as a single shell-safe argv token (no doubled `=`)', function (): void {
    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);

    // Reach into the private renderArg() via reflection so the test
    // pins the exact shell-token shape without depending on a live
    // child process. The bug fixed here produced
    // `'--queue=' . '=' . 'default'` which the shell tokenised into
    // `--queue==default` — artisan then parsed the value as `=default`.
    $argSpec = new ArgSpec(
        name: '--queue',
        label: 'Queue name',
        type: 'text',
        rules: ['string'],
    );

    $reflection = new ReflectionClass(CommandSpawner::class);
    $method = $reflection->getMethod('renderArg');
    $method->setAccessible(true);

    $tokens = $method->invoke($spawner, $argSpec, 'high');

    expect($tokens)->toBeArray();
    expect($tokens)->toHaveCount(1);

    $token = $tokens[0];
    expect($token)->toBeString();

    // The expected single-arg shape: escapeshellarg('--queue=high')
    // wraps the whole `name=value` pair in shell-safe quoting.
    expect($token)->toBe(escapeshellarg('--queue=high'));

    // After the shell tokeniser strips the outer single-quotes, the
    // argv item is exactly `--queue=high` — never `--queue==high`.
    $unquoted = trim($token, "'");
    expect($unquoted)->toBe('--queue=high');
    expect($unquoted)->not->toContain('==');
});

it('forwards an --option=value pair to a spawned artisan command intact', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);
    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);

    // queue:retry --queue=high is the only --option-bearing SAFE-tier
    // entry. With the bug present the argv reached artisan as
    // `--queue==high` and artisan emitted "Queue [=high] is not
    // defined" (or the equivalent for the failed-jobs query). With
    // the fix the argv item is `--queue=high` and artisan emits the
    // standard "No failed jobs found" / "Unable to find job" body,
    // which contains neither `[=high]` nor `==high`.
    $runId = $spawner->start('queue:retry', ['id' => 'all', '--queue' => 'high'], 99, 'safe');

    $record = $registry->find($runId);
    expect($record)->not->toBeNull();

    expect(awaitProcessExit($record->pid))->toBeTrue();

    clearstatcache(true, $record->outPath);
    $captured = is_file($record->outPath) ? (string) file_get_contents($record->outPath) : '';

    // The smoking gun would be artisan complaining about a queue
    // whose name begins with `=` — only possible when the spawner
    // hands artisan a literal `--queue==high` token.
    expect($captured)->not->toContain('[=high]');
    expect($captured)->not->toContain('==high');
});

it('FileTailer returns empty + unchanged offset for a missing or truncated file', function (): void {
    /** @var FileTailer $tailer */
    $tailer = app(FileTailer::class);

    // Missing file.
    $absent = sys_get_temp_dir().'/never-exists-'.bin2hex(random_bytes(6));
    $missing = $tailer->tailOnce($absent, 100);
    expect($missing['chunk'])->toBe('');
    expect($missing['newOffset'])->toBe(100);

    // Truncated file (size < fromOffset).
    $tmpFile = tempnam(sys_get_temp_dir(), 'tailer-trunc-');
    try {
        file_put_contents($tmpFile, 'short');
        $truncated = $tailer->tailOnce($tmpFile, 99_999);
        expect($truncated['chunk'])->toBe('');
        expect($truncated['newOffset'])->toBe(99_999);
    } finally {
        @unlink($tmpFile);
    }
});
