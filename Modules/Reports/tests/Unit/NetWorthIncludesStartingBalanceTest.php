<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Reports\Internal\Aggregation\NetWorthSeriesQuery;
use Modules\Reports\Internal\Enums\ReportGranularity;

uses(RefreshDatabase::class);

function nwsbUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'nwsb-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function nwsbAccount(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId(array_merge([
        'user_id' => $userId,
        'name' => 'NWSB ASN',
        'slug' => 'nwsb-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00NWSB'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-04-01 00:00:00',
        'updated_at' => '2026-04-01 00:00:00',
    ], $overrides));
}

function nwsbTransaction(DatabaseManager $db, int $userId, int $accountId, string $postedAt, int $amountMinor): void
{
    static $nwsbRow = 0;
    $nwsbRow++;

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nwsb-'.$nwsbRow.'.csv',
        'sha256' => hash('sha256', 'nwsb-run-'.$nwsbRow.'-'.bin2hex(random_bytes(4))),
        'uploaded_at' => $postedAt.' 00:00:00',
        'status' => 'imported',
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => $amountMinor >= 0 ? 'income' : 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'NWSB Merchant '.$nwsbRow,
        'counterparty_normalized' => 'nwsb merchant '.$nwsbRow,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'source_row_index' => $nwsbRow,
        'fingerprint' => hash('sha256', 'nwsb-tx-'.$nwsbRow.'-'.bin2hex(random_bytes(4))),
        'fingerprint_version' => 3,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
    ]);
}

function nwsbMayPoint(User $user): int
{
    $period = new Period(
        start: CarbonImmutable::parse('2026-05-01'),
        endExclusive: CarbonImmutable::parse('2026-06-01'),
        label: 'May 2026',
    );

    $points = app(NetWorthSeriesQuery::class)->forUser($user, $period, ReportGranularity::Monthly);

    return $points[0]->totalMinor;
}

// The figure the desktop dashboard printed as "NET WORTH" was the bare
// transaction sum: the account's own starting balance never reached it.
it('reports net worth as the baseline plus the history it does not already hold', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = nwsbUser();
    $accountId = nwsbAccount($db, $user->id, ['starting_balance_minor' => 168_000]);

    nwsbTransaction($db, $user->id, $accountId, '2026-05-10', 300_000);
    nwsbTransaction($db, $user->id, $accountId, '2026-05-20', -67_646);

    expect(nwsbMayPoint($user))->toBe(400_354);
});

it('excludes from net worth the history a dated baseline already absorbed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = nwsbUser();
    $accountId = nwsbAccount($db, $user->id, [
        'starting_balance_minor' => 168_000,
        'starting_balance_date' => '2026-05-10',
    ]);

    nwsbTransaction($db, $user->id, $accountId, '2026-04-30', 999_000);
    nwsbTransaction($db, $user->id, $accountId, '2026-05-10', 300_000);

    expect(nwsbMayPoint($user))->toBe(468_000);
});

it('leaves net worth on the bare sum for an account carrying no baseline', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = nwsbUser();
    $accountId = nwsbAccount($db, $user->id);

    nwsbTransaction($db, $user->id, $accountId, '2026-05-10', 300_000);

    expect(nwsbMayPoint($user))->toBe(300_000);
});
