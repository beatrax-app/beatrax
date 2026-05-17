<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
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

function rsqcUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
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
    $this->user = rsqcUser('rsqc@diederik.test');
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
