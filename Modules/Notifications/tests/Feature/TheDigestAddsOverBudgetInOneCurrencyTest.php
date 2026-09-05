<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Dto\BudgetProgressRow;
use Modules\Budgets\Public\Enums\BudgetProgressStatus;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Forecasting\Public\Dto\NetWorth;
use Modules\Forecasting\Public\Enums\ShortfallRisk;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\TopCategories;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\NotificationQuery;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Events\PositionDigestDue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-14 09:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    // The bundled snapshot prices every code the picker offers, so the table is
    // emptied to one pair: EUR->USD at 2.0, and nothing at all for JPY.
    $db->connection()->table('exchange_rates')->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Usd->value,
        'rate_date' => '2026-05-14',
        'rate' => '2.0',
        'source' => 'ecb',
        'created_at' => '2026-05-14 00:00:00',
        'updated_at' => '2026-05-14 00:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function digestFxUser(string $username, string $baseCurrency): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => $baseCurrency,
        'locale' => 'en',
    ]);
}

function digestFxRow(string $currency, int $overByMinor, BudgetProgressStatus $status, int $categoryId): BudgetProgressRow
{
    return new BudgetProgressRow(
        categoryId: $categoryId,
        name: 'Category '.$categoryId,
        budgetMinor: 10_000,
        spentMinor: 10_000 + $overByMinor,
        currency: $currency,
        fractionUsed: 1.5,
        status: $status,
    );
}

/**
 * @param  list<BudgetProgressRow>  $budgets
 */
function digestFxEmit(User $user, array $budgets): void
{
    $period = new Period(
        start: CarbonImmutable::parse('2026-05-01'),
        endExclusive: CarbonImmutable::parse('2026-06-01'),
        label: 'May 2026',
    );

    $position = new PositionSummaryDto(
        summary: new DashboardSummary(
            period: $period,
            inflow: Money::ofMinor(200_000, Currency::Usd->value),
            outflow: Money::ofMinor(150_000, Currency::Usd->value),
            net: Money::ofMinor(50_000, Currency::Usd->value),
            topCategories: TopCategories::none(Currency::Usd->value),
            recentTransactions: [],
            uncategorizedCount: 0,
            isFirstRun: false,
        ),
        tilesByCurrency: null,
        emailScanHealth: null,
        upcoming: [],
        budgets: $budgets,
        shortfallRisk: ShortfallRisk::None,
        netWorth: new NetWorth(
            totalMinor: 0,
            currency: Currency::Usd->value,
            accounts: [],
            hasExcludedAccounts: false,
        ),
    );

    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user, $position): void {
        /** @var Dispatcher $events */
        $events = app(Dispatcher::class);

        $events->dispatch(new PositionDigestDue(
            userId: (int) $user->id,
            cadence: DigestCadence::Daily,
            occurrence: '2026-05-14',
            position: $position,
        ));
    });
}

/**
 * @return list<array{key: string, replace: array<string, mixed>}>
 */
function digestFxBodyLines(User $user): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $params = $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->where('trigger_type', NotificationTrigger::PositionDigest)
        ->value('params');

    expect($params)->toBeString();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $params, true, 512, JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $copy */
    $copy = $decoded['copy'];

    /** @var list<array{key: string, replace: array<string, mixed>}> $body */
    $body = $copy['body'];

    return $body;
}

/** @return list<string> the "minor|CURRENCY" a line's named money param carries */
function digestFxMoneyParams(User $user, string $key, string $param): array
{
    $found = [];
    foreach (digestFxBodyLines($user) as $line) {
        if ($line['key'] === $key) {
            /** @var array{kind: string, value: string} $money */
            $money = $line['replace'][$param];
            $found[] = $money['value'];
        }
    }

    return $found;
}

/** @return list<string> the ":list" every not-converted line names */
function digestFxUnconvertedLists(User $user): array
{
    $found = [];
    foreach (digestFxBodyLines($user) as $line) {
        if ($line['key'] === 'core::money.not_converted') {
            $found[] = (string) $line['replace']['list'];
        }
    }

    return $found;
}

// Minor units are not a common unit: 5000 EUR-cents plus 3000 USD-cents is
// 8000 of nothing, and the code it was labelled with came off whichever row
// the loop happened to see last -- here a GBP row that is not even over.
it('converts each over-budget envelope before it adds it, and labels the total with the reader’s own code', function (): void {
    $user = digestFxUser('digest-fx-mixed', Currency::Usd->value);

    digestFxEmit($user, [
        digestFxRow(Currency::Eur->value, 5_000, BudgetProgressStatus::Over, 1),
        digestFxRow(Currency::Usd->value, 3_000, BudgetProgressStatus::Over, 2),
        digestFxRow(Currency::Gbp->value, 0, BudgetProgressStatus::Under, 3),
    ]);

    expect(digestFxMoneyParams($user, 'notifications::copy.digest.over_budget', 'amount'))
        ->toBe(['13000|'.Currency::Usd->value]);
});

// A queued digest runs with nothing authenticated, so the ambient reader the
// display currency normally comes from is the install default -- neither the
// reader's choice nor the owner's.
it('renders the over-budget total in the owner’s currency when no reader is authenticated', function (): void {
    $user = digestFxUser('digest-fx-owner', Currency::Usd->value);

    /** @var AuthFactory $auth */
    $auth = app(AuthFactory::class);
    expect($auth->guard()->user())->toBeNull();
    expect(config('currency.base'))->toBe(Currency::Eur->value);

    digestFxEmit($user, [
        digestFxRow(Currency::Eur->value, 5_000, BudgetProgressStatus::Over, 1),
    ]);

    expect(digestFxMoneyParams($user, 'notifications::copy.digest.over_budget', 'amount'))
        ->toBe(['10000|'.Currency::Usd->value]);
});

// An understated figure with nothing saying so is the same defect one level
// down: the codes no rate reached are named, the way every other roll-up
// names them.
it('names the currency it could not price rather than quietly leaving it out', function (): void {
    $user = digestFxUser('digest-fx-unpriced', Currency::Usd->value);

    digestFxEmit($user, [
        digestFxRow(Currency::Usd->value, 3_000, BudgetProgressStatus::Over, 1),
        digestFxRow(Currency::Jpy->value, 500, BudgetProgressStatus::Over, 2),
    ]);

    expect(digestFxMoneyParams($user, 'notifications::copy.digest.over_budget', 'amount'))
        ->toBe(['3000|'.Currency::Usd->value]);
    expect(digestFxUnconvertedLists($user))->toBe([Currency::Jpy->value]);
});

// The shared core:: line, not a Notifications copy of it: CopyLine resolves
// through the same translator a blade does, so the sentence has to reach the
// reader rather than the key print into the body.
it('renders the shared not-converted sentence into the digest body', function (): void {
    $user = digestFxUser('digest-fx-sentence', Currency::Usd->value);

    digestFxEmit($user, [
        digestFxRow(Currency::Usd->value, 3_000, BudgetProgressStatus::Over, 1),
        digestFxRow(Currency::Jpy->value, 500, BudgetProgressStatus::Over, 2),
    ]);

    /** @var NotificationQuery $query */
    $query = app(NotificationQuery::class);
    $rows = $query->allForUser($user)['rows'];

    expect($rows)->toHaveCount(1);
    expect($rows[0]->body)->toContain('JPY not converted — no rate available');
    expect($rows[0]->body)->not->toContain('core::money.not_converted');
});
