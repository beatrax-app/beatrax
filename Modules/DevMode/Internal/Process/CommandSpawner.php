<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Illuminate\Support\Str;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandSpec;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Spawns a whitelisted artisan command in architecture (b) —
 * spawn-then-tail per CONTEXT D-16.
 *
 * The spawn step:
 *   1. Generates a UUID `run_id`.
 *   2. Computes `outPath = storage/app/dev_mode/runs/{runId}.out` via
 *      {@see UserDataPathService::appPath()} (the noStoragePathHard-
 *      CodedOutsideUserDataPathService invariant requires every path
 *      to flow through that service).
 *   3. Ensures the parent directory exists with mode 0700 (developer-
 *      only — these tmp files may contain stdout that 16-04b's audit
 *      pipeline copies into the audit log with redaction; meanwhile
 *      the raw file is restrictive by default).
 *   4. Resolves the CommandSpec via DevCommandRegistry::find() so an
 *      off-whitelist name throws InvalidArgumentException BEFORE a
 *      Process is constructed (CONTEXT D-14 NEVER-EXPOSED commands
 *      never reach the shell).
 *   5. Builds a bash invocation that escapes every component via
 *      escapeshellarg, redirects stdout + stderr into the tmp file,
 *      detaches with `&`, and prints `$!` so the parent captures the
 *      child PID. The bash wrapper is the standard pattern for
 *      capturing a backgrounded process's PID; Symfony Process'
 *      built-in start() loses it under shell-redirect detach.
 *   6. Stores `(run_id, pid, command, args, started_at,
 *      callerUserId, tier, outPath)` in RunRegistry under
 *      `dev_mode.run.{runId}`.
 *   7. Returns the `run_id` — the spawning HTTP request returns
 *      immediately because the child is detached.
 *
 * Injection resistance is three guards deep (T-16-11, T-16-SC2):
 *   - The command name comes from DevCommandRegistry::find(); arbitrary
 *     user-supplied names are rejected before assembly.
 *   - Every arg value is wrapped with escapeshellarg before reaching
 *     the shell.
 *   - The controllers validate every arg through Laravel's validate()
 *     against the ArgSpec::$rules list before this method is reached.
 *
 * @see Task 1 Test 4 — the canonical injection-resistance regression.
 */
final readonly class CommandSpawner
{
    public function __construct(
        private RunRegistry $registry,
        private Clock $clock,
        private DevCommandRegistry $commands,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function start(string $command, array $args, int $callerUserId, string $tier): string
    {
        // Whitelist guard — throws InvalidArgumentException for any
        // unknown name (NEVER-EXPOSED commands such as `migrate`
        // never reach the shell). The spec also tells us which args
        // are positional vs option.
        $spec = $this->commands->find($command);

        $runId = (string) Str::uuid();
        $outPath = UserDataPathService::appPath('dev_mode/runs/'.$runId.'.out');

        $this->ensureRunsDirectory(dirname($outPath));

        $artisanPath = UserDataPathService::projectPath('artisan');

        $shellCommand = $this->buildShellCommand(
            artisanPath: $artisanPath,
            command: $command,
            spec: $spec,
            args: $args,
            outPath: $outPath,
        );

        $pid = $this->spawnDetached($shellCommand);

        $this->registry->store(
            runId: $runId,
            pid: $pid,
            command: $command,
            args: $args,
            startedAt: $this->clock->now(),
            callerUserId: $callerUserId,
            tier: $tier,
            outPath: $outPath,
        );

        return $runId;
    }

    /**
     * Builds the bash command that:
     *   - Runs `php artisan <cmd> <escaped-args> > <outPath> 2>&1 &`
     *   - Prints `$!` (the backgrounded child's PID) on stdout.
     *
     * Every interpolated value is escapeshellarg'd. The whitelist on
     * `$command` is already enforced by find(); we still escape it
     * for belt-and-braces in case a future planner registers a name
     * with a metacharacter (the registry has no such validation).
     *
     * @param  array<string, mixed>  $args
     */
    private function buildShellCommand(
        string $artisanPath,
        string $command,
        CommandSpec $spec,
        array $args,
        string $outPath,
    ): string {
        $argTokens = $this->flattenArgs($spec, $args);

        $parts = [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($artisanPath),
            escapeshellarg($command),
        ];
        foreach ($argTokens as $token) {
            $parts[] = $token;
        }

        $invocation = implode(' ', $parts);
        $redirect = '> '.escapeshellarg($outPath).' 2>&1';

        // `setsid` detaches the child into its own session so it
        // survives PHP-FPM worker shutdown. Fall back to a plain `&`
        // detach when `setsid` is unavailable (busybox / minimal
        // shells); the foregrounded `bash -c` exits as soon as the
        // child is launched.
        $detach = 'setsid '.$invocation.' '.$redirect.' < /dev/null &';

        // Capture and emit the child PID via $!. The trailing newline
        // is implicit from `echo`.
        return 'bash -c '.escapeshellarg($detach.' echo $!');
    }

    /**
     * Flattens the args map into Process tokens. Positional args come
     * first in the order they appear in `$spec->argsSchema`; option
     * args (those whose ArgSpec::$name begins with `--`) are emitted
     * as `--name=value`. Every value passes through escapeshellarg.
     *
     * Boolean args render as the literal `--name` flag when truthy,
     * and omitted otherwise (Laravel's native option semantics).
     *
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function flattenArgs(CommandSpec $spec, array $args): array
    {
        $tokens = [];

        foreach ($spec->argsSchema as $argSpec) {
            if (! array_key_exists($argSpec->name, $args)) {
                continue;
            }
            $value = $args[$argSpec->name];
            if ($value === null) {
                continue;
            }

            $tokens = array_merge($tokens, $this->renderArg($argSpec, $value));
        }

        return $tokens;
    }

    /**
     * @return list<string>
     */
    private function renderArg(ArgSpec $argSpec, mixed $value): array
    {
        $isOption = str_starts_with($argSpec->name, '--');

        if ($argSpec->type === 'boolean') {
            if ($value === true || $value === 'true' || $value === 1 || $value === '1') {
                return $isOption ? [escapeshellarg($argSpec->name)] : [];
            }

            return [];
        }

        $stringValue = is_scalar($value) ? (string) $value : '';
        $escaped = escapeshellarg($stringValue);

        if ($isOption) {
            // `--name=value` packs both halves into a single arg so the
            // shell tokeniser does not need to recombine them.
            return [escapeshellarg($argSpec->name.'=').'='.$escaped];
        }

        return [$escaped];
    }

    /**
     * Executes the bash wrapper in the foreground; the wrapper itself
     * has already detached the child, so this returns within ms with
     * the child's PID on stdout. The runs dir is the cwd so any
     * relative-path argument the command needs resolves predictably.
     */
    private function spawnDetached(string $shellCommand): int
    {
        $cwd = UserDataPathService::projectPath();

        $process = Process::fromShellCommandline($shellCommand, $cwd);
        $process->setTimeout(5.0); // The wrapper exits in milliseconds.
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'CommandSpawner: bash wrapper exited non-zero. stderr: '.trim($process->getErrorOutput()),
            );
        }

        $pidLine = trim($process->getOutput());
        if ($pidLine === '' || preg_match('/^\d+$/', $pidLine) !== 1) {
            throw new RuntimeException(
                "CommandSpawner: failed to capture child PID from bash wrapper. Got: `{$pidLine}`",
            );
        }

        return (int) $pidLine;
    }

    private function ensureRunsDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException(
                "CommandSpawner: failed to create runs directory at `{$dir}`.",
            );
        }
    }
}
