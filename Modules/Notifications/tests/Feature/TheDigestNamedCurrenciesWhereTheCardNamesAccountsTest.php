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

// The net-worth card and this digest fill the same shared sentence,
// core::money.not_converted, from two different kinds of list: the card names
// accounts and the digest named currency codes. One reader, one sentence, two
// answers about which money was left out. Both now read the same
// NetWorth::excludedAccountNames(), so neither can drift from the other.
function digestNamesUser(string $username): User
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

function digestNamesLine(int $accountId, string $name, string $currency): AccountBalanceLine
{
    return new AccountBalanceLine(
        accountId: $accountId,
        name: $name,
        kind: 'bank',
        balanceMinor: 900_00,
        currency: $currency,
        isLiability: false,
        baseEquivalentMinor: null,
    );
}

/**
 * @param  list<AccountBalanceLine>  $accounts
 */
function digestNamesEmit(User $user, array $accounts): void
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
        shortfallRisk: ShortfallRisk::None,
        netWorth: new NetWorth(
            totalMinor: 1_845_00,
            currency: Currency::Eur->value,
            accounts: $accounts,
            hasExcludedAccounts: $accounts !== [],
            balancesWithoutRate: count($accounts),
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

/** @return list<string> every ":list" the digest's not-converted lines name */
function digestNamesUnconvertedLists(User $user): array
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

    $found = [];
    foreach ($copy['body'] as $line) {
        if ($line['key'] === 'core::money.not_converted') {
            $found[] = (string) $line['replace']['list'];
        }
    }

    return $found;
}

it('names the account it could not price rather than the currency code', function (): void {
    $user = digestNamesUser('digest-names-one');

    digestNamesEmit($user, [digestNamesLine(1, 'Held abroad', 'ZWL')]);

    expect(digestNamesUnconvertedLists($user))->toBe(['Held abroad']);
});

it('names an account once when it holds two balances no rate reached', function (): void {
    $user = digestNamesUser('digest-names-dedup');

    digestNamesEmit($user, [
        digestNamesLine(1, 'Held abroad', 'ZWL'),
        digestNamesLine(1, 'Held abroad', Currency::Jpy->value),
    ]);

    expect(digestNamesUnconvertedLists($user))->toBe(['Held abroad']);
});

it('names every account no rate reached, ordered the way the card orders them', function (): void {
    $user = digestNamesUser('digest-names-two');

    digestNamesEmit($user, [
        digestNamesLine(1, 'Held abroad', 'ZWL'),
        digestNamesLine(2, 'Wise', Currency::Jpy->value),
    ]);

    expect(digestNamesUnconvertedLists($user))->toBe(['Held abroad, Wise']);
});

// A balance already in the reader's own currency has no rate to be missing, so
// naming it would report a gap that is not there.
it('says nothing when every balance is already in the reader currency', function (): void {
    $user = digestNamesUser('digest-names-none');

    digestNamesEmit($user, [digestNamesLine(1, 'Betaalrekening', Currency::Eur->value)]);

    expect(digestNamesUnconvertedLists($user))->toBe([]);
});
