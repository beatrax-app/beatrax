<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Dto\SupportResource;
use Modules\Community\Public\Services\SupportResourceProvider;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\DriftAlerts\Public\Dto\SavingsInsight;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

final class SavingsInsightsQuery
{
    private const REVIEW_FLOOR = 500;

    private const CACHE_TTL = 600;

    public function __construct(
        private readonly RecurringSeriesQuery $recurring,
        private readonly CounterpartyProfileQuery $counterparties,
        private readonly SupportResourceProvider $support,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly CacheRepository $cache,
        private readonly BaseCurrency $baseCurrency,
        private readonly CrossCurrencyTotal $fx,
    ) {}

    /**
     * @return list<SavingsInsight> cached per user — invalidated on dismiss() and
     *                              expires within CACHE_TTL
     */
    public function forUser(User $user): array
    {
        return $this->cache->remember(
            $this->cacheKey($user),
            self::CACHE_TTL,
            fn (): array => $this->compute($user),
        );
    }

    /**
     * @return list<SavingsInsight>
     */
    private function compute(User $user): array
    {
        $openAlerts = $this->openAlertSeriesIds($user);
        $dismissed = $this->dismissedKeys($user);

        $insights = [];
        foreach ($this->recurring->allApprovedForUser($user) as $series) {
            if ($series->direction !== Direction::Expense->value) {
                continue;
            }

            $counterpartyId = $this->recurring->counterpartyIdForSeries($series->seriesId, $user);
            if ($counterpartyId === null) {
                continue;
            }
            $identity = $this->counterparties->identityForId($user, $counterpartyId);
            if ($identity === null) {
                continue;
            }
            $resource = $this->support->forCounterparty($identity['displayName'], $identity['type']);
            if ($resource === null) {
                continue;
            }

            $monthlyMinor = abs($series->monthlyEquivalent->toMinor());
            $insight = $this->pick(
                $series->seriesId,
                $series->displayName(),
                $monthlyMinor,
                $series->monthlyEquivalent->currency(),
                $identity['slug'],
                $resource,
                isset($openAlerts[$series->seriesId]),
            );

            if ($insight !== null && ! isset($dismissed[$insight->key])) {
                $insights[] = $insight;
            }
        }

        return $this->costliestFirst($insights, $user);
    }

    // "Ways to save", ordered by what each costs the reader — a race between
    // currencies, so it is run in the reader's own. On raw minor units a
    // USD150.00 subscription outranked a EUR100.00 one while being cheaper. An
    // insight the rate table cannot reach sorts after every one it can.
    /**
     * @param  list<SavingsInsight>  $insights
     * @return list<SavingsInsight>
     */
    private function costliestFirst(array $insights, User $user): array
    {
        $baseCurrency = $this->baseCurrency->forUser($user);
        $rates = $this->fx->ratesTo(array_map(
            static fn (SavingsInsight $insight): string => $insight->currency,
            $insights,
        ), $baseCurrency);

        $inBase = [];
        foreach ($insights as $index => $insight) {
            $money = Money::tryOfMinor($insight->monthlyMinor, $insight->currency);
            $inBase[$index] = $money === null ? null : $this->fx->convert($money, $baseCurrency, $rates)?->toMinor();
        }

        $order = array_keys($insights);
        usort($order, static function (int $a, int $b) use ($inBase, $insights): int {
            $rankable = ($inBase[$b] !== null) <=> ($inBase[$a] !== null);
            if ($rankable !== 0) {
                return $rankable;
            }

            return ($inBase[$b] ?? $insights[$b]->monthlyMinor) <=> ($inBase[$a] ?? $insights[$a]->monthlyMinor);
        });

        return array_map(static fn (int $index): SavingsInsight => $insights[$index], $order);
    }

    public function dismiss(User $user, string $key): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $this->db->connection()->table('savings_insight_dismissals')->insertOrIgnore([
            'user_id' => $user->id,
            'insight_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->cache->forget($this->cacheKey($user));
    }

    private function cacheKey(User $user): string
    {
        return 'savings-insights:'.$user->id;
    }

    private function pick(
        int $seriesId,
        string $name,
        int $monthlyMinor,
        string $currency,
        string $slug,
        SupportResource $resource,
        bool $hasOpenAlert,
    ): ?SavingsInsight {
        $monthly = Money::ofMinor($monthlyMinor, $currency)->format();

        // The review floor is a base-currency threshold; the arm applies it only
        // to base-currency series so a foreign minor amount is never compared
        // with it.
        return match (true) {
            $resource->cheaperUrl !== null => new SavingsInsight(
                key: 'cheaper:'.$seriesId,
                type: 'cheaper',
                seriesId: $seriesId,
                name: $name,
                monthlyMinor: $monthlyMinor,
                currency: $currency,
                message: Lang::get('drift-alerts::savings.insight.cheaper_message', ['name' => $name, 'monthly' => $monthly]),
                actionLabel: Lang::get('drift-alerts::savings.insight.cheaper_action'),
                actionUrl: $resource->cheaperUrl,
                counterpartySlug: $slug,
            ),
            $hasOpenAlert && $resource->cancelUrl !== null => new SavingsInsight(
                key: 'cancel:'.$seriesId,
                type: 'cancel',
                seriesId: $seriesId,
                name: $name,
                monthlyMinor: $monthlyMinor,
                currency: $currency,
                message: Lang::get('drift-alerts::savings.insight.cancel_message', ['name' => $name, 'monthly' => $monthly]),
                actionLabel: Lang::get('drift-alerts::savings.insight.cancel_action'),
                actionUrl: $resource->cancelUrl,
                counterpartySlug: $slug,
            ),
            $currency === $this->baseCurrency->code() && $monthlyMinor >= self::REVIEW_FLOOR && $resource->cancelUrl !== null => new SavingsInsight(
                key: 'review:'.$seriesId,
                type: 'review',
                seriesId: $seriesId,
                name: $name,
                monthlyMinor: $monthlyMinor,
                currency: $currency,
                message: Lang::get('drift-alerts::savings.insight.review_message', ['name' => $name, 'monthly' => $monthly]),
                actionLabel: Lang::get('drift-alerts::savings.insight.review_action'),
                actionUrl: $resource->cancelUrl,
                counterpartySlug: $slug,
            ),
            default => null,
        };
    }

    /**
     * @return array<int, true>
     */
    private function openAlertSeriesIds(User $user): array
    {
        $ids = [];
        $rows = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('state', DriftAlertState::Open->value)
            ->get(['recurring_series_id']);

        foreach ($rows as $row) {
            if (is_numeric($row->recurring_series_id)) {
                $ids[(int) $row->recurring_series_id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, true>
     */
    private function dismissedKeys(User $user): array
    {
        $keys = [];
        $rows = $this->db->connection()->table('savings_insight_dismissals')
            ->where('user_id', $user->id)
            ->get(['insight_key']);

        foreach ($rows as $row) {
            if (is_string($row->insight_key)) {
                $keys[$row->insight_key] = true;
            }
        }

        return $keys;
    }
}
