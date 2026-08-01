<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/*
 * Cursor pagination correctness over `approvedForUser`, which sorts
 * by `monthly_equivalent_minor DESC` then `id DESC`. The cursor must
 * preserve the primary sort order — passing the previous page's tail
 * id should return the rows with strictly smaller monthly equivalent
 * (or equal equivalent with strictly smaller id), not a disjoint
 * id-window.
 */

function rsqcUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rsqcSeries(User $user, int $monthlyEquivalentMinor, string $name): RecurringSeries
{
    // Use 'income' direction with positive monthly equivalents so the
    // DESC ordering reads top-down as a literal "largest equivalent
    // first" — matches the docblock contract on approvedForUser.
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'income',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => $monthlyEquivalentMinor,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => $monthlyEquivalentMinor,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'rsqc::'.$name,
        'cluster_counterparty_key' => $name,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = rsqcUser('rsqc');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns approved rows in descending monthly_equivalent order across pages', function (): void {
    // Five rows with distinct monthly equivalents inserted out of
    // order so id ASC and equivalent DESC do NOT coincide.
    foreach ([1500, 5000, 800, 3000, 200] as $eq) {
        rsqcSeries($this->user, $eq, 'row-'.$eq);
    }

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    $page1 = $query->approvedForUser($this->user, null, 3);
    expect($page1)->toHaveCount(3);
    $page1Eq = array_map(static fn ($r) => $r->monthlyEquivalent->toMinor(), $page1);
    expect($page1Eq)->toBe([5000, 3000, 1500]);

    $tailId = $page1[count($page1) - 1]->seriesId;
    $page2 = $query->approvedForUser($this->user, $tailId, 3);
    $page2Eq = array_map(static fn ($r) => $r->monthlyEquivalent->toMinor(), $page2);
    expect($page2Eq)->toBe([800, 200]);
})->group('approved-cursor-respects-monthly-equivalent-sort');

it('pendingForUser excludes cadence_changed rows so the Pending tab and the Cadence-changed tab do not double-count', function (): void {
    rsqcSeries($this->user, 1000, 'pending-row');
    /** @var RecurringSeries $pendingRow */
    $pendingRow = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->firstOrFail();
    $this->db->connection()->table('recurring_series')
        ->where('id', $pendingRow->id)
        ->update(['state' => 'pending']);

    rsqcSeries($this->user, 2000, 'cadence-changed-row');
    /** @var RecurringSeries $cadenceRow */
    $cadenceRow = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('detected_name', 'cadence-changed-row')
        ->firstOrFail();
    $this->db->connection()->table('recurring_series')
        ->where('id', $cadenceRow->id)
        ->update(['state' => 'cadence_changed']);

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);
    $pending = $query->pendingForUser($this->user);
    expect($pending)->toHaveCount(1);
    expect($pending[0]->seriesId)->toBe($pendingRow->id);
    expect($query->pendingCountForUser($this->user))->toBe(1);

    $cadenceChanged = $query->cadenceChangedForUser($this->user);
    expect($cadenceChanged)->toHaveCount(1);
    expect($cadenceChanged[0]->seriesId)->toBe($cadenceRow->id);
})->group('pending-excludes-cadence-changed');

it('amountTrendForSeries returns an empty currency + empty points when the series row carries an empty latest_currency', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Insert a corrupt row directly through the query builder so the
    // model boot hook does not auto-populate cluster_counterparty_key
    // — but the trigger DOES require latest_currency. SQLite enforces
    // the column constraint differently per dialect; use a single
    // space as the smallest payload that bypasses the schema NULL
    // check yet still trips the "currency === ''" branch after the
    // detector's whitespace-strip pass would normally run.
    $now = '2026-05-17 12:00:00';
    $id = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'trend-empty-currency',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => '',
        'monthly_equivalent_minor' => -1099,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'corrupt::empty-currency',
        'cluster_counterparty_key' => 'trend-empty-currency',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);
    $trend = $query->amountTrendForSeries((int) $id, $this->user);

    expect($trend->currency)->toBe('');
    expect($trend->points)->toBe([]);
})->group('amount-trend-empty-currency-returns-empty-payload');

it('amountTrendForSeries defaults to the base currency for a missing series', function (): void {
    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);
    $trend = $query->amountTrendForSeries(999999, $this->user);

    expect($trend->currency)->toBe('EUR');
    expect($trend->points)->toBe([]);
});

it('paginates correctly when monthly_equivalent values tie — id-tiebreak descends within the tied band', function (): void {
    // Two rows tie on monthly_equivalent so the cursor must compose
    // both predicates: monthly_equivalent < cursorEq OR (equal AND id <
    // cursorId).
    $a = rsqcSeries($this->user, 1000, 'tie-a');
    $b = rsqcSeries($this->user, 1000, 'tie-b');
    $c = rsqcSeries($this->user, 500, 'low-c');

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    $page1 = $query->approvedForUser($this->user, null, 1);
    expect($page1)->toHaveCount(1);
    // The two tied rows sort by id DESC, so $b (created later) is first.
    expect($page1[0]->seriesId)->toBe($b->id);

    $page2 = $query->approvedForUser($this->user, $b->id, 1);
    expect($page2)->toHaveCount(1);
    expect($page2[0]->seriesId)->toBe($a->id);

    $page3 = $query->approvedForUser($this->user, $a->id, 1);
    expect($page3)->toHaveCount(1);
    expect($page3[0]->seriesId)->toBe($c->id);
})->group('approved-cursor-handles-monthly-equivalent-ties');
