<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\GoalContributionMutated;

uses(RefreshDatabase::class);

// Attributions are append-only, so only create and delete ever reach the op
// log. An edit has no meaning here and is logged as an unknown mutation type
// rather than written.

/**
 * @return array{listener: SyncCaptureListener, userId: int, contributionId: int, goalId: int, transactionId: int}
 */
function goalContributionCaptureFixture(DatabaseManager $db): array
{
    $connection = $db->connection();

    $userId = (int) $connection->table('users')->insertGetId([
        'username' => 'goal-capture-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $accountId = $connection->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN goal capture',
        'slug' => 'goal-capture-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $runId = $connection->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/goal-capture.csv',
        'sha256' => hash('sha256', 'goal-capture-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-06-14 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $transactionId = $connection->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'goal-capture-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 10:00:00',
        'value_date' => '2026-06-14',
        'amount_minor' => 20000,
        'currency' => 'EUR',
        'settled_amount_minor' => 20000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'savings transfer',
        'counterparty_name' => 'Savings transfer',
        'normalization_version' => 3,
        'type' => 'transfer_in',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $goalId = $connection->table('goals')->insertGetId([
        'user_id' => $userId,
        'name' => 'Winter tyres',
        'target_minor' => 60000,
        'target_currency' => 'EUR',
        'start_date' => '2026-06-01',
        'target_date' => '2026-12-01',
        'status' => 'active',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $contributionId = $connection->table('goal_contributions')->insertGetId([
        'user_id' => $userId,
        'goal_id' => $goalId,
        'transaction_id' => $transactionId,
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'goal-capture-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);
    app()->instance(OpLogWriter::class, $writer);

    /** @var SyncCaptureListener $listener */
    $listener = app(SyncCaptureListener::class);

    return [
        'listener' => $listener,
        'userId' => $userId,
        'contributionId' => (int) $contributionId,
        'goalId' => (int) $goalId,
        'transactionId' => (int) $transactionId,
    ];
}

it('lands a create_row op per stored column when a contribution is attributed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [
        'listener' => $listener,
        'userId' => $userId,
        'contributionId' => $contributionId,
        'goalId' => $goalId,
        'transactionId' => $transactionId,
    ] = goalContributionCaptureFixture($db);

    $listener->handleGoalContribution(new GoalContributionMutated(
        contributionId: $contributionId,
        userId: $userId,
        mutationType: 'create',
        dirtyFields: [
            'user_id' => $userId,
            'goal_id' => $goalId,
            'transaction_id' => $transactionId,
        ],
    ));

    $rows = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'goal_contributions')
        ->get();

    // Five, not the three the payload names: writeCreateRow reads the row's
    // timestamps back so a live write sends what the backfill's whole-row read
    // already sent. A peer holding the identity columns and a null created_at
    // is swept by no retention pass and has no place in a keyset order.
    expect($rows)->toHaveCount(5);
    expect($rows->pluck('op_type')->unique()->all())->toBe(['create_row']);
    expect($rows->pluck('field')->sort()->values()->all())
        ->toBe(['created_at', 'goal_id', 'transaction_id', 'updated_at', 'user_id']);

    expect((string) $rows->firstWhere('field', 'created_at')->value)->toContain('2026-06-14');
});

it('lands a delete_tombstone op when an attribution is removed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    ['listener' => $listener, 'userId' => $userId, 'contributionId' => $contributionId] = goalContributionCaptureFixture($db);

    $listener->handleGoalContribution(new GoalContributionMutated(
        contributionId: $contributionId,
        userId: $userId,
        mutationType: 'delete',
    ));

    $rows = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'goal_contributions')
        ->where('op_type', 'delete_tombstone')
        ->get();

    expect($rows)->toHaveCount(1);
});

it('writes nothing for a mutation type an append-only table cannot have', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    ['listener' => $listener, 'userId' => $userId, 'contributionId' => $contributionId] = goalContributionCaptureFixture($db);

    $listener->handleGoalContribution(new GoalContributionMutated(
        contributionId: $contributionId,
        userId: $userId,
        mutationType: 'edit',
        dirtyFields: ['goal_id' => 1],
    ));

    expect($db->connection()->table('op_log_entries')->where('table_name', 'goal_contributions')->count())->toBe(0);
});
