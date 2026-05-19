<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Shared `Probe` implementation that runs an external CLI tool with
 * `--version` (or an equivalent argv tail) and surfaces the stdout
 * trim as an `ok` `ProbeResult`. Failure modes — binary not on PATH,
 * non-zero exit, empty stdout — surface as a `warning` so the
 * doctor command's exit-code aggregator routes them to the 1-exit-
 * warning slot rather than the 2-exit-blocker slot. External tooling
 * being missing is operationally relevant but not load-bearing for
 * the local dashboard's runtime (the project has no Composer dep on
 * `composer` itself at runtime, and Node only matters for asset
 * builds), so warning is the honest severity.
 *
 * Sub-classes provide `$label`, the argv tuple to invoke, and the
 * warning message that explains what the operator loses without the
 * tool. The 5-second timeout matches the legacy inline `reportTool()`
 * helper this probe family replaces.
 */
abstract class ExternalToolVersionProbe implements Probe
{
    private const TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly string $label,
        /** @var list<string> */
        private readonly array $argv,
        private readonly string $missingMessage,
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    public function run(): ProbeResult
    {
        [$version, $available] = $this->runVersion($this->argv);

        if ($available) {
            return new ProbeResult('ok', $version, ['version' => $version]);
        }

        return new ProbeResult(
            'warning',
            sprintf('%s (%s)', $version, $this->missingMessage),
            ['stderr' => $version],
        );
    }

    /**
     * Executes the argv and returns `[output-string, success-flag]`.
     * On success the version string is read from stdout — chatty
     * deprecation notices on stderr no longer leak into the result.
     * On failure the stderr trim is returned as the diagnostic; an
     * empty stderr is replaced with `(not available)` so the operator
     * still gets a non-empty hint.
     *
     * @param  list<string>  $argv
     * @return array{0: string, 1: bool}
     */
    private function runVersion(array $argv): array
    {
        $process = new Process($argv);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (Throwable) {
            return ['(not available)', false];
        }

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            return [$stderr === '' ? '(not available)' : $stderr, false];
        }

        $stdout = trim($process->getOutput());

        return [$stdout === '' ? '(empty)' : $stdout, true];
    }
}
