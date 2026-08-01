<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tax\Public\Dto\TaxYearData;
use Modules\Tax\Public\Services\TaxYearQuery;

uses(RefreshDatabase::class);

/*
 * Unit tests for TaxYearQuery — the grouped tax-year query service.
 *
 * Tests cover:
 *  - Expense-type tagged transactions counted in deductionsTotalMinor.
 *  - Income-type tagged transactions counted in incomeTotalMinor.
 *  - COALESCE tax_year_override resolution (D-10, T-07-07).
 *  - Category grouping with per-group subtotals.
 *  - "No category" trailing group for unclassified rows.
 *  - Cross-user isolation (T-07-05).
 *  - availableYears distinct + descending.
 *  - Empty result when no tags exist for a year.
 */

// ---------------------------------------------------------------------------
// Shared fixture helpers
// ---------------------------------------------------------------------------

/**
 * Inserts a minimal user row and returns its id.
 */
function taqUser(DatabaseManager $db, string $username): int
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
 * Inserts an account + import run + transaction for the given user and
 * returns the transaction id. Accepts extra transaction column overrides.
 *
 * @param  array<string, mixed>  $overrides
 */
function taqTransaction(
    DatabaseManager $db,
    int $userId,
    array $overrides = [],
): int {
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TAQ ASN '.$suffix,
        'slug' => 'taq-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/taq-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'taq-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $defaults = [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'taq-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2025-03-01',
        'booked_at' => '2025-03-01 00:00:00',
        'value_date' => '2025-03-01',
        'amount_minor' => -4990,
        'currency' => 'EUR',
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'taq-vendor',
        'counterparty_name' => 'TAQ Vendor BV',
        'normalization_version' => 1,
        'description' => 'TAQ test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(
        array_merge($defaults, $overrides),
    );
}

/**
 * Inserts a deduction category for the user and returns its id.
 */
function taqCategory(DatabaseManager $db, int $userId, string $name = 'Zorgkosten', int $sortOrder = 0): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'short_name' => substr($name, 0, 3),
        'status' => 'active',
        'sort_order' => $sortOrder,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Inserts a tax_transaction_tags row and returns the tag id.
 *
 * @param  array<string, mixed>  $overrides
 */
function taqTag(DatabaseManager $db, int $userId, int $txId, ?int $catId = null, array $overrides = []): int
{
    $defaults = [
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => $catId,
        'tax_year_override' => null,
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('tax_transaction_tags')->insertGetId(
        array_merge($defaults, $overrides),
    );
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('counts an expense-tagged transaction in deductionsTotalMinor', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taqUser($db, 'taq-expense');
    $txId = taqTransaction($db, $userId, [
        'type' => 'expense',
        'settled_amount_minor' => -9900,
        'booked_at' => '2025-03-01 00:00:00',
    ]);
    $catId = taqCategory($db, $userId, 'Zorgkosten');
    taqTag($db, $userId, $txId, $catId);

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $result = $query->forUser($userId, 2025);

    expect($result)->toBeInstanceOf(TaxYearData::class)
        ->and($result->deductionsTotalMinor)->toBe(9900)
        ->and($result->incomeTotalMinor)->toBe(0)
        ->and($result->itemCount)->toBe(1);
});

it('counts an income-tagged transaction in incomeTotalMinor', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taqUser($db, 'taq-income');
    $txId = taqTransaction($db, $userId, [
        'type' => 'income',
        'settled_amount_minor' => 5000,
        'booked_at' => '2025-06-01 00:00:00',
    ]);
    $catId = taqCategory($db, $userId, 'Freelance');
    taqTag($db, $userId, $txId, $catId);

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $result = $query->forUser($userId, 2025);

    expect($result->incomeTotalMinor)->toBe(5000)
        ->and($result->deductionsTotalMinor)->toBe(0);
});

it('applies COALESCE tax_year_override when determining the tax year', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taqUser($db, 'taq-override');

    // Transaction booked in 2026 but overridden to tax year 2025
    $txId = taqTransaction($db, $userId, [
        'booked_at' => '2026-01-10 00:00:00',
        'type' => 'expense',
        'settled_amount_minor' => -3300,
    ]);
    $catId = taqCategory($db, $userId, 'Override Cat');
    taqTag($db, $userId, $txId, $catId, ['tax_year_override' => 2025]);

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);

    // Must appear in 2025 (override)
    $result2025 = $query->forUser($userId, 2025);
    expect($result2025->itemCount)->toBe(1)
        ->and($result2025->deductionsTotalMinor)->toBe(3300);

    // Must NOT appear in 2026 (booked year)
    $result2026 = $query->forUser($userId, 2026);
    expect($result2026->itemCount)->toBe(0);
});

