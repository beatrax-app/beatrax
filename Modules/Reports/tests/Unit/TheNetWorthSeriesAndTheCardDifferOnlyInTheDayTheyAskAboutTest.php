<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Reports\Internal\Aggregation\NetWorthSeriesQuery;
use Modules\Reports\Internal\Enums\ReportGranularity;

uses(RefreshDatabase::class);

// The sampled series and the dashboard card answer the same question, and they
// now answer it the same way: both read currentBalanceAsOf(). One difference is
// left, and it is the DATE -- the card asks for today, a series point for its
// bucket's last day. Asked about one day they agree exactly. This is the case
// the page in .docs/features/reports/architecture.md describes.
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-15 09:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

function nwdUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'nwd-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function nwdMovement(User $user, Account $account, int $minor, string $postedAt, string $status): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nwd-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'nwd-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => 'expense',
        'status' => $status,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 10:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => 'EUR',
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'NWD Vendor',
        'counterparty_normalized' => 'nwd-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'nwd-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function nwdLedger(): array
{
    $user = nwdUser();

    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'nwd account',
        'slug' => 'nwd-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00NWD'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);

    nwdMovement($user, $account, -10_000, '2026-08-05', ClearedStatus::Cleared->value);
    nwdMovement($user, $account, -25_000, '2026-08-06', ClearedStatus::Uncleared->value);
    nwdMovement($user, $account, -5_000, '2026-08-20', ClearedStatus::Cleared->value);

    return [
        'card' => app(NetWorthQuery::class)->forUser($user)->totalMinor,
        'monthPoint' => nwdLastPoint($user, '2026-09-01'),
        'todayPoint' => nwdLastPoint($user, '2026-08-16'),
    ];
}

// The last bucket's endExclusive is clamped to the period's, so asking for a
// range that ends tomorrow makes the final point's own day today -- the one
// day on which nothing but the row set can separate it from the card.
function nwdLastPoint(User $user, string $endExclusive): int
{
    $points = app(NetWorthSeriesQuery::class)->forUser(
        $user,
        new Period(
            start: CarbonImmutable::parse('2026-08-01'),
            endExclusive: CarbonImmutable::parse($endExclusive),
            label: 'Aug 2026',
        ),
        ReportGranularity::Monthly,
    );

    return $points[count($points) - 1]->totalMinor;
}

it('reads the card as of today, counting a row nobody has confirmed yet', function (): void {
    // -10,000 confirmed and -25,000 pending, both already posted; the -5,000
    // dated the 20th is not money that has left yet on the 15th.
    expect(nwdLedger()['card'])->toBe(-35_000);
});

it('agrees with the card to the cent on a point whose own day is today', function (): void {
    // The uncleared -25,000 is money the reader has spent, and the line drawn
    // above the card may not disown it. Sampling cleared rows only read
    // -10,000 here, so the chart sat 25,000 above the figure beside it.
    $figures = nwdLedger();

    expect($figures['todayPoint'])->toBe(-35_000)
        ->and($figures['todayPoint'])->toBe($figures['card']);
});

it('counts every row the bucket last day has reached, pending ones included', function (): void {
    // All three, because the 31st has reached the row dated the 20th.
    expect(nwdLedger()['monthPoint'])->toBe(-40_000);
});

it('differs from the card by the rows between the two dates and by nothing else', function (): void {
    $figures = nwdLedger();

    // The whole gap is the -5,000 the bucket's last day has reached and today
    // has not. The pending -25,000 is on both sides now, so it cancels.
    expect($figures['card'] - $figures['monthPoint'])->toBe(5_000)
        ->and($figures['monthPoint'])->toBe($figures['todayPoint'] - 5_000);
});
