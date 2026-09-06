<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// The key set is read once at construction, for one user; the scope is chosen
// per replay() call. Nothing held those two together, and an admitted entry is
// re-stamped onto the call's scope — so a device only the first user confirmed
// could author a write the second user's ledger accepted as its own.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $makeUser = static fn (string $username): int => (int) User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ])->id;

    // Every NOT NULL column the transactions triggers require, so the create is
    // refused by the gate under test rather than by the schema.
    $makeLedger = static function (DatabaseManager $db, int $userId, string $tag): array {
        $accountId = $db->connection()->table('accounts')->insertGetId([
            'user_id' => $userId,
            'name' => 'Key set '.$tag,
            'slug' => 'keyset-acct-'.$tag,
            'kind' => 'bank',
            'iban' => 'NL00KEYS'.strtoupper($tag),
            'default_currency' => 'EUR',
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);

        $runId = $db->connection()->table('import_runs')->insertGetId([
            'user_id' => $userId,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/keyset-'.$tag.'.csv',
            'sha256' => hash('sha256', 'keyset-run-'.$tag),
            'uploaded_at' => '2026-06-01 00:00:00',
            'status' => 'previewed',
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);

        $categoryIds = [];
        foreach (['a', 'b'] as $slot) {
            $categoryIds[$slot] = $db->connection()->table('categories')->insertGetId([
                'user_id' => $userId,
                'name' => 'Key set '.$tag.' '.$slot,
                'slug' => 'keyset-cat-'.$tag.'-'.$slot,
                'kind' => 'expense',
                'created_at' => '2026-06-01 00:00:00',
                'updated_at' => '2026-06-01 00:00:00',
            ]);
        }

        $txnId = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $userId,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'category_id' => $categoryIds['a'],
            'fingerprint' => hash('sha256', 'keyset-'.$tag),
            'posted_at' => '2026-06-01',
            'booked_at' => '2026-06-01 10:00:00',
            'value_date' => '2026-06-01',
            'amount_minor' => -4999,
            'currency' => 'EUR',
            'settled_amount_minor' => -4999,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => 'albert heijn',
            'counterparty_name' => 'ALBERT HEIJN',
            'normalization_version' => 3,
            'description' => 'key set scope fixture',
            'type' => 'expense',
            'source_format' => 'asn-csv',
            'source_row_index' => 1,
            'fingerprint_version' => 3,
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);

        return ['txn' => $txnId, 'catA' => $categoryIds['a'], 'catB' => $categoryIds['b']];
    };

    $this->mine = $makeUser('keyset-mine');
    $this->theirs = $makeUser('keyset-theirs');

    $this->myLedger = $makeLedger($db, $this->mine, 'mine');
    $this->theirLedger = $makeLedger($db, $this->theirs, 'theirs');

    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $signer = new DeviceKeySigner;

    // Confirmed by `mine` and nobody else — the whole point of the fixture.
    $this->deviceKeys = ['device-mine' => bin2hex(sodium_crypto_sign_publickey($keypair))];

    $this->signedEntry = static function (
        string $table,
        int $pk,
        string $value,
        int $hlcL,
        int $entryUserId,
    ) use ($signer, $secret): OpLogEntry {
        $shape = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: $table,
            pk: $pk,
            field: 'category_id',
            value: $value,
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: 'device-mine',
            opType: OpType::Set,
            signature: $signature,
            userId: $entryUserId,
        );

        return $shape($signer->sign($shape('')->signingPayload(), $secret));
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('refuses every entry when the key set was read for a different user than the replay scope', function (): void {
    $before = $this->db->connection()->table('transactions')
        ->where('id', $this->theirLedger['txn'])->value('category_id');

    $entry = ($this->signedEntry)(
        'transactions',
        $this->theirLedger['txn'],
        (string) $this->theirLedger['catB'],
        9999,
        $this->theirs,
    );

    // Keys belong to `mine`; the replay is scoped to `theirs`.
    $replayer = new OpLogReplayer($this->db, $this->deviceKeys, deviceKeysUserId: $this->mine);
    $replayer->replay([$entry], $this->theirs);

    $after = $this->db->connection()->table('transactions')
        ->where('id', $this->theirLedger['txn'])->value('category_id');

    expect($after)->toBe($before, 'a device only the other user confirmed rewrote this ledger');

    expect($this->db->connection()->table('op_log_entries')->where('hlc_l', 9999)->count())
        ->toBe(0, 'a refused entry must never become a durable op_log row');

    expect(
        $this->db->connection()->table('op_log_quarantine')
            ->where('hlc_l', 9999)->where('reason', 'cross_user')->count()
    )->toBe(1, 'the refusal must be recorded as cross_user');
});

it('runs the scope check before the table gate, so a mismatched scope is never reported as an unknown table', function (): void {
    $entry = ($this->signedEntry)('not_a_registered_table', 4242, '"x"', 7777, $this->theirs);

    (new OpLogReplayer($this->db, $this->deviceKeys, deviceKeysUserId: $this->mine))
        ->replay([$entry], $this->theirs);

    $reason = $this->db->connection()->table('op_log_quarantine')
        ->where('hlc_l', 7777)->value('reason');

    expect($reason)->toBe('cross_user', 'the user-scope check must be the replayer\'s first guard');
});

it('still applies a peer entry whose own user id differs from the scope, when the key set matches it', function (): void {
    // user_id on the wire is the SENDING device's autoincrement. Comparing it
    // to this device's own id is what rejected every op a paired peer sent, so
    // the restored guard must not be reading it.
    $entry = ($this->signedEntry)(
        'transactions',
        $this->myLedger['txn'],
        (string) $this->myLedger['catB'],
        5555,
        $this->mine + 4242,
    );

    (new OpLogReplayer($this->db, $this->deviceKeys, deviceKeysUserId: $this->mine))
        ->replay([$entry], $this->mine);

    expect((int) $this->db->connection()->table('transactions')
        ->where('id', $this->myLedger['txn'])->value('category_id'))
        ->toBe($this->myLedger['catB'], 'a paired peer\'s own user id must not refuse its ops');
});

it('applies a same-scope replay unchanged', function (): void {
    $entry = ($this->signedEntry)(
        'transactions',
        $this->myLedger['txn'],
        (string) $this->myLedger['catB'],
        6666,
        $this->mine,
    );

    (new OpLogReplayer($this->db, $this->deviceKeys, deviceKeysUserId: $this->mine))
        ->replay([$entry], $this->mine);

    expect((int) $this->db->connection()->table('transactions')
        ->where('id', $this->myLedger['txn'])->value('category_id'))
        ->toBe($this->myLedger['catB']);

    expect($this->db->connection()->table('op_log_quarantine')->where('hlc_l', 6666)->count())
        ->toBe(0);
});
