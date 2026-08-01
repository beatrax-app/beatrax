<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Database\Factories\ForecastRunFactory;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

/**
 * Wave 0 RED stubs for GOAL-04.
 *
 * These tests reference GoalProgressQuery (Plan 02) and the GoalProgressRow DTO
 * (Plan 02). They will error with "class not found" until Plan 02 lands — that
 * is correct Wave 0 RED behaviour.
 *
 * Covers:
 *   GOAL-04: Projected finish date via run-rate over trailing window;
 *            in-horizon (≤90d) vs beyond-90d confidence flag;
 *            no-contribution-history → null projectedFinishDate;
 *            already-reached → "reached" sentinel (progressState=reached);
 *            ForecastDto.isComputing → run-rate-only fallback (projection still
 *            returned but projectionBeyondHorizon reflects only the run-rate).
 */
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/x.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

/** Persist a contribution transaction for projection tests. */
function projTx(int $userId, int $accountId, int $runId, int $amountMinor, string $postedAt): void
{
    static $i = 0;
    $i++;

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "P{$i}",
        'counterparty_normalized' => "p{$i}",
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad('p'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

// ---------------------------------------------------------------------------
// Projected finish date — run-rate derivation
// ---------------------------------------------------------------------------

it('returns a projected finish date when there is a contribution history', function (): void {
    // Seed 3 monthly contributions of 20 000 over 90 days.
    $startDate = CarbonImmutable::now()->subDays(90)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 300000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    // Seed forecast_runs for the baseline horizons (30, 60, 90 — scenario_id null).
    ForecastRunFactory::new()->complete()->create(['user_id' => $this->user->id, 'horizon_days' => 30, 'scenario_id' => null]);
    ForecastRunFactory::new()->complete()->create(['user_id' => $this->user->id, 'horizon_days' => 60, 'scenario_id' => null]);
    ForecastRunFactory::new()->complete()->create(['user_id' => $this->user->id, 'horizon_days' => 90, 'scenario_id' => null]);

    projTx($this->user->id, $this->account->id, $this->run->id, 20000, CarbonImmutable::now()->subDays(90)->toDateString());
    projTx($this->user->id, $this->account->id, $this->run->id, 20000, CarbonImmutable::now()->subDays(60)->toDateString());
    projTx($this->user->id, $this->account->id, $this->run->id, 20000, CarbonImmutable::now()->subDays(30)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectedFinishDate)->not->toBeNull();
    expect($rows[0]->contributedMinor)->toBe(60000);
});

// WR-06 follow-up: a goal younger than the minimum observation window must NOT
// project a finish date. One large early deposit divided by a 1-3 day window
// would otherwise extrapolate a misleadingly-soon finish; the card shows the
// "building a projection" copy until enough history accrues.
it('suppresses the projected date until the minimum observation window has elapsed', function (): void {
    // Goal created 3 days ago (< 7-day minimum) with a single large deposit.
    $startDate = CarbonImmutable::now()->subDays(3)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 500000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(60)->toDateString(),
        'status' => 'active',
    ]);

    // A big day-2 deposit that, divided by the tiny window, would project a
    // finish only days out.
    projTx($this->user->id, $this->account->id, $this->run->id, 200000, CarbonImmutable::now()->subDays(1)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // Contributions still count (200 000 < 500 000 → in_progress) but no
    // projected date is offered yet.
    expect($rows[0]->contributedMinor)->toBe(200000);
    expect($rows[0]->projectedFinishDate)->toBeNull();
    expect($rows[0]->progressState)->toBe('in_progress');
});

it('sets projectionBeyondHorizon to false when projected finish is within 90 days', function (): void {
    // High daily contribution rate → finish date very soon (within horizon).
    $startDate = CarbonImmutable::now()->subDays(10)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 50000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(20)->toDateString(),
        'status' => 'active',
    ]);

    // 40 000 contributed over 10 days = 4 000/day rate → 10 days to finish remaining 10 000.
    projTx($this->user->id, $this->account->id, $this->run->id, 40000, CarbonImmutable::now()->subDays(5)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectionBeyondHorizon)->toBeFalse();
});

it('sets projectionBeyondHorizon to true when projected finish exceeds 90 days', function (): void {
    // Low daily contribution rate → finish date well beyond 90-day horizon.
    $startDate = CarbonImmutable::now()->subDays(30)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 1000000, // 10 000 EUR — large target
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addYears(3)->toDateString(),
        'status' => 'active',
    ]);

    // Only 1 000 minor contributed over 30 days = 33 minor/day → ~30 000 days to finish.
    projTx($this->user->id, $this->account->id, $this->run->id, 1000, CarbonImmutable::now()->subDays(15)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectionBeyondHorizon)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Edge cases
// ---------------------------------------------------------------------------

it('returns null projectedFinishDate when there is no contribution history', function (): void {
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    // No transactions seeded — no contribution history.
    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectedFinishDate)->toBeNull();
    expect($rows[0]->contributedMinor)->toBe(0);
});

it('marks progressState as reached when contributions meet the target', function (): void {
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 50000,
        'start_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
        'target_date' => CarbonImmutable::now()->addDays(60)->toDateString(),
        'status' => 'active',
    ]);

    projTx($this->user->id, $this->account->id, $this->run->id, 50000, CarbonImmutable::now()->subDays(5)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->progressState)->toBe('reached');
});

it('returns null projectedFinishDate for an unlinked goal', function (): void {
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => null, // unlinked
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
        'status' => 'active',
    ]);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectedFinishDate)->toBeNull();
    expect($rows[0]->projectionBeyondHorizon)->toBeFalse();
});
