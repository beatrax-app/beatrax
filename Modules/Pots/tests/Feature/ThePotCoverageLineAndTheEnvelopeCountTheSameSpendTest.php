<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotBalanceQuery;

// The pot card's coverage line and the budgets grid both answer "what did I
// spend in this category this period". They are read from two different
// queries, and the pot's own one answered a different question.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 09:00:00'));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::create([
        'username' => 'pot-vs-envelope',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 17,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    $this->accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id, 'name' => 'ASN', 'slug' => 'pve-asn', 'kind' => 'bank',
        'iban' => 'NL57PVEB0123456789', 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $this->runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->user->id, 'source_format' => 'camt053', 'raw_file_path' => '/tmp/pve.xml',
        'sha256' => hash('sha256', 'pve'), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $this->groceries = $db->connection()->table('categories')->insertGetId([
        'user_id' => $this->user->id, 'name' => 'Groceries', 'slug' => 'pve-groceries', 'kind' => 'expense',
        'display_order' => 100, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $this->household = $db->connection()->table('categories')->insertGetId([
        'user_id' => $this->user->id, 'name' => 'Household', 'slug' => 'pve-household', 'kind' => 'expense',
        'display_order' => 101, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function pveTx(DatabaseManager $db, int $userId, int $accountId, int $runId, ?int $categoryId, int $signedMinor, string $type, string $postedAt): int
{
    $hex = bin2hex(random_bytes(5));

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'category_id' => $categoryId,
        'fingerprint' => hash('sha256', 'pve-'.$hex), 'fingerprint_version' => 3,
        'posted_at' => $postedAt, 'booked_at' => $postedAt.' 09:00:00', 'value_date' => $postedAt,
        'amount_minor' => $signedMinor, 'currency' => Currency::Eur->value,
        'settled_amount_minor' => $signedMinor, 'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'Shop', 'counterparty_normalized' => 'shop', 'normalization_version' => 1,
        'type' => $type, 'source_format' => 'camt053', 'source_row_index' => random_int(1, 999999),
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function pveLinkedPot(int $userId, int $accountId, int $categoryId): void
{
    Pot::factory()->create([
        'user_id' => $userId, 'account_id' => $accountId,
        'name' => 'Buffer', 'category_id' => $categoryId, 'currency' => Currency::Eur->value,
    ]);
}

function pveCoverageMinor(User $user): ?int
{
    $row = collect(app(PotBalanceQuery::class)->forUser($user))->firstWhere('name', 'Buffer');

    return $row?->categorySpentMinor;
}

function pveLedgerSpendMinor(User $user, int $categoryId): int
{
    $period = app(PeriodQuery::class)->containingForUser($user, CarbonImmutable::now());
    $byKey = app(SpendByCategoryQuery::class)->forUserAndPeriodByCurrency($user->id, $period);

    return $byKey[$categoryId.'|'.Currency::Eur->value] ?? 0;
}

it('nets a refund out of the coverage line, as every other spend surface does', function (): void {
    pveLinkedPot($this->user->id, $this->accountId, $this->groceries);
    pveTx($this->db, $this->user->id, $this->accountId, $this->runId, $this->groceries, -10000, 'expense', '2026-08-05');
    pveTx($this->db, $this->user->id, $this->accountId, $this->runId, $this->groceries, 3000, 'refund', '2026-08-06');

    expect(pveCoverageMinor($this->user))->toBe(pveLedgerSpendMinor($this->user, $this->groceries))
        ->and(pveCoverageMinor($this->user))->toBe(7000);
});

it('counts a split leg the way the budgets grid counts it', function (): void {
    pveLinkedPot($this->user->id, $this->accountId, $this->groceries);
    // SaveTransactionSplit leaves a split parent's own category_id null.
    $parentId = pveTx($this->db, $this->user->id, $this->accountId, $this->runId, null, -8000, 'expense', '2026-08-05');
    foreach ([[$this->groceries, -6000], [$this->household, -2000]] as [$categoryId, $minor]) {
        $this->db->connection()->table('transaction_splits')->insert([
            'user_id' => $this->user->id, 'transaction_id' => $parentId, 'category_id' => $categoryId,
            'settled_amount_minor' => $minor, 'settled_currency' => Currency::Eur->value,
            'sort_order' => 0, 'created_at' => '2026-08-05 09:00:00', 'updated_at' => '2026-08-05 09:00:00',
        ]);
    }

    expect(pveCoverageMinor($this->user))->toBe(pveLedgerSpendMinor($this->user, $this->groceries))
        ->and(pveCoverageMinor($this->user))->toBe(6000);
});

it('leaves an internal transfer out of the coverage line', function (): void {
    pveLinkedPot($this->user->id, $this->accountId, $this->groceries);
    pveTx($this->db, $this->user->id, $this->accountId, $this->runId, $this->groceries, -2500, 'expense', '2026-08-05');
    pveTx($this->db, $this->user->id, $this->accountId, $this->runId, $this->groceries, -9900, 'transfer_out', '2026-08-06');

    expect(pveCoverageMinor($this->user))->toBe(pveLedgerSpendMinor($this->user, $this->groceries))
        ->and(pveCoverageMinor($this->user))->toBe(2500);
});

it('reads the coverage window off the pot owner rather than off whoever is browsing', function (): void {
    pveLinkedPot($this->user->id, $this->accountId, $this->groceries);
    // 2026-07-20 is inside the owner's day-17 period and outside a day-1 one.
    pveTx($this->db, $this->user->id, $this->accountId, $this->runId, $this->groceries, -4400, 'expense', '2026-07-20');

    $browser = User::create([
        'username' => 'pot-vs-envelope-browser',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($browser);

    expect(pveCoverageMinor($this->user))->toBe(4400);
});

it('counts the last day of the period and not the first day of the next', function (): void {
    pveLinkedPot($this->user->id, $this->accountId, $this->groceries);
    $period = app(PeriodQuery::class)->containingForUser($this->user, CarbonImmutable::now());
    pveTx($this->db, $this->user->id, $this->accountId, $this->runId, $this->groceries, -1100, 'expense', $period->start->toDateString());
    pveTx($this->db, $this->user->id, $this->accountId, $this->runId, $this->groceries, -2200, 'expense', $period->endExclusive->subDay()->toDateString());
    pveTx($this->db, $this->user->id, $this->accountId, $this->runId, $this->groceries, -4400, 'expense', $period->endExclusive->toDateString());
    pveTx($this->db, $this->user->id, $this->accountId, $this->runId, $this->groceries, -8800, 'expense', $period->start->subDay()->toDateString());

    expect(pveCoverageMinor($this->user))->toBe(3300);
});
