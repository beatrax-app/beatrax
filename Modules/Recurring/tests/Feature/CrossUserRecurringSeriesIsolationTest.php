<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Actions\EditRecurringSeriesName;
use Modules\Recurring\Public\Actions\RejectRecurringSeries;
use Modules\Recurring\Public\Actions\SnoozeRecurringSeries;
use Modules\Recurring\Public\Actions\UnRejectRecurringSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function cusUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cusSeries(User $user, string $state, string $cluster, string $name): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => $state,
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => $cluster,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->userA = cusUser('cus-a');
    $this->userB = cusUser('cus-b');
    $this->seriesA = cusSeries($this->userA, 'pending', 'cus::a', 'spotify-a');
    $this->seriesB = cusSeries($this->userB, 'rejected', 'cus::b', 'netflix-b');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('ApproveRecurringSeries 404s when invoked against a cross-user id', function (): void {
    /** @var ApproveRecurringSeries $action */
    $action = $this->app->make(ApproveRecurringSeries::class);

    expect(fn () => ($action)($this->seriesA->id, $this->userB))
        ->toThrow(NotFoundHttpException::class);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($this->seriesA->id);
    expect($fresh->state)->toBe('pending');
});

it('RejectRecurringSeries 404s when invoked against a cross-user id', function (): void {
    /** @var RejectRecurringSeries $action */
    $action = $this->app->make(RejectRecurringSeries::class);

    expect(fn () => ($action)($this->seriesA->id, $this->userB))
        ->toThrow(NotFoundHttpException::class);
});

it('SnoozeRecurringSeries 404s when invoked against a cross-user id', function (): void {
    /** @var SnoozeRecurringSeries $action */
    $action = $this->app->make(SnoozeRecurringSeries::class);

    expect(fn () => ($action)($this->seriesA->id, $this->userB, CarbonImmutable::parse('2026-06-17 00:00:00')))
        ->toThrow(NotFoundHttpException::class);
});

it('EditRecurringSeriesName 404s when invoked against a cross-user id', function (): void {
    /** @var EditRecurringSeriesName $action */
    $action = $this->app->make(EditRecurringSeriesName::class);

    expect(fn () => ($action)($this->seriesA->id, $this->userB, 'pwned'))
        ->toThrow(NotFoundHttpException::class);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($this->seriesA->id);
    expect($fresh->display_name_override)->toBeNull();
});

it('UnRejectRecurringSeries 404s when invoked against a cross-user id', function (): void {
    /** @var UnRejectRecurringSeries $action */
    $action = $this->app->make(UnRejectRecurringSeries::class);

    expect(fn () => ($action)($this->seriesB->id, $this->userA))
        ->toThrow(NotFoundHttpException::class);
});

it('RecurringSeriesQuery::pendingForUser returns only the user own pending rows', function (): void {
    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    $aRows = $query->pendingForUser($this->userA);
    $bRows = $query->pendingForUser($this->userB);

    expect($aRows)->toHaveCount(1);
    expect($aRows[0]->seriesId)->toBe($this->seriesA->id);

    // User B has only a rejected row.
    expect($bRows)->toBeEmpty();
});

it('RecurringSeriesQuery::rejectedForUser returns only the user own rejected rows', function (): void {
    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    $aRows = $query->rejectedForUser($this->userA);
    $bRows = $query->rejectedForUser($this->userB);

    expect($aRows)->toBeEmpty();
    expect($bRows)->toHaveCount(1);
    expect($bRows[0]->seriesId)->toBe($this->seriesB->id);
});

it('RecurringSeriesQuery::approvedForUser stays empty cross-user', function (): void {
    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    /** @var ApproveRecurringSeries $approve */
    $approve = $this->app->make(ApproveRecurringSeries::class);
    ($approve)($this->seriesA->id, $this->userA);

    expect($query->approvedForUser($this->userA))->toHaveCount(1);
    expect($query->approvedForUser($this->userB))->toBeEmpty();
});

it('RecurringSeriesQuery::forSeries returns null for a cross-user lookup', function (): void {
    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    expect($query->forSeries($this->seriesA->id, $this->userB))->toBeNull();
    expect($query->forSeries($this->seriesA->id, $this->userA))->not->toBeNull();
});
