<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

/**
 * Immutable value object summarising one probe's outcome. The doctor
 * command iterates probes, prints `{$label}: {$severity} — {$message}`
 * per row, and aggregates the exit code via the project's existing
 * convention: any `warning` bumps the exit code to ≥1; any `critical`
 * bumps it to 2.
 *
 * `$metadata` carries structured per-probe context for downstream
 * consumers (e.g. `BackupFreshnessProbe` stores `hours_old` so the
 * `system_alerts` row it writes can capture the same number).
 */
final readonly class ProbeResult
{
    /**
     * @param  'ok'|'warning'|'critical'  $severity
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $severity,
        public string $message,
        public array $metadata = [],
    ) {}
}
