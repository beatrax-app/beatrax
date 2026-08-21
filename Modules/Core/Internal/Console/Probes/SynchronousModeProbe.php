<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Illuminate\Database\DatabaseManager;
use Throwable;

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
            return new ProbeResult(ProbeSeverity::Critical->value,
                'Failed to read PRAGMA synchronous: '.$e->getMessage(),
                ['exception' => $e::class],
            );
        }

        if ($current === 1) {
            return new ProbeResult(ProbeSeverity::Ok->value, 'synchronous = NORMAL (1).', ['current_level' => $current]);
        }

        return new ProbeResult(ProbeSeverity::Warning->value,
            sprintf('SQLite synchronous level is %d (expected NORMAL/1).', $current),
            ['current_level' => $current],
        );
    }
}
