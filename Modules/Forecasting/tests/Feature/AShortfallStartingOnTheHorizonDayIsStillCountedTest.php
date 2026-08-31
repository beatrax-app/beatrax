<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Models\ForecastShortfallWindow;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function horizonDayShortfallUser(): User
{
    return User::query()->create([
        'username' => 'horizon-day',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function horizonDayShortfallAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'horizon day',
        'slug' => 'horizon-day-1',
        'kind' => 'bank',
        'iban' => 'NL57HORIZONDAY1',
        'default_currency' => Currency::Eur->value,
    ]);
}

function horizonDayShortfallWindow(User $user, Account $account, CarbonImmutable $startsAt): ForecastShortfallWindow
{
    return ForecastShortfallWindow::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'scenario_id' => null,
        'horizon_days' => ForecastHighlightsQuery::TILE_HORIZON,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addDays(4),
        'lowest_balance_minor' => -8500,
        'currency' => Currency::Eur->value,
        'buffer_used_minor' => 50000,
    ]);
}

it('counts a shortfall window whose first day is the horizon day itself', function (): void {
    CarbonImmutable::setTestNow('2026-08-17 09:41:00');

    $user = horizonDayShortfallUser();
    $account = horizonDayShortfallAccount($user);

    horizonDayShortfallWindow(
        $user,
        $account,
        CarbonImmutable::today()->addDays(ForecastHighlightsQuery::TILE_HORIZON),
    );

    expect(app(ForecastHighlightsQuery::class)->activeShortfallCountForUser($user))->toBe(1);
});

it('stores a DATE column as ten characters when the writer hands it a Carbon', function (): void {
    CarbonImmutable::setTestNow('2026-08-17 09:41:00');

    $user = horizonDayShortfallUser();
    $account = horizonDayShortfallAccount($user);

    horizonDayShortfallWindow($user, $account, CarbonImmutable::today()->addDays(30));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $widths = $db->connection()->table('forecast_shortfall_windows')
        ->selectRaw('length(starts_at) as starts_width, length(ends_at) as ends_width')
        ->first();

    expect($widths)->not->toBeNull();
    expect([(int) $widths->starts_width, (int) $widths->ends_width])->toBe([10, 10]);
});
