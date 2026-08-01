<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * @link ../../../../../.docs/features/core/architecture.md
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
            return new ProbeResult(ProbeSeverity::Critical->value,
                'Failed to read PRAGMA journal_mode: '.$e->getMessage(),
                ['exception' => $e::class],
            );
        }

        if (strtolower($current) === 'wal') {
            return new ProbeResult(ProbeSeverity::Ok->value, 'WAL active.', ['current_mode' => $current]);
        }

        return new ProbeResult(ProbeSeverity::Warning->value,
            sprintf("SQLite journal_mode is '%s' (expected 'wal').", $current),
            ['current_mode' => $current],
        );
    }
}
