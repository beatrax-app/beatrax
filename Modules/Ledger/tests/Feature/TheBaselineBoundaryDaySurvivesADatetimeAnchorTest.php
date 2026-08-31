<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\AccountStartingBalanceQuery;

// Compared as stored, '2026-04-17' >= '2026-04-17 00:00:00' is FALSE and the
// whole boundary day drops out of every balance the raw predicate bounds. The
// cast no longer writes the long shape, but a peer replaying an op-log entry
// minted before it writes that entry's payload straight back.
beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'baseline-boundary',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    // Written through Eloquent on purpose: the model is the writer that decides
    // the stored shape, and a fixture reaching the column through the query
    // builder proves nothing about what the app produces.
    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Baseline card',
        'slug' => 'baseline-boundary-card',
        'kind' => 'ics_card',
        'iban' => 'BASELINE-BOUNDARY',
        'default_currency' => 'EUR',
        'starting_balance_minor' => -1000,
        'starting_balance_date' => '2026-04-17',
    ]);

    $this->db->connection()->table('import_runs')->insert([
        'id' => 9001,
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/baseline-boundary.pdf',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => '2026-04-20 00:00:00',
        'status' => 'confirmed',
    ]);

    $this->db->connection()->table('transactions')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'import_run_id' => 9001,
        'type' => 'expense',
        'status' => 'cleared',
        'posted_at' => '2026-04-17',
        'booked_at' => '2026-04-17 12:00:00',
        'value_date' => '2026-04-17',
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'boundary merchant',
        'normalization_version' => 1,
        'source_format' => 'ics-pdf',
        'source_row_index' => 0,
        'fingerprint' => str_repeat('c', 64),
        'fingerprint_version' => 3,
    ]);
});

it('stores the anchor as a bare day when it is written through the model', function (): void {
    $raw = $this->db->connection()
        ->table('accounts')
        ->where('id', $this->account->id)
        ->value('starting_balance_date');

    expect($raw)->toBe('2026-04-17');
});

it('counts a row posted on the anchor day through the raw baseline predicate', function (): void {
    $counted = $this->db->connection()
        ->table('transactions')
        ->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')
        ->where('transactions.user_id', $this->user->id)
        ->whereRaw(AccountStartingBalanceQuery::AT_OR_AFTER_BASELINE_SQL)
        ->count();

    expect($counted)->toBe(1);
});

it('counts the anchor day on an anchor that arrived carrying a time', function (): void {
    $this->db->connection()
        ->table('accounts')
        ->where('id', $this->account->id)
        ->update(['starting_balance_date' => '2026-04-17 00:00:00']);

    $counted = $this->db->connection()
        ->table('transactions')
        ->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')
        ->where('transactions.user_id', $this->user->id)
        ->whereRaw(AccountStartingBalanceQuery::AT_OR_AFTER_BASELINE_SQL)
        ->count();

    expect($counted)->toBe(1);
});
