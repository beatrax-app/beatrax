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
            // Reset PRAGMA so subsequent writes on the same PDO (e.g. the
            // testing path where default + readonly share one in-memory
            // connection) can proceed; every execute() re-arms PRAGMA
            // query_only = 1 before reading, so this is a no-op in prod.
            $pdo->exec('PRAGMA query_only = 0');
            // Restore the previous max-execution-time so the wall-clock
            // cap stays scoped to this read rather than persisting for
            // the rest of the request; 0 means "no prior limit" (CLI
            // default), which makes the process unlimited again.
            $this->wallClock->apply($previousLimit);
        }

        return [
            'rows' => $rows,
            'duration_ms' => $duration,
        ];
    }

    // Under tests, where the default connection is sqlite_testing
    // (in-memory), a separate readonly_select connection instance would
    // resolve to a SEPARATE :memory: database with an empty schema — fall
    // back to the default connection to keep the testing path coherent.
    private function resolveConnection(): Connection
    {
        $default = $this->db->getDefaultConnection();
        if ($default === 'sqlite_testing') {
            return $this->db->connection();
        }

        return $this->db->connection('readonly_select');
    }
}
