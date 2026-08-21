<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Services\PositionQuery;

uses(RefreshDatabase::class);

// A position's `summary` must stay value-identical to
// ThisPeriodAtAGlanceQuery::for() for the same (user, period), or the digest
// and the dashboard disagree.

function pqUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function pqAccount(DatabaseManager $db, int $userId, string $suffix): int
{
    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'PQ ASN',
        'slug' => 'pq-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00PQ'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function pqImportRun(DatabaseManager $db, int $userId, string $suffix): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pq-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'pq-run-'.$suffix),
        'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function pqTransaction(DatabaseManager $db, int $userId, int $accountId, int $runId, int $amountMinor, string $postedAt): int
{
    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'pq-'.bin2hex(random_bytes(8))),
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'pq-merchant',
        'counterparty_name' => 'PQ MERCHANT',
        'normalization_version' => 1,
        'description' => 'pq fixture',
        'type' => $amountMinor >= 0 ? 'income' : 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns a fully-populated DTO for a user with zero data (D-21: nothing notable is still a position)', function (): void {
    $user = pqUser('pq-empty');
    $this->actingAs($user);

    /** @var PositionQuery $query */
    $query = app(PositionQuery::class);
    /** @var PeriodQuery $periods */
    $periods = app(PeriodQuery::class);

    $position = $query->forUser($user, $periods->current());

    expect($position)->toBeInstanceOf(PositionSummaryDto::class);
    expect($position->summary->isFirstRun)->toBeTrue();
    expect($position->tilesByCurrency)->toBeNull();
    expect($position->emailScanHealth)->toBeNull();
    expect($position->upcoming)->toBe([]);
    expect($position->budgets)->toBe([]);
    expect($position->shortfallAhead)->toBeFalse();
});

it('summary is value-identical to ThisPeriodAtAGlanceQuery::for() for the same (user, period)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pqUser('pq-identical');
    $this->actingAs($user);
    $accountId = pqAccount($db, (int) $user->id, 'ident');
    $runId = pqImportRun($db, (int) $user->id, 'ident');

    pqTransaction($db, (int) $user->id, $accountId, $runId, 10000, '2026-05-05');
    pqTransaction($db, (int) $user->id, $accountId, $runId, -2500, '2026-05-08');

    /** @var PositionQuery $query */
    $query = app(PositionQuery::class);
    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = app(ThisPeriodAtAGlanceQuery::class);
    /** @var PeriodQuery $periods */
    $periods = app(PeriodQuery::class);
    $period = $periods->current();

    $position = $query->forUser($user, $period);
    $direct = $glance->for($user, $period);

    expect($position->summary->isFirstRun)->toBe($direct->isFirstRun);
    expect($position->summary->inflow->toMinor())->toBe($direct->inflow->toMinor());
    expect($position->summary->inflow->currency())->toBe($direct->inflow->currency());
    expect($position->summary->outflow->toMinor())->toBe($direct->outflow->toMinor());
    expect($position->summary->net->toMinor())->toBe($direct->net->toMinor());
    expect($position->summary->uncategorizedCount)->toBe($direct->uncategorizedCount);
    expect($position->summary->recentTransactions)->toHaveCount(count($direct->recentTransactions));
    expect($position->summary->topCategories)->toHaveCount(count($direct->topCategories));
    expect($position->summary->period->start->toDateString())->toBe($direct->period->start->toDateString());
    expect($position->summary->period->endExclusive->toDateString())->toBe($direct->period->endExclusive->toDateString());
});

it('does not leak one user\'s figures into another user\'s position (cross-user isolation)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userA = pqUser('pq-cross-a');
    $userB = pqUser('pq-cross-b');
    $this->actingAs($userA);

    $accountA = pqAccount($db, (int) $userA->id, 'crossa');
    $runA = pqImportRun($db, (int) $userA->id, 'crossa');
    pqTransaction($db, (int) $userA->id, $accountA, $runA, -5000, '2026-05-05');

    $accountB = pqAccount($db, (int) $userB->id, 'crossb');
    $runB = pqImportRun($db, (int) $userB->id, 'crossb');
    pqTransaction($db, (int) $userB->id, $accountB, $runB, -9999999, '2026-05-06');

    /** @var PositionQuery $query */
    $query = app(PositionQuery::class);
    /** @var PeriodQuery $periods */
    $periods = app(PeriodQuery::class);
    $period = $periods->current();

    $positionA = $query->forUser($userA, $period);

    expect($positionA->summary->outflow->toMinor())->toBe(5000);
});

it('composes upcoming recurring charges from RecurringSeriesQuery, scoped to the period window', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pqUser('pq-upcoming');
    $this->actingAs($user);

    // Inside the current (2026-05-01 -> 2026-06-01) period window — must surface.
    $db->connection()->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'Netflix',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1399,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => 1399,
        'variance_tolerance_percent' => 25,
        'next_expected_at' => '2026-05-25',
        'next_expected_confidence_low' => false,
        'cluster_key' => 'netflix-cluster',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    // Outside the period window — must NOT surface.
    $db->connection()->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'Spotify',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -999,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => 999,
        'variance_tolerance_percent' => 25,
        'next_expected_at' => '2026-07-01',
        'next_expected_confidence_low' => false,
        'cluster_key' => 'spotify-cluster',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    /** @var PositionQuery $query */
    $query = app(PositionQuery::class);
    /** @var PeriodQuery $periods */
    $periods = app(PeriodQuery::class);

    $position = $query->forUser($user, $periods->current());

    expect($position->upcoming)->toHaveCount(1);
    expect($position->upcoming[0]->detectedName)->toBe('Netflix');
});

it('composes budgets from BudgetProgressQuery', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pqUser('pq-budgets');
    $this->actingAs($user);

    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'slug' => 'pq-groceries',
        'kind' => 'expense',
        'display_order' => 1,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    $db->connection()->table('category_budgets')->insert([
        'user_id' => $user->id,
        'category_id' => $categoryId,
        'period_type' => 'monthly',
        'budget_minor' => 20000,
        'currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    /** @var PositionQuery $query */
    $query = app(PositionQuery::class);
    /** @var PeriodQuery $periods */
    $periods = app(PeriodQuery::class);

    $position = $query->forUser($user, $periods->current());

    expect($position->budgets)->toHaveCount(1);
    expect($position->budgets[0]->name)->toBe('Groceries');
    expect($position->budgets[0]->budgetMinor)->toBe(20000);
});

it('composes shortfallAhead from ForecastHighlightsQuery::activeShortfallCountForUser', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pqUser('pq-shortfall');
    $this->actingAs($user);
    $accountId = pqAccount($db, (int) $user->id, 'shortfall');

    $db->connection()->table('forecast_shortfall_windows')->insert([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'scenario_id' => null,
        'starts_at' => '2026-05-22',
        'ends_at' => '2026-05-25',
        'lowest_balance_minor' => -5000,
        'currency' => 'EUR',
        'buffer_used_minor' => 0,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    /** @var PositionQuery $query */
    $query = app(PositionQuery::class);
    /** @var PeriodQuery $periods */
    $periods = app(PeriodQuery::class);

    $position = $query->forUser($user, $periods->current());

    expect($position->shortfallAhead)->toBeTrue();
});
