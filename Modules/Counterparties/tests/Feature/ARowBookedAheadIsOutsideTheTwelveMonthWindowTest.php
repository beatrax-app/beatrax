<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;

// "12 mo" is twelve whole calendar months ending with the one in progress, and
// the ledger deliberately holds rows whose posted_at is still ahead. Filtered
// from the opening day with nothing above it, next month's direct debit was
// counted in the headline, in the average, and in no bar at all.

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function aheadUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  list<array{0: string, 1: int}>  $rows
 */
function aheadLedger(User $user, array $rows): int
{
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Ahead ASN',
        'slug' => 'ahead-asn-'.$user->id,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ahead-'.$user->id.'.csv',
        'sha256' => hash('sha256', 'ahead-'.$user->id),
        'uploaded_at' => '2020-01-01 00:00:00',
        'status' => 'committed',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    $counterpartyId = DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'ahead-merchant',
        'display_name' => 'Ahead Merchant',
        'merchant_name' => 'Ahead Merchant',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    foreach ($rows as $index => [$postedAt, $amountMinor]) {
        DB::table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'counterparty_id' => $counterpartyId,
            'fingerprint' => hash('sha256', 'ahead-'.$user->id.'-'.$index),
            'fingerprint_version' => 3,
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'type' => 'expense',
            'amount_minor' => $amountMinor,
            'currency' => 'EUR',
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Ahead Merchant',
            'counterparty_normalized' => 'ahead merchant',
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'source_row_index' => $index,
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);
    }

    return $counterpartyId;
}

it('leaves a booked future direct debit out of the index total, average and bars', function (): void {
    CarbonImmutable::setTestNow('2026-08-01 12:00:00');

    $user = aheadUser('ahead-index');
    aheadLedger($user, [['2026-08-01', -1000], ['2026-09-15', -5000]]);

    $row = app(CounterpartyIndexQuery::class)->forUser($user)->firstOrFail();

    expect($row->total12mMinor)->toBe(-1000)
        ->and($row->avgPerMonthMinor)->toBe(-83)
        ->and(array_sum($row->sparkline))->toBe($row->total12mMinor);
});

it('leaves it out of the profile headline the index total has to agree with', function (): void {
    CarbonImmutable::setTestNow('2026-08-01 12:00:00');

    $user = aheadUser('ahead-profile');
    aheadLedger($user, [['2026-08-01', -1000], ['2026-09-15', -5000]]);

    $profile = app(CounterpartyProfileQuery::class)->bySlug($user, 'ahead-merchant');

    expect($profile)->not->toBeNull()
        ->and($profile->total12mMinor)->toBe(-1000);
});

it('leaves it out of the profile category breakdown', function (): void {
    CarbonImmutable::setTestNow('2026-08-01 12:00:00');

    $user = aheadUser('ahead-breakdown');
    aheadLedger($user, [['2026-08-01', -1000], ['2026-09-15', -5000]]);

    /** @var Counterparty $counterparty */
    $counterparty = Counterparty::query()->where('user_id', $user->id)->where('slug', 'ahead-merchant')->firstOrFail();

    $breakdown = app(CounterpartyProfileQuery::class)->categoryBreakdown($counterparty, $user);

    expect($breakdown)->toHaveCount(1)
        ->and((int) $breakdown->firstOrFail()->total_minor)->toBe(-1000);
});

// The last day of the month in progress is inside the window, not past it: a
// bound written as "before today" would drop the rest of this month's spend.
it('keeps a row dated later this month, on the first of that month', function (): void {
    CarbonImmutable::setTestNow('2026-08-01 12:00:00');

    $user = aheadUser('ahead-edge');
    aheadLedger($user, [['2026-08-31', -1000]]);

    $row = app(CounterpartyIndexQuery::class)->forUser($user)->firstOrFail();

    expect($row->total12mMinor)->toBe(-1000)
        ->and(array_sum($row->sparkline))->toBe(-1000);
});
