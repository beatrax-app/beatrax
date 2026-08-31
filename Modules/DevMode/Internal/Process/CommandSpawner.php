<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Illuminate\Support\Str;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Enums\ArgType;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Exceptions\ProcessSpawningUnavailableException;
use Modules\DevMode\Internal\Exceptions\SpawnProcessException;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandRunAudit;
use Modules\DevMode\Public\Dto\CommandSpec;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final readonly class CommandSpawner
{
    // $phpBinary is the interpreter the detached child is launched with.
    // Injected rather than read from PHP_BINARY at the call site because the
    // embed SAPI leaves that constant empty, and a spawner with nothing to
    // spawn has to be constructible in a test to be provable.
    public function __construct(
        private RunRegistry $registry,
        private Clock $clock,
        private DevCommandRegistry $commands,
        private AuditWriter $audit,
        private string $phpBinary = PHP_BINARY,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     *
     * @throws ProcessSpawningUnavailableException
     */
    public function start(string $command, array $args, int $callerUserId, CommandTier $tier): string
    {
        // Before the run directory, the registry row and the audit row, so a
        // platform that cannot spawn leaves none of them behind. An empty
        // interpreter would otherwise build `'' artisan cache:clear`, which a
        // shell accepts and reports as a started run that did nothing.
        if ($this->phpBinary === '') {
            throw new ProcessSpawningUnavailableException(
                'No PHP interpreter path is available to spawn a child process with.',
            );
        }

        // Throws on any unregistered name, so a never-exposed command such
        // as `migrate` cannot reach the shell below.
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

        // Deliberately incomplete: FinalizeRunAudit updates this same row in
        // place, keyed on properties.run_id, when the stream reports done.
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

    // $command is escaped too, though find() already vetted it, so a future
    // registry entry carrying a shell metacharacter stays harmless.
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
            escapeshellarg($this->phpBinary),
            escapeshellarg($artisanPath),
            escapeshellarg($command),
        ];
        foreach ($argTokens as $token) {
            $parts[] = $token;
        }

        $invocation = implode(' ', $parts);
        $redirect = '> '.escapeshellarg($outPath).' 2>&1';

        // `< /dev/null` stops the child holding the request's stdin open.
        // setsid would detach more cleanly but is absent from macOS' default
        // toolchain, so plain `&` it is.
        $detach = $invocation.' '.$redirect.' < /dev/null &';

        // The subshell is the child's PARENT, which is the only process that
        // can be told its exit code. `$!` is still the artisan pid, so cancel
        // signals and liveness probes reach the command itself; `exec 1>&-`
        // hands the caller EOF so the pid is read without waiting for the run.
        $watch = '( '.$detach.' p=$!; echo $p; exec 1>&-; '
            .'wait $p; echo $? > '.escapeshellarg(RunExitCodeFile::pathFor($outPath)).' ) &';

        return 'bash -c '.escapeshellarg($watch);
    }

    // Tokens emit in $spec->argsSchema order, so a schema that lists an
    // option before a positional produces that order on the command line.
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

        if ($argSpec->type === ArgType::Boolean) {
            return $this->renderBooleanArg($argSpec, $value, $isOption);
        }

        $stringValue = is_scalar($value) ? (string) $value : '';

        // An option escapes `name=value` as one unit, so a mid-string quote
        // cannot split it into two argv items.
        return $isOption
            ? [escapeshellarg($argSpec->name.'='.$stringValue)]
            : [escapeshellarg($stringValue)];
    }

    /**
     * @return list<string>
     */
    private function renderBooleanArg(ArgSpec $argSpec, mixed $value, bool $isOption): array
    {
        $truthy = $value === true || $value === 'true' || $value === 1 || $value === '1';

        return $truthy && $isOption ? [escapeshellarg($argSpec->name)] : [];
    }

    private function spawnDetached(string $shellCommand): int
    {
        $cwd = UserDataPathService::projectPath();

        $process = Process::fromShellCommandline($shellCommand, $cwd);
        $process->setTimeout(5.0);

        try {
            $process->run();
        } catch (ProcessStartFailedException $e) {
            // proc_open() is present and the sandbox refuses the fork behind
            // it, which is a property of the host and not of this command:
            // "PHP Startup: Fork failed: Operation not permitted".
            throw new ProcessSpawningUnavailableException(
                sprintf('The runtime refused to start a child process: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        if (! $process->isSuccessful()) {
            throw SpawnProcessException::bashWrapperFailed(trim($process->getErrorOutput()));
        }

        $pidLine = trim($process->getOutput());
        if ($pidLine === '' || preg_match('/^\d+$/', $pidLine) !== 1) {
            throw SpawnProcessException::pidUncapturable($pidLine);
        }

        return (int) $pidLine;
    }

    // One level at a time so every intermediate gets 0700 rather than PHP's
    // 0755, which would leave the per-run output world-readable through the
    // parent. The mkdir()||is_dir() pair keeps concurrent spawns race-safe.
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
