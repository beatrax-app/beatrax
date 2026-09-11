<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\DeferredOpCaptureDrain;
use Modules\Sync\Internal\OpLog\DeferredOpCaptures;
use Modules\Sync\Internal\OpLog\DeferredOpKind;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// Measured on the live desktop: 438 create field-ops across `anomaly_alerts`,
// `recurring_series` and `recurring_series_occurrences` named rows this same
// device had already announced 110 minutes earlier. The scheduler created the
// rows at 00:00 holding no key, the pre-sync walk announced them at 16:24, and
// the drain announced every one of them a second time at 18:16 — the whole of
// what that tick wrote was a repeat.

function reannounceUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'reannounce-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Coverage counts only authors a peer can still verify, so a writer no
// registry row names leaves everything it wrote uncovered — which is the
// defect these tests sit on top of, not the state they set up.
function reannounceWriter(int $userId, string $deviceId = 'reannounce-device'): OpLogWriter
{
    $keypair = sodium_crypto_sign_keypair();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Fixture device',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($keypair)),
        'x25519_public_key_hex' => str_repeat('00', 32),
        'safety_number_words' => '',
        'is_self' => 1,
        'paired_at' => '2026-09-01T00:00:00+00:00',
        'confirmed_at' => '2026-09-01T00:00:00+00:00',
        'last_seen_at' => null,
        'created_at' => '2026-09-01T00:00:00+00:00',
        'updated_at' => '2026-09-01T00:00:00+00:00',
    ]);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    app()->instance(OpLogWriter::class, $writer);

    return $writer;
}

// The shape the scheduler leaves behind: a row written by a process holding no
// key, with one coordinate per column of the create it could not sign.
/**
 * @param  list<string>  $columns
 */
function reannounceSeries(DatabaseManager $db, int $userId, array $columns): int
{
    $seriesId = (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Streaming',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'cluster_key' => 'streaming-'.bin2hex(random_bytes(4)),
        'billing_day' => 7,
        'created_at' => '2026-09-11 00:00:08',
        'updated_at' => '2026-09-11 00:00:08',
    ]);

    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);

    foreach ($columns as $column) {
        $queue->record($userId, 'recurring_series', $seriesId, $column, DeferredOpKind::Create);
    }

    return $seriesId;
}

/**
 * @return list<object>
 */
function reannounceOps(DatabaseManager $db, int $userId, int|string $pk): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'recurring_series')
        ->where('pk', (string) $pk)
        ->orderBy('id')
        ->get()
        ->all();
}

/**
 * @param  list<object>  $ops
 * @return list<string>
 */
function reannounceFieldsOfType(array $ops, OpType $type): array
{
    $fields = [];

    foreach ($ops as $op) {
        if ((string) $op->op_type === $type->value) {
            $fields[] = (string) $op->field;
        }
    }

    sort($fields);

    return $fields;
}

it('announces nothing for a row the walk already put in the log', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = reannounceUser($db);
    $writer = reannounceWriter($userId);

    $seriesId = reannounceSeries($db, $userId, ['detected_name', 'latest_amount_minor', 'billing_day']);

    app(OpLogBackfiller::class)->backfill($userId, $writer);

    $afterTheWalk = reannounceOps($db, $userId, $seriesId);

    expect($afterTheWalk)->not->toBeEmpty();

    expect(app(DeferredOpCaptureDrain::class)->drain($userId))->toBe(3)
        ->and(reannounceOps($db, $userId, $seriesId))->toHaveCount(count($afterTheWalk))
        ->and($db->connection()->table('deferred_op_captures')->count())->toBe(0);
});

// The positive control for the assertion above: a guard that suppressed
// everything would pass it just as well.
it('still announces a row the walk never reached', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = reannounceUser($db);
    reannounceWriter($userId);

    $seriesId = reannounceSeries($db, $userId, ['detected_name', 'latest_amount_minor']);

    expect(app(DeferredOpCaptureDrain::class)->drain($userId))->toBe(2);

    expect(reannounceFieldsOfType(reannounceOps($db, $userId, $seriesId), OpType::CreateRow))
        ->toContain('detected_name', 'latest_amount_minor');
});

// The other direction, and the one that matters most: a column the earlier
// create never carried is still owed. It travels as a Set, which the peer
// merges — a second create would be discarded, filling only the columns the
// peer's row is missing and talking over nothing.
it('announces a column the earlier create never carried, as a set', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = reannounceUser($db);
    $writer = reannounceWriter($userId);

    $seriesId = reannounceSeries($db, $userId, ['detected_name']);

    $writer->writeCreateRow('recurring_series', $seriesId, ['detected_name' => 'Streaming']);

    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);
    $queue->record($userId, 'recurring_series', $seriesId, 'billing_day', DeferredOpKind::Create);

    app(DeferredOpCaptureDrain::class)->drain($userId);

    $ops = reannounceOps($db, $userId, $seriesId);

    expect(reannounceFieldsOfType($ops, OpType::Set))->toBe(['billing_day'])
        ->and(reannounceFieldsOfType($ops, OpType::CreateRow))
        ->toBe(['created_at', 'detected_name', 'updated_at']);
});

// A change that happened while the device was locked carries its own
// coordinate and is a Set in its own right. The create being dropped must not
// take it with it.
it('still announces a set captured beside a create the walk already covered', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = reannounceUser($db);
    $writer = reannounceWriter($userId);

    $seriesId = reannounceSeries($db, $userId, ['detected_name', 'billing_day']);

    app(OpLogBackfiller::class)->backfill($userId, $writer);

    $db->connection()->table('recurring_series')->where('id', $seriesId)->update(['billing_day' => 15]);
    app(DeferredOpCaptures::class)->record($userId, 'recurring_series', $seriesId, 'billing_day', DeferredOpKind::Set);

    app(DeferredOpCaptureDrain::class)->drain($userId);

    $sets = array_values(array_filter(
        reannounceOps($db, $userId, $seriesId),
        static fn (object $op): bool => (string) $op->op_type === OpType::Set->value,
    ));

    expect($sets)->toHaveCount(1)
        ->and((string) $sets[0]->field)->toBe('billing_day')
        ->and((string) $sets[0]->value)->toBe('15');
});

it('does not announce a create twice when an import captures the same row twice', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = reannounceUser($db);
    $writer = reannounceWriter($userId);

    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Import account',
        'slug' => 'import-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = app(OpLogBackfiller::class);
    $backfiller->captureRowsById('accounts', [$accountId], $userId, $writer);

    $afterFirst = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'accounts')
        ->where('pk', (string) $accountId)
        ->count();

    expect($afterFirst)->toBeGreaterThan(0)
        ->and($backfiller->captureRowsById('accounts', [$accountId], $userId, $writer))->toBe(0)
        ->and($db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', 'accounts')
            ->where('pk', (string) $accountId)
            ->count())->toBe($afterFirst);
});
