<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Support\ForecastChartView;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

// The seeded window was written before the projection that follows it, and the
// detector delete-then-writes the same (user, account, horizon, scenario)
// tuple. Under a scenario id, on a horizon neither reader asks for, it reached
// no surface at all.

it('leaves demo-1 a shortfall the sidebar badge can count', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1')->firstOrFail();
    $this->actingAs($user);

    expect(app(ForecastHighlightsQuery::class)->activeShortfallCountForUser($user))
        ->toBeGreaterThanOrEqual(1);
});

it('shades the default 30-day chart of the account the shortfall names', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1')->firstOrFail();
    $this->actingAs($user);

    $asn = Account::query()
        ->where('user_id', $user->id)
        ->where('slug', 'asn-demo-1')
        ->firstOrFail();

    $view = app(ForecastChartView::class)->selectedAccount(
        $asn->id,
        ForecastHighlightsQuery::TILE_HORIZON,
        null,
        $user,
        'EUR',
    );

    expect($view['shortfallWindows'])->not->toBeEmpty();
});
