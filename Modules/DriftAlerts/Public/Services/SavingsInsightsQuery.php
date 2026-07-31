<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Dto\SupportResource;
use Modules\Community\Public\Services\SupportResourceProvider;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\DriftAlerts\Public\Dto\SavingsInsight;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * @link ../../../../.docs/features/drift-alerts/architecture.md
 */
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
    ) {}

    /**
     * @return list<SavingsInsight> cached per user (see the class @link) — invalidated on
     *                              dismiss() and expires within CACHE_TTL
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

        usort($insights, static fn (SavingsInsight $a, SavingsInsight $b): int => $b->monthlyMinor <=> $a->monthlyMinor);

        return $insights;
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
        $monthly = Money::ofMinor($monthlyMinor, $currency)->format($currency === 'EUR' ? 'nl_NL' : 'en_US');

        if ($resource->cheaperUrl !== null) {
            return new SavingsInsight(
                key: 'cheaper:'.$seriesId,
                type: 'cheaper',
                seriesId: $seriesId,
                name: $name,
                monthlyMinor: $monthlyMinor,
                currency: $currency,
                message: $name.' may have a cheaper plan — you pay '.$monthly.'/mo.',
                actionLabel: 'See cheaper plans',
                actionUrl: $resource->cheaperUrl,
                counterpartySlug: $slug,
            );
        }

        if ($hasOpenAlert && $resource->cancelUrl !== null) {
            return new SavingsInsight(
                key: 'cancel:'.$seriesId,
                type: 'cancel',
                seriesId: $seriesId,
                name: $name,
                monthlyMinor: $monthlyMinor,
                currency: $currency,
                message: $name.'\'s price went up — cancel or switch ('.$monthly.'/mo).',
                actionLabel: 'How to cancel',
                actionUrl: $resource->cancelUrl,
                counterpartySlug: $slug,
            );
        }

        // The review floor is a EUR threshold; only apply it to EUR series so a
        // foreign-currency minor amount is never compared against it.
        if ($currency === 'EUR' && $monthlyMinor >= self::REVIEW_FLOOR && $resource->cancelUrl !== null) {
            return new SavingsInsight(
                key: 'review:'.$seriesId,
                type: 'review',
                seriesId: $seriesId,
                name: $name,
                monthlyMinor: $monthlyMinor,
                currency: $currency,
                message: 'Still using '.$name.'? It costs '.$monthly.'/mo.',
                actionLabel: 'How to cancel',
                actionUrl: $resource->cancelUrl,
                counterpartySlug: $slug,
            );
        }

        return null;
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
