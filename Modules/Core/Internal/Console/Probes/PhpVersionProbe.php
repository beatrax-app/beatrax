<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class PhpVersionProbe implements Probe
{
    private const MIN_PHP = '8.5';

    // The minimum is injectable (default = the shipped floor) purely so the
    // below-minimum path can be driven under test; production resolves it
    // from the container with no argument.
    public function __construct(private readonly string $minPhp = self::MIN_PHP) {}

    public function label(): string
    {
        return 'PHP';
    }

    public function run(): ProbeResult
    {
        $phpVersion = phpversion();

        if (preg_match('/^(\d+\.\d+)/', $phpVersion, $m) === 1 && version_compare($m[1], $this->minPhp, '>=')) {
            return new ProbeResult(
                ProbeSeverity::Ok->value,
                $phpVersion,
                ['version' => $phpVersion, 'min' => $this->minPhp],
            );
        }

        return new ProbeResult(
            ProbeSeverity::Critical->value,
            sprintf('%s (minimum %s required)', $phpVersion, $this->minPhp),
            ['version' => $phpVersion, 'min' => $this->minPhp],
        );
    }
}
