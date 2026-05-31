<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

/**
 * Reports the running PHP interpreter's version and asserts the
 * configured minimum. The minimum tracks the `php` requirement in
 * composer.json and the CI matrix — both are 8.5, which is also the
 * minor `nativephp/php-bin` ships in the desktop build. The comparison
 * matches against major.minor only so alpha / beta / RC builds of
 * the minimum version still pass — the operator's intent in
 * installing "PHP 8.5-RC1" is to be on 8.5, not to be rejected for it.
 *
 * `phpversion()` is read directly (not the `PHP_VERSION` constant)
 * so the comparison reflects the runtime, not a statically pre-
 * computed value carried over by opcache.
 *
 * A below-minimum version surfaces as `critical` so the doctor
 * command's aggregator routes it to the BLOCKER exit code; the
 * pre-existing PHP runtime line was load-bearing for the 0/1/2
 * exit semantics and the Probe-based replacement preserves that.
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
