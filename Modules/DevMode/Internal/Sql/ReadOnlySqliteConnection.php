<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Read-only SQLite seam for the /dev/sql panel.
 *
 * The `readonly_select` connection registered in config/database.php
 * shares the same on-disk database file as the default `sqlite`
 * connection. This class opens the PDO and applies
 * `PRAGMA query_only = 1` per-PDO before executing — SQLite's
 * engine-level read-only mode that rejects every write attempt with
 * SQLITE_READONLY at the storage layer (defense-in-depth alongside
 * the parse-time SelectOnlyValidator).
 *
 * Wall-clock cap rationale:
 *
 *   - PDO::ATTR_TIMEOUT is connection-level (lock-wait timeout), NOT
 *     query-duration. set_time_limit($n) is the reliable coarse cap.
 *   - This class invokes the cap via the injected
 *     {@see WallClockCap} seam so unit tests can mock the apply(int)
 *     call without interacting with the test runner's own
 *     execution-time budget.
 *
 * Returned shape:
 *   ['rows' => list<object>, 'duration_ms' => int]
 *
 * The rows array is the raw DB::select() output — each row is a
 * stdClass with the column names as properties. The consuming view
 * iterates and renders cells via get_object_vars() to satisfy the
 * larastan-strict-rules profile.
 */
final readonly class ReadOnlySqliteConnection
{
    /** Seconds before the wall-clock cap fires. */
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

    /**
     * Resolve the connection to use for SELECT execution.
     *
     * In the production path, `readonly_select` is a sibling of the
     * default `sqlite` connection (config/database.php; both point at
     * the same on-disk database file). Under tests where the default
     * connection is `sqlite_testing` (in-memory), separate connection
     * instances would resolve to SEPARATE `:memory:` databases — the
     * sibling sees an empty schema. To keep the testing path coherent
     * without diverging the production logic, resolve to the default
     * connection when the default is `sqlite_testing`.
     */
    private function resolveConnection(): Connection
    {
        $default = $this->db->getDefaultConnection();
        if ($default === 'sqlite_testing') {
            return $this->db->connection();
        }

        return $this->db->connection('readonly_select');
    }
}
