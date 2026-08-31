<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// Two devices importing the same statement dedup at the import layer, on the
// fingerprint UNIQUE — not in the merge. The merge only ever sees the edits
// that follow, so a change that moved dedup into the replayer would leave the
// duplicate row standing here.

function dedupUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
    $this->user = dedupUser('sync-dedup-u');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) $this->user->id;

    $this->accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN dedup test',
        'slug' => 'sync-dedup-asn',
        'kind' => 'bank',
        'iban' => 'NL00ASNBDEDUP0001',
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $this->runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sync-dedup.csv',
        'sha256' => hash('sha256', 'sync-dedup-run'),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $catA = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'DedupCatA',
        'slug' => 'dedup-cat-a',
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $catB = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'DedupCatB',
        'slug' => 'dedup-cat-b',
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $this->catA = $catA;
    $this->catB = $catB;

    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);

    $tx = new CanonicalTransaction(
        userId: $userId,
        accountId: $this->accountId,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-06-01'),
        bookedAt: CarbonImmutable::parse('2026-06-01 10:00:00'),
        valueDate: CarbonImmutable::parse('2026-06-01'),
        amountMinor: -4999,
        currency: 'EUR',
        settledAmountMinor: -4999,
        settledCurrency: 'EUR',
        counterpartyName: 'ALBERT HEIJN',
        counterpartyIban: null,
        counterpartyNormalized: $composer->normalize('ALBERT HEIJN'),
        normalizationVersion: FingerprintComposer::NORMALIZATION_VERSION,
        description: 'sync dedup fixture',
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: $this->runId,
        sourceRowIndex: 1,
        sourceRef: null,
    );

    $this->fingerprint = $composer->compose($tx);

    $this->rowPayload = [
        'user_id' => $userId,
        'account_id' => $this->accountId,
        'import_run_id' => $this->runId,
        'fingerprint' => $this->fingerprint,
        'fingerprint_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 10:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -4999,
        'currency' => 'EUR',
        'settled_amount_minor' => -4999,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => $composer->normalize('ALBERT HEIJN'),
        'counterparty_name' => 'ALBERT HEIJN',
        'normalization_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'description' => 'sync dedup fixture',
        'type' => 'expense',  // REQUIRED — transactions_type_check_insert trigger
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ];

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));
    $this->signer = new DeviceKeySigner;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('deduplicates via FingerprintComposer UNIQUE index and merges edit op-logs without duplicate rows', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) $this->user->id;

    $txnId = $db->connection()->table('transactions')->insertGetId($this->rowPayload);

    // The same transaction again: insertOrIgnore drops it without raising.
    $db->connection()->table('transactions')->insertOrIgnore($this->rowPayload);

    $count = $db->connection()
        ->table('transactions')
        ->where('fingerprint', $this->fingerprint)
        ->where('user_id', $userId)
        ->count();

    expect($count)->toBe(1);

    $makeEntry = function (int $catId, int $hlcL, string $deviceId) use ($txnId, $userId): OpLogEntry {
        $stub = new OpLogEntry(
            table: 'transactions',
            pk: $txnId,
            field: 'category_id',
            value: (string) $catId,
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: $deviceId,
            opType: OpType::Set,
            signature: '',
            userId: $userId,
        );
        $sig = $this->signer->sign($stub->signingPayload(), $this->sk);

        return new OpLogEntry(
            table: 'transactions',
            pk: $txnId,
            field: 'category_id',
            value: (string) $catId,
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: $deviceId,
            opType: OpType::Set,
            signature: $sig,
            userId: $userId,
        );
    };

    $entryA = $makeEntry($this->catA, 1000, 'device-a');
    $entryB = $makeEntry($this->catB, 1001, 'device-b');  // higher HLC → wins

    $replayer = new OpLogReplayer(
        $db,
        ['device-a' => $this->pkHex, 'device-b' => $this->pkHex],
    );

    $replayer->replay([$entryA, $entryB], $userId);

    $countAfter = $db->connection()
        ->table('transactions')
        ->where('fingerprint', $this->fingerprint)
        ->where('user_id', $userId)
        ->count();

    expect($countAfter)->toBe(1);

    $catIdAfter = $db->connection()
        ->table('transactions')
        ->where('id', $txnId)
        ->value('category_id');

    expect((int) $catIdAfter)->toBe($this->catB);

    $logCount = $db->connection()
        ->table('op_log_entries')
        ->where('user_id', $userId)
        ->count();
    expect($logCount)->toBeGreaterThanOrEqual(2);
});
