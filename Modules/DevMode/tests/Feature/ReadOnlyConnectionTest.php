<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\DevMode\Internal\Sql\IsolatedSelectProcess;
use Modules\DevMode\Internal\Sql\QueryTimedOutException;
use Modules\DevMode\Internal\Sql\ReadOnlySqliteConnection;

// The engine layer under SelectOnlyValidator: PRAGMA query_only = 1 means a
// write that slipped past the parser still cannot land.
it('rejects a write attempt with SQLITE_READONLY (engine-level guard)', function (): void {
    $conn = app(ReadOnlySqliteConnection::class);

    expect(fn () => $conn->execute('INSERT INTO users (username, password, period_start_day, default_currency_view, is_developer) VALUES (\'x\', \'x\', 1, \'eur_only\', 0)'))
        ->toThrow(PDOException::class);
});

it('returns rows + duration_ms for a successful SELECT', function (): void {
    DB::table('users')->insert([
        'username' => 'ros-1',
        'password' => 'pw',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => 0,
    ]);
    DB::table('users')->insert([
        'username' => 'ros-2',
        'password' => 'pw',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => 0,
    ]);

    $conn = app(ReadOnlySqliteConnection::class);
    $result = $conn->execute('SELECT username FROM users ORDER BY username ASC');

    expect($result['rows'])->toHaveCount(2);
    expect($result['rows'][0]->username)->toBe('ros-1');
    expect($result['rows'][1]->username)->toBe('ros-2');
    expect($result['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('returns an empty rows array when the SELECT matches nothing', function (): void {
    $conn = app(ReadOnlySqliteConnection::class);
    $result = $conn->execute("SELECT * FROM users WHERE username = 'does-not-exist-row'");

    expect($result['rows'])->toBe([]);
});

function isolatedSelectFixtureDatabase(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-isolated-sql-'.bin2hex(random_bytes(6)).'.sqlite';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE canary (note TEXT)');
    $pdo->exec("INSERT INTO canary (note) VALUES ('still here')");

    return $path;
}

// The cap the SQL panel promises, driven by the statement that used to hit it:
// `SELECT count(*)` over an endless recursive CTE, which SelectOnlyValidator
// allows (no write keyword, one statement) and no PHP-side handler can stop.
it('kills a runaway SELECT at the cap and leaves the calling process alive', function (): void {
    $path = isolatedSelectFixtureDatabase();
    $isolated = new IsolatedSelectProcess;
    $runaway = 'WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM c) SELECT count(*) n FROM c';

    $startedAt = microtime(true);
    $thrown = null;
    try {
        $isolated->run($path, $runaway, 2);
    } catch (QueryTimedOutException $e) {
        $thrown = $e;
    }
    $elapsed = microtime(true) - $startedAt;

    expect($thrown)->toBeInstanceOf(QueryTimedOutException::class);
    expect($elapsed)->toBeLessThan(10.0);

    // This process is still able to serve the next request, which is what
    // set_time_limit() could not promise.
    $after = $isolated->run($path, 'SELECT note FROM canary', 5);
    expect($after['rows'][0]->note)->toBe('still here');

    @unlink($path);
});

it('surfaces an engine error from the isolated process instead of a timeout', function (): void {
    $path = isolatedSelectFixtureDatabase();
    $isolated = new IsolatedSelectProcess;

    expect(fn () => $isolated->run($path, 'SELECT * FROM no_such_table', 5))
        ->toThrow(RuntimeException::class, 'no such table');

    @unlink($path);
});

it('refuses to write through the isolated process (PRAGMA query_only travels with it)', function (): void {
    $path = isolatedSelectFixtureDatabase();
    $isolated = new IsolatedSelectProcess;

    expect(fn () => $isolated->run($path, "INSERT INTO canary (note) VALUES ('sneaked in')", 5))
        ->toThrow(RuntimeException::class, 'readonly database');

    @unlink($path);
});

it('does not isolate an in-memory database, which no child process could open', function (): void {
    $isolated = new IsolatedSelectProcess;

    expect($isolated->canIsolate(':memory:'))->toBeFalse();
    expect($isolated->canIsolate('/var/db/beatrax.sqlite'))->toBeTrue();
});

// The embed SAPI leaves PHP_BINARY empty, and an isolated run with no
// interpreter would build `'' -r ...` and report a started process that ran
// nothing.
it('does not isolate when there is no interpreter to spawn', function (): void {
    $isolated = new IsolatedSelectProcess('');

    expect($isolated->canIsolate('/var/db/beatrax.sqlite'))->toBeFalse();
});

it('reaches the isolated path from ReadOnlySqliteConnection when the database is a real file', function (): void {
    $path = isolatedSelectFixtureDatabase();
    $seen = [];
    $isolated = new class($seen) extends IsolatedSelectProcess
    {
        /** @var array<string, mixed> */
        public array $seenRef;

        public function __construct(array &$seen)
        {
            parent::__construct();
            $seen = [];
            $this->seenRef = &$seen;
        }

        public function canIsolate(string $databaseFile): bool
        {
            return true;
        }

        public function run(string $databaseFile, string $sql, int $timeoutSeconds, int $busyTimeoutMs = 0): array
        {
            $this->seenRef = ['sql' => $sql, 'timeout' => $timeoutSeconds, 'busy' => $busyTimeoutMs];

            return ['rows' => [], 'duration_ms' => 1];
        }
    };

    $conn = new ReadOnlySqliteConnection(app(DatabaseManager::class), $isolated);
    $conn->execute('SELECT 1');

    expect($isolated->seenRef['sql'])->toBe('SELECT 1');
    expect($isolated->seenRef['timeout'])->toBe(ReadOnlySqliteConnection::TIMEOUT_SECONDS);

    // The child opens its own PDO outside Laravel, so the busy timeout has to
    // travel with it: read here off the same connection the code reads it off,
    // never a number named twice.
    $configured = app(DatabaseManager::class)->connection()->getConfig('busy_timeout');
    expect($isolated->seenRef['busy'])->toBe(is_numeric($configured) ? (int) $configured : 0);

    @unlink($path);
});
