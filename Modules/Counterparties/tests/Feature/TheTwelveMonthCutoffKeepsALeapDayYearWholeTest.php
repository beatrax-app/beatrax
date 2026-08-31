<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Support\RollingTwelveMonths;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;

// Every "12m" figure a counterparty carries is one window: the headline total,
// the average it is divided by, the twelve sparkline bars and the profile's
// category breakdown. Taken as a rolling year on the totals and as twelve
// calendar months on the bars, the total counted days no bar could draw.
//
// subMonths() off a day the target month does not have rolls FORWARD, and a
// later startOfMonth() cannot undo it — on 31 January the window opened a
// whole month late — so the boundary is asserted on both traps.

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function leapCutoffUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  list<string>  $postedAts
 */
function leapCutoffLedger(User $user, array $postedAts, int $amountMinor): void
{
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Leap ASN',
        'slug' => 'leap-asn-'.$user->id,
        'kind' => 'bank',
        'iban' => 'NL00LEA'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/leap-'.$user->id.'.csv',
        'sha256' => hash('sha256', 'leap-'.$user->id),
        'uploaded_at' => '2020-01-01 00:00:00',
        'status' => 'committed',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    $counterpartyId = DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'leap-merchant',
        'display_name' => 'Leap Merchant',
        'merchant_name' => 'Leap Merchant',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    foreach ($postedAts as $index => $postedAt) {
        DB::table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'counterparty_id' => $counterpartyId,
            'fingerprint' => hash('sha256', 'leap-'.$user->id.'-'.$index),
            'fingerprint_version' => 3,
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'type' => 'expense',
            'amount_minor' => $amountMinor,
            'currency' => 'EUR',
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Leap Merchant',
            'counterparty_normalized' => 'leap merchant',
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'source_row_index' => $index,
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);
    }
}

it('opens the window on the first of the oldest month, on 29 February', function (): void {
    CarbonImmutable::setTestNow('2028-02-29 12:00:00');

    $user = leapCutoffUser('leap-index');
    leapCutoffLedger($user, [RollingTwelveMonths::startDate(CarbonImmutable::now())], -4200);

    $row = app(CounterpartyIndexQuery::class)->forUser($user)->firstOrFail();

    expect($row->total12mMinor)->toBe(-4200);
});

// 31 January is where subMonths(11) overflows and NoOverflow does not: the
// window must open on 1 February of the year before, not on 1 March.
it('does not lose a month to an overflowing step, opened on 31 January', function (): void {
    CarbonImmutable::setTestNow('2028-01-31 12:00:00');

    expect(RollingTwelveMonths::startDate(CarbonImmutable::now()))->toBe('2027-02-01');

    $user = leapCutoffUser('leap-overflow');
    leapCutoffLedger($user, ['2027-02-01'], -4200);

    $row = app(CounterpartyIndexQuery::class)->forUser($user)->firstOrFail();

    expect($row->total12mMinor)->toBe(-4200);
});

it('counts the same day on the profile it counts on the index', function (): void {
    CarbonImmutable::setTestNow('2028-02-29 12:00:00');

    $user = leapCutoffUser('leap-profile');
    leapCutoffLedger($user, [RollingTwelveMonths::startDate(CarbonImmutable::now())], -4200);

    $profile = app(CounterpartyProfileQuery::class)->bySlug($user, 'leap-merchant');

    expect($profile)->not->toBeNull()
        ->and($profile->total12mMinor)->toBe(-4200);
});

it('counts the same day in the profile category breakdown', function (): void {
    CarbonImmutable::setTestNow('2028-02-29 12:00:00');

    $user = leapCutoffUser('leap-breakdown');
    leapCutoffLedger($user, [RollingTwelveMonths::startDate(CarbonImmutable::now())], -4200);

    /** @var Counterparty $counterparty */
    $counterparty = Counterparty::query()->where('user_id', $user->id)->where('slug', 'leap-merchant')->firstOrFail();

    $breakdown = app(CounterpartyProfileQuery::class)->categoryBreakdown($counterparty, $user);

    expect($breakdown)->toHaveCount(1)
        ->and((int) $breakdown->firstOrFail()->total_minor)->toBe(-4200);
});

// The bars ARE the total, decomposed. A row inside the total and outside every
// bar is money the reader is shown twice over and can find in neither place:
// on the 1st of a month the rolling cutoff swept in the whole of the month a
// year back, up to 31 days of spend, with no bar to put it in.
it('adds its sparkline up to exactly the total printed beside it', function (): void {
    foreach (['2026-08-01', '2026-08-30', '2028-02-29', '2028-01-31'] as $index => $today) {
        CarbonImmutable::setTestNow($today.' 12:00:00');

        $user = leapCutoffUser('leap-sum-'.$index);
        $months = RollingTwelveMonths::months(CarbonImmutable::now());

        // One row on the first day the window admits, one the day before it,
        // and one in the newest month: the middle row is the one a rolling
        // cutoff pulled into the total with no bar to draw it on.
        $windowStart = CarbonImmutable::parse(RollingTwelveMonths::startDate(CarbonImmutable::now()));
        leapCutoffLedger($user, [
            $windowStart->toDateString(),
            $windowStart->subDay()->toDateString(),
            CarbonImmutable::now()->startOfMonth()->toDateString(),
        ], -1000);

        $row = app(CounterpartyIndexQuery::class)->forUser($user)->firstOrFail();

        expect(array_sum($row->sparkline))->toBe(
            $row->total12mMinor,
            'on '.$today.': total '.$row->total12mMinor.' against bars '.implode(',', $row->sparkline)
                .' over '.$months[0].'…'.$months[11],
        );
        expect($row->total12mMinor)->toBe(-2000, 'the row before the window must be in neither, on '.$today);
    }
});
