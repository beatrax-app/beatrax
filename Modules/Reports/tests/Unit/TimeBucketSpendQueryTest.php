<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\TimeBucketSpendQuery;

uses(RefreshDatabase::class);

/*
 * Covers 999.6-04 Task 3 / Req 2/3/6/7: TimeBucketSpendQuery loops
 * TimeBucketGenerator's output and produces exactly one ReportResultRow per
 * generated sub-Period, including zero-activity buckets. Fixture helpers
 * prefixed tbq_ to avoid cross-file global-function collisions.
 */

function tbqUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'tbq-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function tbqAccount(User $user): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'tbq-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'asn',
        'iban' => 'NL00TBQ'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function tbqImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tbq-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'tbq-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tbqTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => tbqImportRun($db, $user),
        'type' => 'expense',
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 10:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'TBQ Vendor',
        'counterparty_normalized' => 'tbq-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => null,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'tbq-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

it('produces one grouped total per generated monthly bucket, including zero-activity months', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tbqUser();
    $account = tbqAccount($user);

    tbqTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -5_000, 'posted_at' => '2026-01-10', 'booked_at' => '2026-01-10 10:00:00', 'value_date' => '2026-01-10']);
    tbqTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -2_000, 'posted_at' => '2026-03-20', 'booked_at' => '2026-03-20 10:00:00', 'value_date' => '2026-03-20']);
    // February has zero activity and must still appear as its own row.

    $period = new Period(
        start: CarbonImmutable::parse('2026-01-01'),
        endExclusive: CarbonImmutable::parse('2026-04-01'),
        label: 'Q1 2026',
    );

    $rows = app(TimeBucketSpendQuery::class)->forUserAndPeriod($user, $period, 'spend', 'EUR', 'monthly');

    expect($rows)->toHaveCount(3);
    expect($rows[0]->amountMinor)->toBe(5_000);
    expect($rows[1]->amountMinor)->toBe(0);
    expect($rows[2]->amountMinor)->toBe(2_000);
    expect(array_sum(array_map(fn ($r) => $r->amountMinor, $rows)))->toBe(7_000);
});
