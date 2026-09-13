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

// The sampled series and the dashboard card answer the same question with two
// different numbers, and the divergence has to be written down accurately or it
// cannot be reasoned about. It is TWO differences, not one: the card asks for
// TODAY and the series for its bucket's last day, and the card counts every row
// while the series counts only the cleared ones. This is the case the page in
// .docs/features/reports/architecture.md describes.
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

    $points = app(NetWorthSeriesQuery::class)->forUser(
        $user,
        new Period(
            start: CarbonImmutable::parse('2026-08-01'),
            endExclusive: CarbonImmutable::parse('2026-09-01'),
            label: 'Aug 2026',
        ),
        ReportGranularity::Monthly,
    );

    return [
        'card' => app(NetWorthQuery::class)->forUser($user)->totalMinor,
        'point' => $points[count($points) - 1]->totalMinor,
    ];
}

it('reads the card as of today, counting a row nobody has confirmed yet', function (): void {
    // -10,000 confirmed and -25,000 pending, both already posted; the -5,000
    // dated the 20th is not money that has left yet on the 15th.
    expect(nwdLedger()['card'])->toBe(-35_000);
});

it('reads the series point as of the bucket last day, counting only confirmed rows', function (): void {
    // -10,000 confirmed plus the -5,000 the bucket's own last day has reached;
    // the -25,000 pending row is not in it.
    expect(nwdLedger()['point'])->toBe(-15_000);
});

it('differs from the card by neither the pending amount nor in one direction', function (): void {
    $figures = nwdLedger();

    // The pending row is -25,000 and the gap is -20,000, because the two
    // sample dates disagree as well; and the card reads BELOW the point here,
    // which is what a pending row that is money going out does to it.
    expect($figures['card'] - $figures['point'])->toBe(-20_000)
        ->and($figures['card'])->toBeLessThan($figures['point']);
});
