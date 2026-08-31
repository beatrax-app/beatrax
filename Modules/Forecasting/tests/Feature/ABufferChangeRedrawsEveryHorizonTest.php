<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Actions\SetAccountForecastBuffer;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Ledger\Models\Account;

function bufferUser(): User
{
    return User::query()->create([
        'username' => 'buf-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('re-projects every horizon, not only the first three', function (): void {
    $user = bufferUser();
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'buf asn',
        'slug' => 'buf-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'BUF-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    Bus::fake([ProjectForecastJob::class]);

    /** @var SetAccountForecastBuffer $action */
    $action = app(SetAccountForecastBuffer::class);
    ($action)((int) $account->id, $user, 25000);

    // The 180- and 365-day runs drew the new buffer line over shortfall
    // shading computed against the old one until something unrelated
    // re-projected them.
    foreach (ForecastHorizon::days() as $days) {
        Bus::assertDispatched(
            ProjectForecastJob::class,
            static fn (ProjectForecastJob $job): bool => $job->horizonDays === $days,
        );
    }
});
