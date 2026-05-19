<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Reads `PRAGMA journal_mode` on the framework's default SQLite
 * connection and asserts it equals `wal` (case-insensitive). Drift
 * surfaces as a `warning`-severity probe result; the
 * `HealthCheckServiceProvider` owns the matching `system_alerts` row
 * write (so probe + boot-time listener cooperate without double-
 * counting).
 *
 * The PRAGMA read is wrapped in try/catch — a missing-file or PDO
 * error returns a `critical` `ProbeResult` rather than throwing.
 */
final class WalModeProbe implements Probe
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function label(): string
    {
        return 'SQLite WAL mode';
    }

    public function run(): ProbeResult
    {
        try {
            $value = $this->db->connection()->scalar('PRAGMA journal_mode');
            $current = is_string($value) ? $value : '';
        } catch (Throwable $e) {
            return new ProbeResult(
                'critical',
                'Failed to read PRAGMA journal_mode: '.$e->getMessage(),
                ['exception' => $e::class],
            );
        }

        if (strtolower($current) === 'wal') {
            return new ProbeResult('ok', 'WAL active.', ['current_mode' => $current]);
        }

        return new ProbeResult(
            'warning',
            sprintf("SQLite journal_mode is '%s' (expected 'wal').", $current),
            ['current_mode' => $current],
        );
    }
}
