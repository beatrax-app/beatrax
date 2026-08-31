<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Dto\SupportResource;
use Modules\Community\Public\Services\SupportResourceProvider;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\DriftAlerts\Internal\Dto\InsightCandidate;
use Modules\DriftAlerts\Internal\Dto\InsightFacts;
use Modules\DriftAlerts\Internal\Enums\SavingsInsightKind;
use Modules\DriftAlerts\Public\Dto\SavingsInsight;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Modules\Sync\Public\Events\EntityMutated;

final readonly class SavingsInsightsQuery
{
    private const int REVIEW_FLOOR = 500;

    private const int CACHE_TTL = 600;

    public function __construct(
        private DriftAlertQuery $alerts,
        private RecurringSeriesQuery $recurring,
        private CounterpartyProfileQuery $counterparties,
        private SupportResourceProvider $support,
        private DatabaseManager $db,
        private Clock $clock,
        private CacheRepository $cache,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
        private Dispatcher $events,
    ) {}

    // Only the facts are cached — the sentence and the amount are built per
    // request, because both follow the reader and the reader of a cache entry
    // is not the one who filled it.
    /**
     * @return list<SavingsInsight>
     *
     * @link ../../../../.docs/features/drift-alerts/cached-facts-not-sentences.md
     */
    public function forUser(User $user): array
    {
        /** @var list<InsightFacts> $facts */
        $facts = $this->cache->remember(
            $this->cacheKey($user),
            self::CACHE_TTL,
            fn (): array => $this->compute($user),
        );

        return array_map(self::render(...), $facts);
    }

    private static function render(InsightFacts $facts): SavingsInsight
    {
        $monthly = Money::ofMinor($facts->monthlyMinor, $facts->currency)->format();

        return new SavingsInsight(
            key: $facts->kind->keyFor($facts->seriesId),
            type: $facts->kind->value,
            seriesId: $facts->seriesId,
            name: $facts->name,
            monthlyMinor: $facts->monthlyMinor,
            currency: $facts->currency,
            message: Lang::get($facts->kind->messageKey(), ['name' => $facts->name, 'monthly' => $monthly]),
            messageKey: $facts->kind->messageKey(),
            actionLabel: Lang::get($facts->kind->actionKey()),
            actionUrl: $facts->actionUrl,
            counterpartySlug: $facts->counterpartySlug,
        );
    }

    /**
     * @return list<InsightFacts>
     */
    private function compute(User $user): array
    {
        $openAlerts = $this->alerts->openSeriesIdsForUser($user);
        $dismissed = $this->dismissedKeys($user);

        $approved = $this->recurring->allApprovedForUser($user);
        $baseCurrency = $this->baseCurrency->forUser($user);
        $rates = $this->fx->ratesTo(array_map(
            static fn (RecurringSeriesDto $series): string => $series->monthlyEquivalent->currency(),
            $approved,
        ), $baseCurrency);

        $insights = [];
        foreach ($approved as $series) {
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
            $facts = $this->pick(
                new InsightCandidate(
                    seriesId: $series->seriesId,
                    name: $series->displayName(),
                    monthlyMinor: $monthlyMinor,
                    currency: $series->monthlyEquivalent->currency(),
                    monthlyInBaseMinor: $this->inBase($monthlyMinor, $series->monthlyEquivalent->currency(), $baseCurrency, $rates),
                    counterpartySlug: $identity['slug'],
                ),
                $resource,
                isset($openAlerts[$series->seriesId]),
            );

            if ($facts !== null && ! isset($dismissed[$facts->kind->keyFor($facts->seriesId)])) {
                $insights[] = $facts;
            }
        }

        return $this->costliestFirst($insights, $baseCurrency, $rates);
    }

    // Null for a currency the rate table cannot reach, which withholds the
    // review prompt rather than comparing foreign minor units with a floor
    // denominated in the reader's own.
    /**
     * @param  array<string, string>  $rates
     */
    private function inBase(int $minor, string $currency, string $baseCurrency, array $rates): ?int
    {
        $money = Money::tryOfMinor($minor, $currency);

        return $money === null ? null : $this->fx->convert($money, $baseCurrency, $rates)?->toMinor();
    }

    // "Ways to save", ordered by what each costs the reader — a race between
    // currencies, so it is run in the reader's own. On raw minor units a
    // USD150.00 subscription outranked a EUR100.00 one while being cheaper. An
    // insight the rate table cannot reach sorts after every one it can.
    /**
     * @param  list<InsightFacts>  $insights
     * @param  array<string, string>  $rates
     * @return list<InsightFacts>
     */
    private function costliestFirst(array $insights, string $baseCurrency, array $rates): array
    {
        $inBase = [];
        foreach ($insights as $index => $insight) {
            $inBase[$index] = $this->inBase($insight->monthlyMinor, $insight->currency, $baseCurrency, $rates);
        }

        $order = array_keys($insights);
        usort($order, static function (int $a, int $b) use ($inBase, $insights): int {
            $rankable = ($inBase[$b] !== null) <=> ($inBase[$a] !== null);
            if ($rankable !== 0) {
                return $rankable;
            }

            return ($inBase[$b] ?? $insights[$b]->monthlyMinor) <=> ($inBase[$a] ?? $insights[$a]->monthlyMinor);
        });

        return array_map(static fn (int $index): InsightFacts => $insights[$index], $order);
    }

    // The key crosses the wire from the card, and the column is 64 chars: a
    // tampered payload stored a 500-character key. Only a key this module can
    // itself produce is persisted.
    public function dismiss(User $user, string $key): void
    {
        if (SavingsInsightKind::tryParse($key) === null) {
            return;
        }

        $userId = $user->id;
        $now = $this->clock->now()->toDateTimeString();

        // Derived from the (user_id, insight_key) its own UNIQUE names. The key
        // embeds a recurring_series id, which is itself derived, so the card the
        // reader waved away on the desktop is the same row on the phone.
        $dismissalId = DerivedRowId::for('savings_insight_dismissals', [
            'user_id' => $userId,
            'insight_key' => $key,
        ]);

        $row = [
            'user_id' => $user->id,
            'insight_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $held = $this->db->connection()->table('savings_insight_dismissals')->where('id', $dismissalId)->exists();

        $this->db->connection()->table('savings_insight_dismissals')->insertOrIgnore(['id' => $dismissalId] + $row);

        $this->cache->forget($this->cacheKey($user));

        // Asked before the write: insertOrIgnore reports nothing, and a second
        // create op for a dismissal this device already holds is noise.
        if ($held) {
            return;
        }

        $this->events->dispatch(new EntityMutated(
            table: 'savings_insight_dismissals',
            pk: $dismissalId,
            userId: $userId,
            mutationType: 'create',
            dirtyFields: $row,
        ));
    }

    // The segment is not decoration: an install upgrading mid-window would
    // otherwise hand the render step an entry holding the finished DTOs this
    // key used to carry.
    private function cacheKey(User $user): string
    {
        return 'savings-insights:facts:'.$user->id;
    }

    private function pick(
        InsightCandidate $candidate,
        SupportResource $resource,
        bool $hasOpenAlert,
    ): ?InsightFacts {
        // The review floor is a threshold in the reader's reporting currency,
        // so the series is converted into it before the comparison rather than
        // refused for not already being denominated in it.
        $kind = match (true) {
            $resource->cheaperUrl !== null => SavingsInsightKind::Cheaper,
            $hasOpenAlert && $resource->cancelUrl !== null => SavingsInsightKind::Cancel,
            $candidate->monthlyInBaseMinor !== null
                && $candidate->monthlyInBaseMinor >= self::REVIEW_FLOOR
                && $resource->cancelUrl !== null => SavingsInsightKind::Review,
            default => null,
        };

        $url = $kind === SavingsInsightKind::Cheaper ? $resource->cheaperUrl : $resource->cancelUrl;

        if ($kind === null || $url === null) {
            return null;
        }

        return new InsightFacts(
            kind: $kind,
            seriesId: $candidate->seriesId,
            name: $candidate->name,
            monthlyMinor: $candidate->monthlyMinor,
            currency: $candidate->currency,
            actionUrl: $url,
            counterpartySlug: $candidate->counterpartySlug,
        );
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
