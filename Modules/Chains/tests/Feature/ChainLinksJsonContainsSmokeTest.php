<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// JSON1 is not guaranteed by every SQLite build, and the auto-promotion
// counter walks chain_links by evidence->signature_hash. Both the
// whereJsonContains form and the json_extract fallback are pinned here.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->conn = $db->connection();

    $this->user = User::query()->create([
        'username' => 'chain-json-smoke',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->asn = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN smoke',
        'slug' => 'cjs-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->ics = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS smoke',
        'slug' => 'cjs-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cjs.csv',
        'sha256' => str_repeat('j', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->fromTx = jsonSmokeTx($this->user, $this->asn, $this->run, 1);
    $this->toTx = jsonSmokeTx($this->user, $this->ics, $this->run, 2);
});

function jsonSmokeTx(User $user, Account $account, ImportRun $run, int $index): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1500 - $index,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500 - $index,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'JSON Smoke '.$index,
        'counterparty_normalized' => 'json-smoke-'.$index,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $index,
        'fingerprint' => str_pad((string) $index, 64, 'j', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

function seedJsonSmokeChainLink(Connection $conn, int $userId, int $fromId, int $toId): void
{
    $conn->table('chain_links')->insert([
        'user_id' => $userId,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => 'paypal_funding',
        'state' => 'candidate',
        'confidence' => 0.75,
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => 'abc123']),
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);
}

it('whereJsonContains finds a chain_link by evidence->signature_hash on this SQLite build', function (): void {
    seedJsonSmokeChainLink($this->conn, $this->user->id, $this->fromTx->id, $this->toTx->id);

    expect($this->conn->table('chain_links')
        ->whereJsonContains('evidence->signature_hash', 'abc123')
        ->exists())->toBeTrue();
});

it('whereRaw json_extract fallback also finds the row', function (): void {
    seedJsonSmokeChainLink($this->conn, $this->user->id, $this->fromTx->id, $this->toTx->id);

    $hash = 'abc123';
    expect((bool) $this->conn
        ->table('chain_links')
        ->whereRaw('json_extract(evidence, ?) = ?', ['$.signature_hash', $hash])
        ->exists())->toBeTrue();
});
