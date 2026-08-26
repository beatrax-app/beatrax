<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\BaseCurrency;

// BaseCurrency refuses a web request that has no reader, the way UserScope
// matches nothing on one. The suite runs in the console, where the refusal is
// off, so the resolver is rebound here over an Application that answers the way
// a served request does — otherwise a guest page that asked for a reader
// currency would 500 in the browser and stay green in CI forever.

beforeEach(function (): void {
    // An install with no user at all sends /login to the first-run screen, so
    // the guest shell only renders once an account exists to log in to.
    User::create(['username' => 'shell-guest', 'password' => 'fixture-password', 'period_start_day' => 1]);

    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->andReturnFalse();

    $this->app->instance(BaseCurrency::class, new BaseCurrency(
        app(Repository::class),
        app(CurrentUser::class),
        $app,
    ));
});

it('renders the guest shell under the currency the install ships with', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee('data-base-currency="'.config('currency.base').'"', escape: false);
});
