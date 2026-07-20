<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * @link ../../../../../.docs/features/core/architecture.md
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

    // On success the version string is read from stdout, so chatty
    // deprecation notices on stderr never leak into the result. On failure
    // the stderr trim is the diagnostic; empty stderr becomes "(not
    // available)" so the operator still gets a non-empty hint.
    /**
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
