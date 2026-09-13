<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Public\Enums\ClearedStatus;

uses(RefreshDatabase::class);

// A reconcile freezes exactly the classification a tag is, so UntagTransaction
// refuses one — and the sweep counted the list it had been handed instead of
// the writes that landed, printing "Removed 2 tag(s)." over a tag still sitting
// in the table. The same shape the batch tag banner was fixed for.

// Its own fixtures: Pest shares one global namespace across the run, so
// borrowing the sibling file's makes this one pass only in its shard.
function sweptUser(DatabaseManager $db): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => 'swept-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture-password-12chars'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function sweptTaggedRow(DatabaseManager $db, int $userId, string $status): int
{
    $hex = bin2hex(random_bytes(5));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN '.$hex, 'slug' => 'swpt-'.$hex,
        'kind' => 'bank', 'iban' => 'NL00SWPT'.strtoupper($hex), 'default_currency' => 'EUR',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/swpt-'.$hex.'.csv',
        'sha256' => hash('sha256', 'swpt-'.$hex), 'uploaded_at' => now(), 'status' => 'committed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A transfer carries no deduction, which is what puts it on the sweep's
    // list; the status is what decides whether the write is allowed to land.
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'swpt-tx-'.$hex),
        'posted_at' => '2026-04-10', 'booked_at' => '2026-04-10 12:00:00', 'value_date' => '2026-04-10',
        'amount_minor' => -50_000, 'currency' => 'EUR',
        'settled_amount_minor' => -50_000, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'swept vendor', 'counterparty_name' => 'Swept Vendor BV',
        'normalization_version' => 1, 'description' => 'Row under test',
        'type' => 'transfer_out', 'payment_type' => 'transfer', 'status' => $status,
        'source_format' => 'asn-csv', 'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => null,
        'note' => null,
        'tax_year_override' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $txId;
}

it('counts the removals that landed, not the rows it listed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = sweptUser($db);

    sweptTaggedRow($db, $userId, ClearedStatus::Cleared->value);
    $frozen = sweptTaggedRow($db, $userId, ClearedStatus::Reconciled->value);

    $this->artisan('tax:sweep-untaggable', ['--apply' => true])
        ->expectsOutputToContain('Removed 1 tag(s).')
        ->expectsOutputToContain('1 tag(s) were refused')
        ->assertSuccessful();

    expect($db->connection()->table('tax_transaction_tags')->pluck('transaction_id')->all())->toBe([$frozen]);
});
