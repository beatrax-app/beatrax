<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Internal\Enums\ArgType;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\FileTailer;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;

beforeEach(function (): void {
    $runsDir = UserDataPathService::appPath('dev_mode/runs');
    if (is_dir($runsDir)) {
        foreach (glob($runsDir.'/*.out') ?: [] as $file) {
            @unlink($file);
        }
    }
});

// A real `php artisan` boot, waited on by wall clock: it measures 2.5-4s with
// nothing else running, and the suite runs four workers at once. The number
// only bounds how long a child that never exits is tolerated, so it is set to
// survive that load rather than to be tight.
function awaitProcessExit(int $pid, float $timeoutSeconds = 30.0): bool
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

    $runId = $spawner->start('cache:clear', [], 99, CommandTier::Safe);

    expect($runId)->toBeString();
    expect(PatternScan::matches('/^[0-9a-f-]{36}$/', $runId))->toBeTrue();

    $record = $registry->find($runId);
    expect($record)->not->toBeNull();
    expect($record->command)->toBe('cache:clear');
    expect($record->tier)->toBe(CommandTier::Safe);
    expect($record->callerUserId)->toBe(99);
    expect($record->pid)->toBeGreaterThan(0);
    expect($record->outPath)->toContain('/dev_mode/runs/'.$runId.'.out');

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

    // The payload closes the quote and touches a file outside the
    // spawner-controlled tree, so its existence afterwards is the breach.
    $sentinelPath = sys_get_temp_dir().'/PWNED-'.bin2hex(random_bytes(6));
    $maliciousArg = "/tmp/'; touch ".$sentinelPath."; '";

    @unlink($sentinelPath);
    expect(is_file($sentinelPath))->toBeFalse();

    // db:restore failing on a nonexistent backup is the correct outcome: the
    // arg must arrive as inert path data, not as something the shell ran.
    $runId = $spawner->start('db:restore', ['path' => $maliciousArg], 99, CommandTier::Destructive);

    $record = $registry->find($runId);
    expect($record)->not->toBeNull();

    expect(awaitProcessExit($record->pid))->toBeTrue();

    clearstatcache(true, $sentinelPath);
    expect(is_file($sentinelPath))->toBeFalse();
});

it('FileTailer returns new bytes when the file grows between calls', function (): void {
    /** @var FileTailer $tailer */
    $tailer = app(FileTailer::class);

    $tmpFile = tempnam(sys_get_temp_dir(), 'tailer-test-');
    expect($tmpFile)->toBeString();

    try {
        file_put_contents($tmpFile, "line-1\n");
        $first = $tailer->tailOnce($tmpFile, 0);
        expect($first['chunk'])->toBe("line-1\n");
        expect($first['newOffset'])->toBe(7);

        $second = $tailer->tailOnce($tmpFile, $first['newOffset']);
        expect($second['chunk'])->toBe('');
        expect($second['newOffset'])->toBe(7);

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

    $runId = $spawner->start('beatrax:failed-jobs', ['action' => 'prune'], 99, CommandTier::Safe);

    $record = $registry->find($runId);
    expect($record)->not->toBeNull();

    expect(awaitProcessExit($record->pid))->toBeTrue();

    clearstatcache(true, $record->outPath);
    $captured = is_file($record->outPath) ? (string) file_get_contents($record->outPath) : '';

    // Regression: the name once rendered as one shell arg containing a space,
    // `beatrax:failed-jobs prune`, and Symfony Console answered "Command ...
    // is not defined". That string is the tripwire.
    expect($captured)->not->toContain('is not defined');
    expect($captured)->not->toContain('Unknown action');
});

it('renders an --option=value arg as a single shell-safe argv token (no doubled `=`)', function (): void {
    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);

    // Reflection pins the exact token shape without a live child. The bug
    // built `'--queue=' . '=' . 'default'`, which the shell tokenised as
    // `--queue==default` and artisan read as the value `=default`.
    $argSpec = new ArgSpec(
        name: '--queue',
        labelKey: 'dev::runner.arg.queue.label',
        type: ArgType::Text,
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

    expect($token)->toBe(escapeshellarg('--queue=high'));

    // Stripping the outer quotes reproduces what the shell hands to artisan.
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

    // queue:retry is the only --option-bearing SAFE-tier entry, so it is the
    // only end-to-end proof available for the doubled-`=` bug.
    $runId = $spawner->start('queue:retry', ['id' => 'all', '--queue' => 'high'], 99, CommandTier::Safe);

    $record = $registry->find($runId);
    expect($record)->not->toBeNull();

    expect(awaitProcessExit($record->pid))->toBeTrue();

    clearstatcache(true, $record->outPath);
    $captured = is_file($record->outPath) ? (string) file_get_contents($record->outPath) : '';

    // Artisan naming a queue that starts with `=` is only possible from a
    // literal `--queue==high` token.
    expect($captured)->not->toContain('[=high]');
    expect($captured)->not->toContain('==high');
});

it('FileTailer returns empty + unchanged offset for a missing or truncated file', function (): void {
    /** @var FileTailer $tailer */
    $tailer = app(FileTailer::class);

    $absent = sys_get_temp_dir().'/never-exists-'.bin2hex(random_bytes(6));
    $missing = $tailer->tailOnce($absent, 100);
    expect($missing['chunk'])->toBe('');
    expect($missing['newOffset'])->toBe(100);

    // 99_999 stands in for an offset stranded past a truncation.
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
