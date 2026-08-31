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
        private IsolatedSelectProcess $isolated,
    ) {}

    // The cap runs in a child process because PHP cannot interrupt a statement
    // SQLite is already stepping: set_time_limit() expires as a fatal error, and
    // under the built-in server that served this request it took the whole
    // backend down instead of returning a timeout message.
    /**
     * @return array{rows: list<object>, duration_ms: int}
     *
     * @throws QueryTimedOutException
     */
    public function execute(string $sql): array
    {
        $connection = $this->resolveConnection();
        $databaseFile = $connection->getDatabaseName();

        if ($this->isolated->canIsolate($databaseFile)) {
            return $this->isolated->run(
                $databaseFile,
                $sql,
                self::TIMEOUT_SECONDS,
                self::busyTimeoutMs($connection),
            );
        }

        return $this->executeInProcess($connection, $sql);
    }

    /**
     * @return array{rows: list<object>, duration_ms: int}
     */
    private function executeInProcess(Connection $connection, string $sql): array
    {
        $pdo = $connection->getPdo();
        $pdo->exec('PRAGMA query_only = 1');

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
        }

        return [
            'rows' => $rows,
            'duration_ms' => $duration,
        ];
    }

    // The child opens its own PDO outside Laravel, so the ConnectionEstablished
    // listener that applies the configured busy timeout never runs for it. The
    // value is read off the connection rather than named here, so the number
    // still lives only in config/database.php.
    private static function busyTimeoutMs(Connection $connection): int
    {
        $configured = $connection->getConfig('busy_timeout');

        return is_numeric($configured) ? (int) $configured : 0;
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
