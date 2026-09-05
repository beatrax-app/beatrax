<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Forecasting\Public\Enums\ShortfallRisk;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Services\PositionQuery;

uses(RefreshDatabase::class);

// The canonical position is what lets the digest tell a reader something the
// dashboard would agree with. It carried the period's flow, the budgets, the
// upcoming charges and a boolean — and no net worth at all, so the one figure
// a reader would call "how am I doing" could only be got from the card.
//
// The boolean was the second half of the same gap: a horizon no completed run
// had covered answered false, which is the answer a healthy forecast gives.

function nwpUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function nwpAccount(DatabaseManager $db, int $userId, string $suffix, string $currency = 'EUR', string $kind = 'bank'): int
{
    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'NWP '.$suffix,
        'slug' => 'nwp-'.$suffix,
        'kind' => $kind,
        'iban' => 'NL00NWP'.strtoupper($suffix),
        'default_currency' => $currency,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function nwpImportRun(DatabaseManager $db, int $userId, string $suffix): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nwp-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'nwp-run-'.$suffix),
        'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function nwpTransaction(DatabaseManager $db, int $userId, int $accountId, int $runId, int $amountMinor, string $currency = 'EUR'): void
{
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'nwp-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 00:00:00',
        'value_date' => '2026-05-05',
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_normalized' => 'nwp-merchant',
        'counterparty_name' => 'NWP MERCHANT',
        'normalization_version' => 1,
        'description' => 'nwp fixture',
        'type' => $amountMinor >= 0 ? 'income' : 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function nwpCompletedForecastRun(DatabaseManager $db, int $userId): void
{
    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => 30,
        'started_at' => '2026-05-20 08:00:00',
        'completed_at' => '2026-05-20 08:01:00',
        'status' => JobRunStatus::Complete->value,
        'created_at' => '2026-05-20 08:00:00',
        'updated_at' => '2026-05-20 08:01:00',
    ]);
}

function nwpPosition(User $user): PositionSummaryDto
{
    /** @var PositionQuery $query */
    $query = app(PositionQuery::class);
    /** @var PeriodQuery $periods */
    $periods = app(PeriodQuery::class);

    return $query->forUser($user, $periods->current());
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('carries the same net worth the dashboard card reads, figure and currency', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = nwpUser('nwp-agrees');
    $this->actingAs($user);

    $accountId = nwpAccount($db, (int) $user->id, 'agrees');
    $runId = nwpImportRun($db, (int) $user->id, 'agrees');
    nwpTransaction($db, (int) $user->id, $accountId, $runId, 125_00);

    $card = app(NetWorthQuery::class)->forUser($user);
    $position = nwpPosition($user);

    expect($position->netWorth->totalMinor)->toBe($card->totalMinor)
        ->and($position->netWorth->currency)->toBe($card->currency)
        ->and($position->netWorth->totalMinor)->toBe(125_00)
        ->and($position->netWorth->currency)->toBe('EUR');
});

it('names a balance it could not convert rather than quietly shrinking the total', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = nwpUser('nwp-norate');
    $this->actingAs($user);

    $euro = nwpAccount($db, (int) $user->id, 'norate-eur');
    $zim = nwpAccount($db, (int) $user->id, 'norate-zwl', 'ZWL');
    $runId = nwpImportRun($db, (int) $user->id, 'norate');

    nwpTransaction($db, (int) $user->id, $euro, $runId, 100_00);
    nwpTransaction($db, (int) $user->id, $zim, $runId, 900_00, 'ZWL');

    $position = nwpPosition($user);

    expect($position->netWorth->totalMinor)->toBe(100_00)
        ->and($position->netWorth->balancesWithoutRate)->toBe(1)
        ->and($position->netWorth->hasExcludedAccounts)->toBeTrue();
});

it('answers a horizon no run has covered with not-yet-computed, never with no-shortfall', function (): void {
    $user = nwpUser('nwp-never-ran');
    $this->actingAs($user);

    expect(nwpPosition($user)->shortfallRisk)->toBe(ShortfallRisk::NotYetComputed);
});

it('answers a horizon a completed run found nothing in with no-shortfall', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = nwpUser('nwp-clean-run');
    $this->actingAs($user);

    nwpCompletedForecastRun($db, (int) $user->id);

    expect(nwpPosition($user)->shortfallRisk)->toBe(ShortfallRisk::None);
});

// The two states the boolean collapsed, side by side: same empty
// forecast_shortfall_windows table, two different answers.
it('tells the two zero-window users apart', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $neverRan = nwpUser('nwp-pair-never');
    $ranClean = nwpUser('nwp-pair-clean');
    nwpCompletedForecastRun($db, (int) $ranClean->id);

    expect($db->connection()->table('forecast_shortfall_windows')->count())->toBe(0);

    $this->actingAs($neverRan);
    $first = nwpPosition($neverRan)->shortfallRisk;

    $this->actingAs($ranClean);
    $second = nwpPosition($ranClean)->shortfallRisk;

    expect($first)->toBe(ShortfallRisk::NotYetComputed)
        ->and($second)->toBe(ShortfallRisk::None)
        ->and($first)->not->toBe($second);
});
