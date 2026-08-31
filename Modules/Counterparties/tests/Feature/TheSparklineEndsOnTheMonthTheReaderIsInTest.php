<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;

// subMonths(11) off a 29th, 30th or 31st the target month does not have rolls
// FORWARD a month, and the startOfMonth() that runs afterwards cannot undo it.
// The twelve buckets then ran one month late: the last one was a month that has
// not happened yet, and the oldest real month fell off the front.

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function sparklineUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  list<array{0: string, 1: int}>  $charges  posted_at and signed minor amount
 */
function sparklineLedger(User $user, array $charges): int
{
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Sparkline ASN',
        'slug' => 'spk-asn-'.$user->id,
        'kind' => 'bank',
        'iban' => 'NL00SPK'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/spk-'.$user->id.'.csv',
        'sha256' => hash('sha256', 'spk-'.$user->id),
        'uploaded_at' => '2020-01-01 00:00:00',
        'status' => 'committed',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    $counterpartyId = (int) DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'spk-merchant',
        'display_name' => 'Sparkline Merchant',
        'merchant_name' => 'Sparkline Merchant',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    foreach ($charges as $index => [$postedAt, $amountMinor]) {
        DB::table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'counterparty_id' => $counterpartyId,
            'fingerprint' => hash('sha256', 'spk-'.$user->id.'-'.$index),
            'fingerprint_version' => 3,
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'type' => 'expense',
            'amount_minor' => $amountMinor,
            'currency' => 'EUR',
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Sparkline Merchant',
            'counterparty_normalized' => 'sparkline merchant',
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'source_row_index' => $index,
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);
    }

    return $counterpartyId;
}

it('puts the current month in the last bucket and the oldest month in the first, opened on 31 January', function (): void {
    CarbonImmutable::setTestNow('2026-01-31 12:00:00');

    $user = sparklineUser('spk-jan-31');
    sparklineLedger($user, [['2025-02-10', -1000], ['2026-01-10', -2000]]);

    $rows = app(CounterpartyIndexQuery::class)->forUser($user);
    $sparkline = $rows->firstOrFail()->sparkline;

    expect($sparkline)->toHaveCount(12)
        ->and($sparkline[11])->toBe(-2000)
        ->and($sparkline[0])->toBe(-1000);
});

it('never carries a bucket for a month that has not happened yet', function (): void {
    foreach (['2026-01-31', '2026-05-31', '2026-08-31', '2026-08-29'] as $today) {
        CarbonImmutable::setTestNow($today.' 12:00:00');

        $user = sparklineUser('spk-future-'.$today);
        sparklineLedger($user, [[CarbonImmutable::parse($today)->startOfMonth()->toDateString(), -3300]]);

        $sparkline = app(CounterpartyIndexQuery::class)->forUser($user)->firstOrFail()->sparkline;

        // The charge is in the current month, so a series whose last bucket is
        // the current month ends on it. One that runs a month late parks it in
        // the second-to-last bucket and leaves an empty future month behind.
        expect($sparkline[11])->toBe(-3300, 'opened on '.$today);
    }
});
