<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Tax\Public\Services\TaxTagQuery;

function tstUser(DatabaseManager $db, string $username): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tstTransaction(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TST ASN '.$suffix,
        'slug' => 'tst-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tst-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tst-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $defaults = [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tst-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2025-03-01',
        'booked_at' => '2025-03-01 00:00:00',
        'value_date' => '2025-03-01',
        'amount_minor' => -4990,
        'currency' => 'EUR',
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'tst-vendor',
        'counterparty_name' => 'TST Vendor BV',
        'normalization_version' => 1,
        'description' => 'TST test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

function tstTag(DatabaseManager $db, int $userId, int $txId): void
{
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => null,
        'tax_year_override' => null,
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('sums tagged deductions as a magnitude, so no reader of the total has to strip a sign', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tstUser($db, 'tst-magnitude');
    tstTag($db, $userId, tstTransaction($db, $userId, ['settled_amount_minor' => -4990]));
    tstTag($db, $userId, tstTransaction($db, $userId, ['settled_amount_minor' => -1500]));

    $summary = app(TaxTagQuery::class)->summaryForUser($userId, 2025);

    expect($summary->totalMinor)->toBe(6490)
        ->and($summary->count)->toBe(2);
});

it('counts an income tag but adds nothing to the deductions total', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tstUser($db, 'tst-income');
    tstTag($db, $userId, tstTransaction($db, $userId, [
        'settled_amount_minor' => 250000,
        'amount_minor' => 250000,
        'type' => 'income',
    ]));

    $summary = app(TaxTagQuery::class)->summaryForUser($userId, 2025);

    expect($summary->totalMinor)->toBe(0)
        ->and($summary->count)->toBe(1);
});

it('reports zero rather than a negative for a year with nothing tagged', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $summary = app(TaxTagQuery::class)->summaryForUser(tstUser($db, 'tst-empty'), 2025);

    expect($summary->totalMinor)->toBe(0)
        ->and($summary->count)->toBe(0);
});
