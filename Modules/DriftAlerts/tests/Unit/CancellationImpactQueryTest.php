<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Services\CancellationImpactQuery;

uses(RefreshDatabase::class);

function ciqUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ciqSeries(User $user, array $overrides = []): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('recurring_series')->insertGetId(array_merge([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'CIQ fixture',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1149,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1149,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'ciq::'.$suffix,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ], $overrides));
}

it('returns a DTO whose monthlySavings absolute amount equals the series monthly_equivalent_minor in latest_currency', function (): void {
    $user = ciqUser('ciq-eur');
    $seriesId = ciqSeries($user, [
        'monthly_equivalent_minor' => -1149,
        'latest_currency' => 'EUR',
    ]);

    /** @var CancellationImpactQuery $query */
    $query = app(CancellationImpactQuery::class);
    $dto = $query->forSeries($seriesId, $user);

    expect($dto)->not->toBeNull();
    expect($dto->recurringSeriesId)->toBe($seriesId);
    expect($dto->monthlySavings->toMinor())->toBe(1149);
    expect($dto->monthlySavings->currency())->toBe('EUR');
    expect($dto->annualSavings->toMinor())->toBe(13788);
    expect($dto->annualSavings->currency())->toBe('EUR');
    expect($dto->currency)->toBe('EUR');
});

it('preserves USD currency on USD series, because monthly_equivalent_minor is not hard-EUR', function (): void {
    $user = ciqUser('ciq-usd');
    $seriesId = ciqSeries($user, [
        'monthly_equivalent_minor' => -1199,
        'latest_currency' => 'USD',
        'latest_amount_minor' => -1199,
    ]);

    /** @var CancellationImpactQuery $query */
    $query = app(CancellationImpactQuery::class);
    $dto = $query->forSeries($seriesId, $user);

    expect($dto)->not->toBeNull();
    expect($dto->currency)->toBe('USD');
    expect($dto->monthlySavings->currency())->toBe('USD');
    expect($dto->annualSavings->currency())->toBe('USD');
    expect($dto->monthlySavings->toMinor())->toBe(1199);
    expect($dto->annualSavings->toMinor())->toBe(14388);
});

// monthly_equivalent_minor is a rounded integer, and multiplying it back up
// advertised a saving the subscription never costs: EUR 109.92 for a EUR
// 109.90 annual plan, on the same row as the alert's correct annualisation.
it('annualises a yearly plan at its own price, not through the rounded monthly figure', function (): void {
    $user = ciqUser('ciq-yearly');
    $seriesId = ciqSeries($user, [
        'cadence' => 'yearly',
        'latest_amount_minor' => -10990,
        'monthly_equivalent_minor' => -916,
    ]);

    $dto = app(CancellationImpactQuery::class)->forSeries($seriesId, $user);

    expect($dto)->not->toBeNull();
    expect($dto->annualSavings->toMinor())->toBe(10990);
    expect($dto->monthlySavings->toMinor())->toBe(916);
});

it('annualises the second reported yearly plan exactly too', function (): void {
    $user = ciqUser('ciq-yearly-2');
    $seriesId = ciqSeries($user, [
        'cadence' => 'yearly',
        'latest_amount_minor' => -57148,
        'monthly_equivalent_minor' => -4762,
    ]);

    expect(app(CancellationImpactQuery::class)->forSeries($seriesId, $user)->annualSavings->toMinor())
        ->toBe(57148);
});

it('annualises a weekly plan at fifty-two weeks', function (): void {
    $user = ciqUser('ciq-weekly');
    $seriesId = ciqSeries($user, [
        'cadence' => 'weekly',
        'latest_amount_minor' => -500,
        'monthly_equivalent_minor' => -2167,
    ]);

    expect(app(CancellationImpactQuery::class)->forSeries($seriesId, $user)->annualSavings->toMinor())
        ->toBe(26000);
});

it('annualises a quarterly plan at four quarters', function (): void {
    $user = ciqUser('ciq-quarterly');
    $seriesId = ciqSeries($user, [
        'cadence' => 'quarterly',
        'latest_amount_minor' => -4999,
        'monthly_equivalent_minor' => -1666,
    ]);

    expect(app(CancellationImpactQuery::class)->forSeries($seriesId, $user)->annualSavings->toMinor())
        ->toBe(19996);
});

// An irregular series bills at no rate at all, so the monthly equivalent the
// detector derived is the only yearly figure there is.
it('falls back to the monthly equivalent for an irregular series', function (): void {
    $user = ciqUser('ciq-irregular');
    $seriesId = ciqSeries($user, [
        'cadence' => 'irregular',
        'latest_amount_minor' => -3000,
        'monthly_equivalent_minor' => -1000,
    ]);

    expect(app(CancellationImpactQuery::class)->forSeries($seriesId, $user)->annualSavings->toMinor())
        ->toBe(12000);
});

it('returns null on cross-user invocation', function (): void {
    $owner = ciqUser('ciq-owner');
    $intruder = ciqUser('ciq-intruder');
    $seriesId = ciqSeries($owner);

    /** @var CancellationImpactQuery $query */
    $query = app(CancellationImpactQuery::class);

    expect($query->forSeries($seriesId, $intruder))->toBeNull();
});

it('returns null when the series id does not exist', function (): void {
    $user = ciqUser('ciq-missing');

    /** @var CancellationImpactQuery $query */
    $query = app(CancellationImpactQuery::class);

    expect($query->forSeries(99999, $user))->toBeNull();
});
