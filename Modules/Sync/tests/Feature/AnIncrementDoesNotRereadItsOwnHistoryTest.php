<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sync\Internal\OpLog\OpLogWriter;

uses(RefreshDatabase::class);

// A g_counter op has to carry this device's own running total, and that total
// was found by reading back every op this device had ever written for the
// field and json_decoding each one. Every increment therefore cost the ones
// before it: at 5,000 prior increments, 6.2 ms and 2 MB per call, for ever.

function incrementUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'incr-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function incrementWriter(int $userId, string $deviceId): OpLogWriter
{
    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    return $writer;
}

it('reads one op to publish its next running total, however many it has written', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = incrementUser($db);

    $writer = incrementWriter($userId, 'device-counting');

    for ($i = 0; $i < 60; $i++) {
        $writer->writeIncrement('merchant_memories', 7, 'occurrence_count', 1);
    }

    /** @var list<string> $reads */
    $reads = [];
    DB::listen(function (QueryExecuted $q) use (&$reads): void {
        if (str_starts_with($q->sql, 'select') && str_contains($q->sql, 'op_log_entries')) {
            $reads[] = $q->sql;
        }
    });

    $writer->writeIncrement('merchant_memories', 7, 'occurrence_count', 1);

    // The 61st increment reads one row, not sixty. Whole-history reads are
    // what made this quadratic, and a `limit 1` is what makes it flat.
    expect($reads)->toHaveCount(1)
        ->and($reads[0])->toContain('limit 1');

    $latest = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('field', 'occurrence_count')
        ->orderByDesc('hlc_l')
        ->value('value');

    expect(json_decode((string) $latest, true))->toBe(61);
});

it('rejects an increment that would not raise this device count', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = incrementUser($db);

    $writer = incrementWriter($userId, 'device-counting');

    // GCounterStrategy merges as the SUM of each device's MAXIMUM, so a total
    // that does not rise is merged away: the op is written, costs a row, and
    // the count silently stops moving on every device that receives it.
    expect(fn (): mixed => $writer->writeIncrement('merchant_memories', 7, 'occurrence_count', 0))
        ->toThrow(LogicException::class);

    expect($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(0);
});