it('groups rows by category with per-group subtotals', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taqUser($db, 'taq-groups');

    $cat1 = taqCategory($db, $userId, 'Cat Alpha', 1);
    $cat2 = taqCategory($db, $userId, 'Cat Beta', 2);

    $tx1 = taqTransaction($db, $userId, ['settled_amount_minor' => -1000, 'type' => 'expense', 'booked_at' => '2025-02-01 00:00:00']);
    $tx2 = taqTransaction($db, $userId, ['settled_amount_minor' => -2000, 'type' => 'expense', 'booked_at' => '2025-03-01 00:00:00']);
    $tx3 = taqTransaction($db, $userId, ['settled_amount_minor' => -500, 'type' => 'expense', 'booked_at' => '2025-04-01 00:00:00']);

    taqTag($db, $userId, $tx1, $cat1);
    taqTag($db, $userId, $tx2, $cat1);
    taqTag($db, $userId, $tx3, $cat2);

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $result = $query->forUser($userId, 2025);

    expect($result->itemCount)->toBe(3)
        ->and($result->deductionsTotalMinor)->toBe(3500);

    // Two category groups
    expect(count($result->categories))->toBe(2);

    // Find Alpha group
    $alpha = collect($result->categories)->firstWhere('name', 'Cat Alpha');
    expect($alpha)->not->toBeNull()
        ->and($alpha['subtotalMinor'])->toBe(3000)
        ->and(count($alpha['rows']))->toBe(2);

    // Find Beta group
    $beta = collect($result->categories)->firstWhere('name', 'Cat Beta');
    expect($beta)->not->toBeNull()
        ->and($beta['subtotalMinor'])->toBe(500)
        ->and(count($beta['rows']))->toBe(1);
});

it('places rows with no deduction_category in a trailing no-category group', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taqUser($db, 'taq-nocat');
    $catId = taqCategory($db, $userId, 'HasCat');

    $txCat = taqTransaction($db, $userId, ['settled_amount_minor' => -1000, 'type' => 'expense', 'booked_at' => '2025-01-01 00:00:00']);
    $txNoCat = taqTransaction($db, $userId, ['settled_amount_minor' => -500, 'type' => 'expense', 'booked_at' => '2025-02-01 00:00:00']);

    taqTag($db, $userId, $txCat, $catId);
    taqTag($db, $userId, $txNoCat, null);   // no category

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $result = $query->forUser($userId, 2025);

    expect($result->itemCount)->toBe(2);

    // Last group should be the "no category" trailing group
    $lastGroup = $result->categories[array_key_last($result->categories)];
    expect($lastGroup['id'])->toBeNull();
    expect(count($lastGroup['rows']))->toBe(1);
});

it('never returns tagged rows belonging to another user', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = taqUser($db, 'taq-iso-owner');
    $intruder = taqUser($db, 'taq-iso-intruder');

    $catId = taqCategory($db, $owner, 'Owner Cat');
    $txId = taqTransaction($db, $owner, ['booked_at' => '2025-05-01 00:00:00', 'type' => 'expense']);
    taqTag($db, $owner, $txId, $catId);

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $result = $query->forUser($intruder, 2025);

    expect($result->itemCount)->toBe(0)
        ->and($result->categories)->toBe([]);
});

it('returns empty TaxYearData when the user has no tags for a year', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taqUser($db, 'taq-empty');

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $result = $query->forUser($userId, 2025);

    expect($result)->toBeInstanceOf(TaxYearData::class)
        ->and($result->itemCount)->toBe(0)
        ->and($result->deductionsTotalMinor)->toBe(0)
        ->and($result->incomeTotalMinor)->toBe(0)
        ->and($result->categories)->toBe([]);
});

it('availableYears returns distinct effective tax years in descending order', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taqUser($db, 'taq-avail-years');
    $catId = taqCategory($db, $userId, 'AYCat');

    // Transactions booked in different years
    $tx2024 = taqTransaction($db, $userId, ['booked_at' => '2024-06-01 00:00:00', 'type' => 'expense']);
    $tx2025 = taqTransaction($db, $userId, ['booked_at' => '2025-06-01 00:00:00', 'type' => 'expense']);
    // tx2023 via override
    $tx2026 = taqTransaction($db, $userId, ['booked_at' => '2026-01-01 00:00:00', 'type' => 'expense']);

    taqTag($db, $userId, $tx2024, $catId);
    taqTag($db, $userId, $tx2025, $catId);
    // Override to 2023
    taqTag($db, $userId, $tx2026, $catId, ['tax_year_override' => 2023]);

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $years = $query->availableYears($userId);

    expect($years)->toBe([2025, 2024, 2023]);
});
