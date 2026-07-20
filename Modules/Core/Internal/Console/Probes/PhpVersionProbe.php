<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class PhpVersionProbe implements Probe
{
    private const MIN_PHP = '8.5';

    public function label(): string
    {
        return 'PHP';
    }

    public function run(): ProbeResult
    {
        $phpVersion = phpversion();

        if (preg_match('/^(\d+\.\d+)/', $phpVersion, $m) === 1 && version_compare($m[1], self::MIN_PHP, '>=')) {
            return new ProbeResult(
                'ok',
                $phpVersion,
                ['version' => $phpVersion, 'min' => self::MIN_PHP],
            );
        }

        return new ProbeResult(
            'critical',
            sprintf('%s (minimum %s required)', $phpVersion, self::MIN_PHP),
            ['version' => $phpVersion, 'min' => self::MIN_PHP],
        );
    }
}
