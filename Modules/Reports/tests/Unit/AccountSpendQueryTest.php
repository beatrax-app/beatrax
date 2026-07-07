<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\AccountSpendQuery;

uses(RefreshDatabase::class);

/*
 * Covers 999.6-04 Task 3 / Req 2/3: AccountSpendQuery's canonical
 * type-based single-table aggregation. Fixture helpers prefixed acq_ to
 * avoid cross-file global-function collisions.
 */

function acqUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'acq-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function acqAccount(User $user, string $name): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => 'acq-'.strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'asn',
        'iban' => 'NL00ACQ'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function acqImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/acq-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'acq-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function acqTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => acqImportRun($db, $user),
        'type' => 'expense',
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 10:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'ACQ Vendor',
        'counterparty_normalized' => 'acq-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => null,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'acq-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

function acqPeriod(): Period
{
    return new Period(
        start: CarbonImmutable::parse('2026-03-01'),
        endExclusive: CarbonImmutable::parse('2026-04-01'),
        label: 'March 2026',
    );
}

it('groups spend/income/net by account using the canonical type-based definition, excluding transfers', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = acqUser();
    $asn = acqAccount($user, 'ASN');
    $ics = acqAccount($user, 'ICS');

    acqTransaction($db, $user, $asn, ['type' => 'expense', 'settled_amount_minor' => -12_000]);
    acqTransaction($db, $user, $ics, ['type' => 'expense', 'settled_amount_minor' => -3_000]);
    acqTransaction($db, $user, $asn, ['type' => 'income', 'settled_amount_minor' => 50_000]);
    // Internal move between own accounts — must contribute 0.
    acqTransaction($db, $user, $asn, ['type' => 'transfer_out', 'settled_amount_minor' => -20_000]);

    $query = app(AccountSpendQuery::class);
    $period = acqPeriod();

    $spend = $query->forUserAndPeriod($user, $period, 'spend', 'EUR');
    $byLabel = [];
    foreach ($spend as $row) {
        $byLabel[$row->groupLabel] = $row->amountMinor;
    }
    expect($byLabel['ASN'])->toBe(12_000);
    expect($byLabel['ICS'])->toBe(3_000);
    expect(array_sum(array_map(fn ($r) => $r->amountMinor, $spend)))->toBe(15_000);

    $income = $query->forUserAndPeriod($user, $period, 'income', 'EUR');
    expect(array_sum(array_map(fn ($r) => $r->amountMinor, $income)))->toBe(50_000);

    $net = $query->forUserAndPeriod($user, $period, 'net', 'EUR');
    expect(array_sum(array_map(fn ($r) => $r->amountMinor, $net)))->toBe(35_000);
});

it('scopes account labels to the requesting user only', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = acqUser();
    $account = acqAccount($user, 'ASN');

    acqTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -1_500]);

    $rows = app(AccountSpendQuery::class)->forUserAndPeriod($user, acqPeriod(), 'spend', 'EUR');

    expect($rows)->toHaveCount(1);
    expect($rows[0]->groupKey)->toBe($account->id);
    expect($rows[0]->groupLabel)->toBe('ASN');
});
