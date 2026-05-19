<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Reads `PRAGMA synchronous` on the framework's default SQLite
 * connection and asserts it equals integer `1` (= `NORMAL`). The
 * SQLite synchronous levels are `OFF=0`, `NORMAL=1`, `FULL=2`,
 * `EXTRA=3`; the project locks `NORMAL` for the WAL + single-user
 * substrate per `config/database.php`.
 *
 * Mirrors `WalModeProbe` line-for-line except it returns the integer
 * level in `metadata.current_level`. The PRAGMA read is wrapped in
 * try/catch so the probe never throws.
 */
final class SynchronousModeProbe implements Probe
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function label(): string
    {
        return 'SQLite synchronous mode';
    }

    public function run(): ProbeResult
    {
        try {
            $value = $this->db->connection()->scalar('PRAGMA synchronous');
            $current = is_numeric($value) ? (int) $value : -1;
        } catch (Throwable $e) {
            return new ProbeResult(
                'critical',
                'Failed to read PRAGMA synchronous: '.$e->getMessage(),
                ['exception' => $e::class],
            );
        }

        if ($current === 1) {
            return new ProbeResult('ok', 'synchronous = NORMAL (1).', ['current_level' => $current]);
        }

        return new ProbeResult(
            'warning',
            sprintf('SQLite synchronous level is %d (expected NORMAL/1).', $current),
            ['current_level' => $current],
        );
    }
}
