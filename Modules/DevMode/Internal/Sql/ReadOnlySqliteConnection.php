<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

final readonly class ReadOnlySqliteConnection
{
    public const int TIMEOUT_SECONDS = 5;

    public function __construct(
        private DatabaseManager $db,
        private WallClockCap $wallClock,
    ) {}

    /**
     * @return array{rows: list<object>, duration_ms: int}
     */
    public function execute(string $sql): array
    {
        $connection = $this->resolveConnection();
        $pdo = $connection->getPdo();
        $pdo->exec('PRAGMA query_only = 1');

        $previousLimit = (int) ini_get('max_execution_time');
        $this->wallClock->apply(self::TIMEOUT_SECONDS);

        try {
            $start = hrtime(true);
            /** @var list<object> $rows */
            $rows = $connection->select($sql);
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);
        } finally {
            // In tests the default and readonly connections share one PDO, so
            // leaving query_only armed would block every later write. Each
            // execute() re-arms it, so releasing it here is safe.
            $pdo->exec('PRAGMA query_only = 0');
            // The wall-clock cap stays scoped to this read; 0 is the CLI
            // default meaning "no prior limit".
            $this->wallClock->apply($previousLimit);
        }

        return [
            'rows' => $rows,
            'duration_ms' => $duration,
        ];
    }

    // A second readonly_select connection would open its own empty :memory:
    // database under tests, so the default connection is reused instead.
    private function resolveConnection(): Connection
    {
        $default = $this->db->getDefaultConnection();
        if ($default === 'sqlite_testing') {
            return $this->db->connection();
        }

        return $this->db->connection('readonly_select');
    }
}
