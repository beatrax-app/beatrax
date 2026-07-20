<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Illuminate\Support\Str;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandSpec;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Spawns a whitelisted artisan command via the spawn-then-tail
 * architecture: the HTTP request that calls start() returns within
 * milliseconds while the artisan child runs detached and writes
 * stdout/stderr into a per-run tmp file the SSE stream controller
 * tails for the browser.
 *
 * The spawn step:
 *   1. Generates a UUID run_id.
 *   2. Computes outPath = storage/app/dev_mode/runs/{runId}.out via
 *      {@see UserDataPathService::appPath()}. Every storage path
 *      flows through that service so a NativePHP retarget is honoured.
 *   3. Ensures the parent directory exists with mode 0700 (developer-
 *      only). The audit pipeline copies tmp-file contents into the
 *      audit log with redaction; the raw file stays restrictive on
 *      disk regardless.
 *   4. Resolves the CommandSpec via DevCommandRegistry::find() so an
 *      off-whitelist name throws InvalidArgumentException BEFORE a
 *      Process is constructed. NEVER-EXPOSED commands such as
 *      migrate / migrate:rollback / db:seed are absent from the
 *      registry and never reach the shell.
 *   5. Builds a bash invocation that escapes every component via
 *      escapeshellarg, redirects stdout + stderr into the tmp file,
 *      detaches with `&`, and prints `$!` so the parent captures the
 *      child PID. The bash wrapper is the standard pattern for
 *      capturing a backgrounded process's PID; Symfony Process'
 *      built-in start() loses it under shell-redirect detach.
 *   6. Stores (run_id, pid, command, args, started_at,
 *      callerUserId, tier, outPath) in RunRegistry under
 *      dev_mode.run.{runId}.
 *   7. Returns the run_id — the spawning HTTP request returns
 *      immediately because the child is detached.
 *
 * Injection resistance is three guards deep:
 *   - The command name comes from DevCommandRegistry::find();
 *     arbitrary user-supplied names are rejected before assembly.
 *   - Every arg value is wrapped with escapeshellarg before reaching
 *     the shell.
 *   - The controllers validate every arg through Laravel's
 *     validate() against the ArgSpec::$rules list before this method
 *     is reached.
 */
final readonly class CommandSpawner
{
    public function __construct(
        private RunRegistry $registry,
        private Clock $clock,
        private DevCommandRegistry $commands,
        private AuditWriter $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function start(string $command, array $args, int $callerUserId, string $tier): string
    {
        // Whitelist guard — throws InvalidArgumentException for any
        // unknown name (NEVER-EXPOSED commands such as `migrate`
        // never reach the shell). The spec also tells us which args
        // are positional vs option (the leading `--` discriminator).
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

        $startedAt = $this->clock->now();

        $this->registry->store(
            runId: $runId,
            pid: $pid,
            command: $command,
            args: $args,
            startedAt: $startedAt,
            callerUserId: $callerUserId,
            tier: $tier,
            outPath: $outPath,
        );

        // Eager audit row (exit_code=null, finished_at=null) so the
        // timeline reflects the spawn immediately. FinalizeRunAudit
        // updates this same row in place via properties.run_id when the
        // stream's done event fires.
        $this->audit->recordCommandRun(
            command: $command,
            args: $args,
            tier: $tier,
            callerUserId: $callerUserId,
            startedAt: $startedAt,
            finishedAt: null,
            exitCode: null,
            stdoutExcerpt: '',
            errorExcerpt: '',
            runId: $runId,
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

        // Plain bash background detach: `&` puts the child in the
        // background; the closed stdin (`< /dev/null`) prevents SIGHUP
        // propagation when the parent HTTP request exits. `setsid` would
        // also work but isn't part of macOS' default toolchain.
        $detach = $invocation.' '.$redirect.' < /dev/null &';

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
            // The entire `name=value` string is escapeshellarg'd as one
            // unit so the resulting argv item reaches artisan intact,
            // not split or doubled by a mid-string quote.
            return [escapeshellarg($argSpec->name.'='.$stringValue)];
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
        $process->setTimeout(5.0);
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

    /**
     * Create the run-tmp directory walk one level at a time so every
     * intermediate gets mode 0700, not the default 0755 PHP applies
     * to mkdir(..., recursive: true) intermediates. A shared host
     * (multi-user macOS) is the threat model: the per-run UUID
     * filenames inside the dir are otherwise world-readable through
     * the parent path even though the individual files are 0600.
     *
     * The walk preserves the race-safe `mkdir() || is_dir()` pattern
     * at every level: two concurrent spawns racing the directory
     * creation both succeed.
     */
    private function ensureRunsDirectory(string $dir): void
    {
        $parent = dirname($dir);
        foreach ([$parent, $dir] as $path) {
            if (is_dir($path)) {
                continue;
            }
            if (! @mkdir($path, 0700, false) && ! is_dir($path)) {
                throw new RuntimeException(
                    "CommandSpawner: failed to create runs directory at `{$path}`.",
                );
            }
            @chmod($path, 0700);
        }
    }
}
