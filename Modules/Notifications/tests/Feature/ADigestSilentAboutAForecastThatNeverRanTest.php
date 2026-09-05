<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Forecasting\Public\Dto\AccountBalanceLine;
use Modules\Forecasting\Public\Dto\NetWorth;
use Modules\Forecasting\Public\Enums\ShortfallRisk;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\TopCategories;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Events\PositionDigestDue;

uses(RefreshDatabase::class);

// The digest said nothing about a shortfall in two different situations: a
// forecast that ran and found none, and a forecast that has never run. Silence
// in a digest reads as reassurance, so the second was reported as the first.
//
// It also had no way to say what the reader is worth, which is the figure the
// dashboard leads with.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-14 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function digestRiskUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => Currency::Eur->value,
        'locale' => 'en',
    ]);
}

function digestRiskNetWorth(int $totalMinor, string $unconvertedCurrency = ''): NetWorth
{
    $accounts = [];
    if ($unconvertedCurrency !== '') {
        $accounts[] = new AccountBalanceLine(
            accountId: 1,
            name: 'Held abroad',
            kind: 'bank',
            balanceMinor: 900_00,
            currency: $unconvertedCurrency,
            isLiability: false,
            baseEquivalentMinor: null,
            fxRate: null,
            fxSource: null,
            fxAsOf: null,
            fxIsStale: false,
        );
    }

    return new NetWorth(
        totalMinor: $totalMinor,
        currency: Currency::Eur->value,
        accounts: $accounts,
        hasExcludedAccounts: $unconvertedCurrency !== '',
        balancesWithoutRate: $unconvertedCurrency === '' ? 0 : 1,
    );
}

function digestRiskEmit(User $user, ShortfallRisk $risk, NetWorth $netWorth): void
{
    $period = new Period(
        start: CarbonImmutable::parse('2026-05-01'),
        endExclusive: CarbonImmutable::parse('2026-06-01'),
        label: 'May 2026',
    );

    $position = new PositionSummaryDto(
        summary: new DashboardSummary(
            period: $period,
            inflow: Money::ofMinor(200_000, Currency::Eur->value),
            outflow: Money::ofMinor(150_000, Currency::Eur->value),
            net: Money::ofMinor(50_000, Currency::Eur->value),
            topCategories: TopCategories::none(Currency::Eur->value),
            recentTransactions: [],
            uncategorizedCount: 0,
            isFirstRun: false,
        ),
        tilesByCurrency: null,
        emailScanHealth: null,
        upcoming: [],
        budgets: [],
        shortfallRisk: $risk,
        netWorth: $netWorth,
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

/** @return list<string> the copy keys the digest body was built from, in order */
function digestRiskBodyKeys(User $user): array
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
    /** @var array{body: list<array{key: string, replace: array<string, mixed>}>} $copy */
    $copy = $decoded['copy'];

    return array_map(static fn (array $line): string => $line['key'], $copy['body']);
}

it('says the forecast has not run rather than leaving the reader to read silence as safety', function (): void {
    $user = digestRiskUser('digest-never-ran');

    digestRiskEmit($user, ShortfallRisk::NotYetComputed, digestRiskNetWorth(1_845_00));

    expect(digestRiskBodyKeys($user))->toContain('notifications::copy.digest.forecast_not_run');
});

it('says nothing about a shortfall a completed run did not find', function (): void {
    $user = digestRiskUser('digest-clean-run');

    digestRiskEmit($user, ShortfallRisk::None, digestRiskNetWorth(1_845_00));

    $keys = digestRiskBodyKeys($user);

    expect($keys)->not->toContain('notifications::copy.digest.forecast_not_run')
        ->and($keys)->not->toContain('notifications::copy.digest.shortfall');
});

it('still warns about a shortfall a run did find', function (): void {
    $user = digestRiskUser('digest-shortfall');

    digestRiskEmit($user, ShortfallRisk::Ahead, digestRiskNetWorth(1_845_00));

    $keys = digestRiskBodyKeys($user);

    expect($keys)->toContain('notifications::copy.digest.shortfall')
        ->and($keys)->not->toContain('notifications::copy.digest.forecast_not_run');
});

it('names what the reader is worth, in a currency and as a whole sentence', function (): void {
    $user = digestRiskUser('digest-net-worth');

    digestRiskEmit($user, ShortfallRisk::None, digestRiskNetWorth(1_845_00));

    expect(digestRiskBodyKeys($user))->toContain('notifications::copy.digest.net_worth');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $body = $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->where('trigger_type', NotificationTrigger::PositionDigest)
        ->value('body');

    expect($body)->toBeString()
        ->and((string) $body)->toContain('€1,845.00')
        ->and((string) $body)->not->toContain('::');
});

it('names the currency it left out rather than reporting a smaller net worth', function (): void {
    $user = digestRiskUser('digest-no-rate');

    digestRiskEmit($user, ShortfallRisk::None, digestRiskNetWorth(100_00, 'ZWL'));

    expect(digestRiskBodyKeys($user))->toContain('core::money.not_converted');
});
