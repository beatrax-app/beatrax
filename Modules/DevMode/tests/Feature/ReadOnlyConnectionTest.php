<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\DevMode\Internal\Sql\ReadOnlySqliteConnection;
use Modules\DevMode\Internal\Sql\WallClockCap;

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

it('calls WallClockCap::apply(5) at least once with the timeout value (mockable seam)', function (): void {
    $captured = [];
    $stub = new class($captured) extends WallClockCap
    {
        /** @var list<int> */
        public array $calls = [];

        public function __construct(array &$calls)
        {
            $calls = [];
            $this->callsRef = &$calls;
        }

        /** @var array<int, int> */
        public array $callsRef;

        public function apply(int $seconds): void
        {
            $this->callsRef[] = $seconds;
        }
    };

    $conn = new ReadOnlySqliteConnection(app(DatabaseManager::class), $stub);
    $conn->execute('SELECT 1');

    // Two calls, not one: the second restores the previous limit after the
    // query, so only the first carries the cap value.
    expect($stub->callsRef[0])->toBe(ReadOnlySqliteConnection::TIMEOUT_SECONDS);
    expect(count($stub->callsRef))->toBeGreaterThanOrEqual(1);
});

it('returns an empty rows array when the SELECT matches nothing', function (): void {
    $conn = app(ReadOnlySqliteConnection::class);
    $result = $conn->execute("SELECT * FROM users WHERE username = 'does-not-exist-row'");

    expect($result['rows'])->toBe([]);
});
