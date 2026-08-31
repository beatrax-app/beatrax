<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;

uses(RefreshDatabase::class);

// "Lowest in 30 days" is a forward-CASH line: the reader is being told how low
// the money they hold gets before the month is out, beside a shortfall count
// that already refuses to raise one off a card (BufferFloor::forKind returns no
// floor for ics_card). The race behind the figure ran over every account.
//
//  - an ics_card balance is what is OWED, so it sits below zero for the card's
//    whole life and wins the race on day one, every day, for everyone;
//  - a google_play balance is a cumulative spend tally — GooglePlayReceiptMatcher
//    negates every amount and skips refunds — so it only ever descends and takes
//    the race over for good once the reader has bought enough;
//  - a paypal_funding row restates a movement the paying account already carries.
//
// None of the three is money the reader can run short of.

function lpbUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function lpbAccount(User $user, string $slug, AccountKind $kind): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'lpb '.$slug,
        'slug' => 'lpb-'.$slug,
        'kind' => $kind->value,
        'iban' => 'LPB-'.strtoupper($slug),
        'default_currency' => 'EUR',
    ]);
}

/**
 * @param  array<int, int>  $lowestByAccountId
 */
function lpbRun(DatabaseManager $db, User $user, array $lowestByAccountId): void
{
    $accounts = [];
    foreach ($lowestByAccountId as $accountId => $lowest) {
        $accounts[(string) $accountId] = [
            'account_id' => $accountId,
            'account_name' => 'lpb '.$accountId,
            'default_currency' => 'EUR',
            'today_balance_minor' => 0,
            'anchor_source' => 'user_input_opening_balance',
            'points' => [
                ['date' => '2026-05-19', 'low_minor' => 0, 'point_minor' => 0, 'high_minor' => 0, 'currency' => 'EUR'],
                ['date' => '2026-05-20', 'low_minor' => $lowest, 'point_minor' => $lowest, 'high_minor' => $lowest, 'currency' => 'EUR'],
            ],
        ];
    }

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $user->id,
        'scenario_id' => null,
        'horizon_days' => ForecastHighlightsQuery::TILE_HORIZON,
        'status' => 'complete',
        'result_json' => json_encode(['as_of' => '2026-05-19', 'horizon_days' => 30, 'accounts' => $accounts]),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-19 09:00:00'));
    $this->user = lpbUser('lowest-projected-balance');
    $this->bank = lpbAccount($this->user, 'bank', AccountKind::Bank);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('does not hand the reader a card debt as the lowest balance they will hold', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $card = lpbAccount($this->user, 'ics', AccountKind::IcsCard);

    lpbRun($db, $this->user, [
        $this->bank->id => -1000,
        $card->id => -250000,
    ]);

    $dto = app(ForecastHighlightsQuery::class)->forUser($this->user);

    expect($dto->lowestProjectedAccountId)->toBe($this->bank->id);
    expect($dto->lowestProjectedBalanceMinor)->toBe(-1000);
});

it('does not hand the reader a Play spend tally as the lowest balance they will hold', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $play = lpbAccount($this->user, 'play', AccountKind::GooglePlay);

    lpbRun($db, $this->user, [
        $this->bank->id => -1000,
        $play->id => -880000,
    ]);

    $dto = app(ForecastHighlightsQuery::class)->forUser($this->user);

    expect($dto->lowestProjectedAccountId)->toBe($this->bank->id);
    expect($dto->lowestProjectedBalanceMinor)->toBe(-1000);
});

it('does not race the funding account that restates a transfer already posted', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $funding = lpbAccount($this->user, 'funding', AccountKind::PaypalFunding);

    lpbRun($db, $this->user, [
        $this->bank->id => -1000,
        $funding->id => -400000,
    ]);

    $dto = app(ForecastHighlightsQuery::class)->forUser($this->user);

    expect($dto->lowestProjectedAccountId)->toBe($this->bank->id);
});

it('still races every account that holds money the reader can spend', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $wallet = lpbAccount($this->user, 'paypal', AccountKind::Paypal);
    $cash = lpbAccount($this->user, 'cash', AccountKind::Cash);

    lpbRun($db, $this->user, [
        $this->bank->id => 5000,
        $wallet->id => -7500,
        $cash->id => 100,
    ]);

    $dto = app(ForecastHighlightsQuery::class)->forUser($this->user);

    expect($dto->lowestProjectedAccountId)->toBe($wallet->id);
    expect($dto->lowestProjectedBalanceMinor)->toBe(-7500);
});

it('says nothing rather than naming a card when the card is the only account projected', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $card = lpbAccount($this->user, 'ics-only', AccountKind::IcsCard);

    lpbRun($db, $this->user, [$card->id => -250000]);

    $dto = app(ForecastHighlightsQuery::class)->forUser($this->user);

    expect($dto->lowestProjectedBalanceMinor)->toBeNull();
    expect($dto->lowestProjectedAccountName)->toBeNull();
});
