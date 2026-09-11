<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

// Every insert and update the merge issues is read by ForgetNavCountsOnWrite,
// which bumps a cache generation. On the phone that cache is the DATABASE
// store, and it opens a transaction of its own — raised from logQuery(), after
// the statement succeeded, so it arrives as a PDOException rather than the
// QueryException the merge catches. Measured on a Galaxy A51: the replay
// aborted, 367 received ops applied nothing, and 47 rows were held.

/**
 * @param  array<string, mixed>  $fields
 * @return list<OpLogEntry>
 */
function badgeReplayCreateOps(DeviceKeySigner $signer, string $secretKey, int $userId, int $pk, array $fields): array
{
    $entries = [];
    $hlcL = 1_700_000_000_000;

    foreach ($fields as $field => $value) {
        $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: 'transactions',
            pk: $pk,
            field: $field,
            value: json_encode($value, JSON_THROW_ON_ERROR),
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: 'device-badge-replay',
            opType: OpType::CreateRow,
            signature: $signature,
            userId: $userId,
        );

        $entries[] = $make($signer->sign($make('')->signingPayload(), $secretKey));
        $hlcL++;
    }

    return $entries;
}

it('applies an arriving row whose write cannot reach the cache the badge counts live in', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = User::query()->create([
        'username' => 'badge-replay',
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $userId = (int) $user->id;

    test()->actingAs($user);
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);

    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Main', 'slug' => 'main-'.$userId, 'kind' => 'checking',
        'iban' => 'NL00BADGE'.str_pad((string) $userId, 9, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'demo', 'raw_file_path' => 'i.csv',
        'sha256' => str_repeat('a', 64), 'uploaded_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    $born = '2026-06-10 12:00:00';
    $stored = [
        'id' => 157, 'user_id' => $userId, 'account_id' => $accountId, 'type' => 'expense',
        'posted_at' => '2026-06-10', 'booked_at' => $born, 'value_date' => '2026-06-10',
        'amount_minor' => -5511, 'currency' => 'EUR', 'settled_amount_minor' => -5511,
        'settled_currency' => 'EUR', 'counterparty_normalized' => 'belastingdienst',
        'normalization_version' => 3, 'source_format' => 'demo', 'import_run_id' => $runId,
        'source_row_index' => 1, 'fingerprint' => str_repeat('7', 64), 'fingerprint_version' => 3,
        'status' => 'cleared', 'created_at' => $born, 'updated_at' => $born,
    ];
    $db->connection()->table('transactions')->insert($stored);

    // Bound after the fixture, so only the merge's own writes meet it. This is
    // what the database cache store raised on the device, at the point the
    // store opens a second transaction inside the replayer's.
    $refusing = new class(new ArrayStore) extends Repository
    {
        public function increment($key, $value = 1)
        {
            throw new PDOException('SQLSTATE[HY000]: General error: 1 cannot start a transaction within a transaction');
        }
    };
    app()->instance(NavCountsService::class, new NavCountsService($db, $refusing, app(Clock::class)));

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);
    $keypair = sodium_crypto_sign_keypair();

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: ['device-badge-replay' => bin2hex(sodium_crypto_sign_publickey($keypair))],
        rules: new MergeRulesRegistry,
    );

    // The same create again, carrying the column the stored half never had.
    // It reaches SplitCreateTail, which UPDATEs `transactions` — a counted
    // table, so the badge listener runs on it.
    $arriving = array_diff_key($stored, array_flip(['id', 'user_id', 'updated_at']));
    $arriving['payment_type'] = 'direct_debit';

    $replayer->replay(
        badgeReplayCreateOps($signer, sodium_crypto_sign_secretkey($keypair), $userId, 157, $arriving),
        $userId,
    );

    expect($db->connection()->table('transactions')->where('id', 157)->value('payment_type'))
        ->toBe('direct_debit', 'the tail of the arriving create must land even when the badge cache refuses the bump');
});
