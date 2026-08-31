<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Services\TaxCsvExporter;

uses(RefreshDatabase::class);

const COA_SETTLED_AMOUNT = 8;

const COA_ORIGINAL_AMOUNT = 10;

function coaUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function coaTransaction(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'COA ASN '.$suffix,
        'slug' => 'coa-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/coa-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'coa-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $defaults = [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'coa-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-02-10',
        'booked_at' => '2026-02-10 00:00:00',
        'value_date' => '2026-02-10',
        'amount_minor' => -12450,
        'currency' => 'EUR',
        'settled_amount_minor' => -12450,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'coa-vendor',
        'counterparty_name' => 'COA Vendor BV',
        'normalization_version' => 1,
        'description' => 'COA test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

function coaLeg(DatabaseManager $db, int $userId, int $txId, string $categoryName, int $settledAmountMinor, int $sortOrder = 0): int
{
    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => $categoryName,
        'slug' => strtolower($categoryName).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'category_id' => $categoryId,
        'settled_amount_minor' => $settledAmountMinor,
        'settled_currency' => 'EUR',
        'note' => null,
        'sort_order' => $sortOrder,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function coaTag(DatabaseManager $db, int $userId, int $txId, ?int $splitId = null): void
{
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'transaction_split_id' => $splitId,
        'deduction_category_id' => null,
        'note' => null,
        'tax_year_override' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return list<string>
 */
function coaFirstDataRow(User $user, int $year): array
{
    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $lines = array_values(array_filter(explode("\n", trim($exporter->export($user, $year)))));

    /** @var list<string> */
    return str_getcsv($lines[1]);
}

it('reports the leg amount in original_amount, not the whole parent the accountant would then over-claim', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = coaUser('coa-leg-original');
    $txId = coaTransaction($db, $user->id);
    $legId = coaLeg($db, $user->id, $txId, 'Groceries', -2450, 0);
    coaLeg($db, $user->id, $txId, 'Household', -10000, 1);
    coaTag($db, $user->id, $txId, $legId);

    $row = coaFirstDataRow($user, 2026);

    expect($row[COA_SETTLED_AMOUNT])->toBe('24.50')
        ->and($row[COA_ORIGINAL_AMOUNT])->toBe('24.50');
});

it('scales the original amount to the leg when the transaction settled in another currency', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = coaUser('coa-leg-foreign');
    $txId = coaTransaction($db, $user->id, [
        'amount_minor' => -10000,
        'currency' => 'USD',
        'settled_amount_minor' => -9000,
        'settled_currency' => 'EUR',
    ]);
    $legId = coaLeg($db, $user->id, $txId, 'Software', -3000, 0);
    coaLeg($db, $user->id, $txId, 'Hardware', -6000, 1);
    coaTag($db, $user->id, $txId, $legId);

    $row = coaFirstDataRow($user, 2026);

    // A third of a €90 settlement is a third of the $100 that was charged.
    expect($row[COA_SETTLED_AMOUNT])->toBe('30.00')
        ->and($row[COA_ORIGINAL_AMOUNT])->toBe('33.33');
});

it('still reports the whole native amount for a whole-transaction tag on a foreign charge', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = coaUser('coa-whole-foreign');
    coaTag($db, $user->id, coaTransaction($db, $user->id, [
        'amount_minor' => -10000,
        'currency' => 'USD',
        'settled_amount_minor' => -9000,
        'settled_currency' => 'EUR',
    ]));

    $row = coaFirstDataRow($user, 2026);

    expect($row[COA_SETTLED_AMOUNT])->toBe('90.00')
        ->and($row[COA_ORIGINAL_AMOUNT])->toBe('100.00');
});

it('reports the whole native amount for a whole-transaction tag on an unsplit euro transaction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = coaUser('coa-whole-euro');
    coaTag($db, $user->id, coaTransaction($db, $user->id));

    $row = coaFirstDataRow($user, 2026);

    expect($row[COA_SETTLED_AMOUNT])->toBe('124.50')
        ->and($row[COA_ORIGINAL_AMOUNT])->toBe('124.50');
});
