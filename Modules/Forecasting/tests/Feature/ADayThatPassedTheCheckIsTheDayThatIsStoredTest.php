<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Actions\SetAccountOpeningBalance;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\AccountStartingBalanceQuery;

uses(RefreshDatabase::class);

// SafeDate::dayOrNull() trims before it reads, so a padded day passes the check
// and the caller then stored the string that arrived rather than the day the
// check found. date(' 2026-04-17') is NULL in SQLite, and the baseline
// predicate every balance surface reads is built on date() -- so a single
// leading space took the whole starting balance out of the sum.

function paddedDayUser(): User
{
    return User::query()->create([
        'username' => 'padded-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function paddedDayAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'padded account',
        'slug' => 'padded-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'PAD'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
    Bus::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('stores the day the check found, not the string it arrived as', function (string $supplied): void {
    $user = paddedDayUser();
    $account = paddedDayAccount($user);

    app(SetAccountOpeningBalance::class)($account->id, $user, 125000, $supplied, allowDivergence: true);

    expect(DB::table('accounts')->where('id', $account->id)->value('opening_balance_as_of_date'))
        ->toBe('2026-04-17');
})->with([
    'a leading space' => [' 2026-04-17'],
    'a trailing space' => ['2026-04-17 '],
    'the day alone' => ['2026-04-17'],
]);

it('leaves the stored day readable by the predicate every balance is built on', function (): void {
    $user = paddedDayUser();
    $account = paddedDayAccount($user);

    app(SetAccountOpeningBalance::class)($account->id, $user, 125000, ' 2026-04-17', allowDivergence: true);

    $baseline = app(AccountStartingBalanceQuery::class)->forAccount($account->id, $user);

    expect($baseline['date']?->toDateString())->toBe('2026-04-17')
        ->and($baseline['minorUnits'])->toBe(125000);
});
