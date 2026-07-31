<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Illuminate\Support\Str;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandRunAudit;
use Modules\DevMode\Public\Dto\CommandSpec;
use Modules\DevMode\Public\Exceptions\SpawnProcessException;
use Symfony\Component\Process\Process;

/**
 * @link ../../../../.docs/features/dev-mode/architecture.md
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

        $this->registry->store(new RunRecord(
            runId: $runId,
            pid: $pid,
            command: $command,
            args: $args,
            startedAt: $startedAt,
            callerUserId: $callerUserId,
            tier: $tier,
            status: 'running',
            outPath: $outPath,
        ));

        // Eager audit row (exit_code=null, finished_at=null) so the
        // timeline reflects the spawn immediately. FinalizeRunAudit
        // updates this same row in place via properties.run_id when the
        // stream's done event fires.
        $this->audit->recordCommandRun(new CommandRunAudit(
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
        ));

        return $runId;
    }

    // Every interpolated value is escapeshellarg'd, including $command
    // (already whitelist-enforced by find()) for belt-and-braces in case
    // a future registry entry contains a shell metacharacter.
    /**
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

    // Positional args come first in $spec->argsSchema order; option args
    // (name begins with `--`) emit as `--name=value`. Boolean args render
    // as the literal `--name` flag when truthy, omitted otherwise.
    /**
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
            return $this->renderBooleanArg($argSpec, $value, $isOption);
        }

        $stringValue = is_scalar($value) ? (string) $value : '';

        // Options escapeshellarg the entire `name=value` string as one
        // unit so the argv item reaches artisan intact, not split or
        // doubled by a mid-string quote; positionals escape the value.
        return $isOption
            ? [escapeshellarg($argSpec->name.'='.$stringValue)]
            : [escapeshellarg($stringValue)];
    }

    // Boolean args render as the bare `--name` flag only when both truthy
    // and an option; a truthy positional or any falsy value emits nothing.
    /**
     * @return list<string>
     */
    private function renderBooleanArg(ArgSpec $argSpec, mixed $value, bool $isOption): array
    {
        $truthy = $value === true || $value === 'true' || $value === 1 || $value === '1';

        return $truthy && $isOption ? [escapeshellarg($argSpec->name)] : [];
    }

    // The bash wrapper has already detached the child, so this returns
    // within ms with the child's PID on stdout.
    private function spawnDetached(string $shellCommand): int
    {
        $cwd = UserDataPathService::projectPath();

        $process = Process::fromShellCommandline($shellCommand, $cwd);
        $process->setTimeout(5.0);
        $process->run();

        if (! $process->isSuccessful()) {
            throw SpawnProcessException::bashWrapperFailed(trim($process->getErrorOutput()));
        }

        $pidLine = trim($process->getOutput());
        if ($pidLine === '' || preg_match('/^\d+$/', $pidLine) !== 1) {
            throw SpawnProcessException::pidUncapturable($pidLine);
        }

        return (int) $pidLine;
    }

    // Walks one level at a time so every intermediate gets mode 0700 (not
    // PHP's default 0755) — on a shared multi-user host the per-run UUID
    // filenames would otherwise be world-readable through the parent
    // path. The mkdir()||is_dir() check keeps concurrent spawns race-safe.
    private function ensureRunsDirectory(string $dir): void
    {
        $parent = dirname($dir);
        foreach ([$parent, $dir] as $path) {
            if (is_dir($path)) {
                continue;
            }
            if (! @mkdir($path, 0700, false) && ! is_dir($path)) {
                throw SpawnProcessException::runsDirectoryUnwritable($path);
            }
            @chmod($path, 0700);
        }
    }
}
