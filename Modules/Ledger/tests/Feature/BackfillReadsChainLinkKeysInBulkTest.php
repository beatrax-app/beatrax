<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\CounterpartyKeyBackfill;
use Modules\Sync\Public\Services\BlindIndexCodec;

// The enable-time sweep rewrites one signature hash per confirmed chain link,
// and asked the database for the link's own matching key a link at a time.
// The keys come off the transactions the chunk already names.

$bckIban = 'NL00ASNBBCK00001';

$bckKeyHex = str_repeat('ab', 32);

beforeEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    /** @var User $user */
    $user = User::query()->create([
        'username' => 'backfill-chain-link-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->user = $user;
    $this->userId = (int) $user->id;
});

$bckSeedLinks = static function (ConnectionInterface $conn, int $userId, string $iban, int $count): void {
    $accountId = $conn->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'BCK ASN',
        'slug' => 'bck-asn',
        'kind' => 'bank',
        'iban' => $iban,
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $conn->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/bck.csv',
        'sha256' => hash('sha256', 'bck'),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $insertTransaction = static function (int $index, string $suffix, string $plainKey, string $type) use ($conn, $userId, $accountId, $runId): int {
        return (int) $conn->table('transactions')->insertGetId([
            'user_id' => $userId,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'bck-'.$suffix.'-'.$index),
            'posted_at' => '2026-03-01',
            'booked_at' => '2026-03-01 00:00:00',
            'value_date' => '2026-03-01',
            'amount_minor' => -100 - $index,
            'currency' => 'EUR',
            'settled_amount_minor' => -100 - $index,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => $plainKey,
            'counterparty_name' => 'Merchant '.$index,
            'normalization_version' => 1,
            'description' => 'bck row '.$suffix.'-'.$index,
            'type' => $type,
            'source_format' => 'asn-csv',
            'source_row_index' => $index,
            'fingerprint_version' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    for ($i = 0; $i < $count; $i++) {
        $plainKey = 'bck-merchant-'.$i;
        $txId = $insertTransaction($i, 'from', $plainKey, 'expense');
        $partnerId = $insertTransaction($i, 'to', 'bck-partner-'.$i, 'transfer_in');

        $conn->table('chain_links')->insert([
            'user_id' => $userId,
            'from_transaction_id' => $txId,
            'to_transaction_id' => $partnerId,
            'kind' => 'paypal_funding',
            'state' => 'confirmed',
            'confidence' => '1.000',
            'resolver' => 'auto',
            'evidence' => json_encode([
                'matched_iban' => $iban,
                'event_type' => 'General Withdrawal',
                'signature_hash' => hash('sha256', $plainKey.'|'.$iban),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

// The per-link shape and the per-chunk shape both start here, so the count
// stays honest whichever one the sweep is using.
$bckKeyReads = static function (callable $work): int {
    $reads = 0;
    DB::listen(static function (QueryExecuted $query) use (&$reads): void {
        if (
            str_starts_with($query->sql, 'select "counterparty_normalized" from "transactions"')
            || str_starts_with($query->sql, 'select "id", "counterparty_normalized" from "transactions"')
        ) {
            $reads++;
        }
    });

    $work();

    return $reads;
};

it('reads the chain-link matching keys once per chunk, not once per link', function () use ($bckSeedLinks, $bckKeyReads, $bckIban, $bckKeyHex): void {
    $bckSeedLinks($this->conn, $this->userId, $bckIban, 600);

    $backfill = app(CounterpartyKeyBackfill::class);
    $reads = $bckKeyReads(function () use ($backfill, $bckKeyHex): void {
        $backfill->run($this->userId, $bckKeyHex);
    });

    expect($reads)->toBe(2);
});

it('rewrites every signature hash under the derived key and keeps the rest of the evidence', function () use ($bckSeedLinks, $bckIban, $bckKeyHex): void {
    $bckSeedLinks($this->conn, $this->userId, $bckIban, 3);

    /** @var BlindIndexCodec $codec */
    $codec = app(BlindIndexCodec::class);
    app(CounterpartyKeyBackfill::class)->run($this->userId, $bckKeyHex);

    $rows = $this->conn->table('chain_links')->where('user_id', $this->userId)->orderBy('id')->get(['evidence']);

    $expected = [];
    for ($i = 0; $i < 3; $i++) {
        $derived = $codec->deriveWithKey(CounterpartyKey::DOMAIN, 'bck-merchant-'.$i, $this->userId, $bckKeyHex);
        $expected[] = [
            'matched_iban' => $bckIban,
            'event_type' => 'General Withdrawal',
            'signature_hash' => hash('sha256', $derived.'|'.$bckIban),
        ];
    }

    $actual = [];
    foreach ($rows as $row) {
        $actual[] = json_decode((string) $row->evidence, true, 512, JSON_THROW_ON_ERROR);
    }

    expect($actual)->toBe($expected);
});

it('leaves a link whose hash no reachable iban reproduces exactly as it found it', function () use ($bckSeedLinks, $bckIban, $bckKeyHex): void {
    $bckSeedLinks($this->conn, $this->userId, $bckIban, 1);

    $stranger = $this->conn->table('chain_links')->where('user_id', $this->userId)->value('id');
    $untouched = json_encode([
        'matched_via' => 'asn_alias_amount_date',
        'signature_hash' => hash('sha256', 'nothing-reproduces-this'),
    ], JSON_THROW_ON_ERROR);
    $this->conn->table('chain_links')->where('id', $stranger)->update(['evidence' => $untouched]);

    app(CounterpartyKeyBackfill::class)->run($this->userId, $bckKeyHex);

    expect($this->conn->table('chain_links')->where('id', $stranger)->value('evidence'))->toBe($untouched);
});

it('never reads a key off another readers transaction', function () use ($bckSeedLinks, $bckIban, $bckKeyHex): void {
    $bckSeedLinks($this->conn, $this->userId, $bckIban, 2);

    /** @var User $stranger */
    $stranger = User::query()->create([
        'username' => 'backfill-chain-link-stranger',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $borrowedTxId = $this->conn->table('transactions')->where('user_id', $this->userId)->orderBy('id')->value('id');
    $borrowedPartnerId = $this->conn->table('transactions')->where('user_id', $this->userId)->orderByDesc('id')->value('id');
    $strangerLinkId = $this->conn->table('chain_links')->insertGetId([
        'user_id' => (int) $stranger->id,
        'from_transaction_id' => $borrowedTxId,
        'to_transaction_id' => $borrowedPartnerId,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => '1.000',
        'resolver' => 'auto',
        'evidence' => json_encode([
            'matched_iban' => $bckIban,
            'signature_hash' => hash('sha256', 'bck-merchant-0|'.$bckIban),
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = $this->conn->table('chain_links')->where('id', $strangerLinkId)->value('evidence');

    app(CounterpartyKeyBackfill::class)->run((int) $stranger->id, $bckKeyHex);

    expect($this->conn->table('chain_links')->where('id', $strangerLinkId)->value('evidence'))->toBe($before);
});
