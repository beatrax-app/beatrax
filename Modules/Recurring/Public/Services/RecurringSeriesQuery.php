<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use stdClass;

/**
 * Public read API over `recurring_series`. Every method scopes by
 * `user_id` and returns Spatie-Data DTOs so the review page, the
 * fixed-payments view, the dashboard tile, and Phase 9 / Phase 10
 * listeners read a single canonical shape.
 *
 * Cross-user reads return an empty list or `null`; cross-user 404s
 * are surfaced at the Public Action layer (mirrors the Chains
 * precedent — query services stay silent so caller policy stays
 * caller-side).
 *
 * Cursor pagination on `id` matches the chains-side review queue.
 * `approvedForUser` orders by `monthly_equivalent_minor DESC` then
 * `id DESC` so the dashboard tile + fixed-payments view consume a
 * stable, "largest first" projection.
 */
final readonly class RecurringSeriesQuery
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * @return list<RecurringSeriesDto>
     */
    public function pendingForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['pending', 'cadence_changed'], $cursorId, $limit, 'id');
    }

    public function pendingCountForUser(User $user): int
    {
        return $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('state', ['pending', 'cadence_changed'])
            ->count();
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    public function rejectedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['rejected'], $cursorId, $limit, 'id');
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    public function approvedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['approved'], $cursorId, $limit, 'monthly_equivalent_minor');
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    public function cadenceChangedForUser(User $user): array
    {
        return $this->scoped($user, ['cadence_changed'], null, 100, 'id');
    }

    public function forSeries(int $seriesId, User $user): ?RecurringSeriesDto
    {
        $row = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var stdClass $row */
        return $this->toDto($row);
    }

    /**
     * @param  list<string>  $states
     * @return list<RecurringSeriesDto>
     */
    private function scoped(User $user, array $states, ?int $cursorId, int $limit, string $primarySort): array
    {
        $query = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('state', $states)
            ->limit($limit);

        if ($primarySort === 'monthly_equivalent_minor') {
            $query->orderByDesc('monthly_equivalent_minor')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        if ($cursorId !== null) {
            $query->where('id', '<', $cursorId);
        }

        $rows = $query->get();
        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = $this->toDto($row);
        }

        return $result;
    }

    private function toDto(stdClass $row): RecurringSeriesDto
    {
        $latestCurrency = self::toString($row->latest_currency);
        $latestAmount = Money::ofMinor(self::toInt($row->latest_amount_minor), $latestCurrency);

        $eurEquivalent = null;
        if ($latestCurrency !== 'EUR' && isset($row->monthly_equivalent_minor)) {
            $eurEquivalent = Money::ofMinor(self::toInt($row->monthly_equivalent_minor), 'EUR');
        }

        $monthlyEquivalent = Money::ofMinor(
            isset($row->monthly_equivalent_minor) ? self::toInt($row->monthly_equivalent_minor) : 0,
            $latestCurrency !== '' ? $latestCurrency : 'EUR',
        );

        $nextExpectedAt = null;
        $rawNext = $row->next_expected_at ?? null;
        if (is_string($rawNext) && $rawNext !== '') {
            $nextExpectedAt = CarbonImmutable::parse($rawNext);
        }

        $snoozedUntil = null;
        $rawSnooze = $row->snoozed_until ?? null;
        if (is_string($rawSnooze) && $rawSnooze !== '') {
            $snoozedUntil = CarbonImmutable::parse($rawSnooze);
        }

        $displayNameOverride = $row->display_name_override ?? null;

        return new RecurringSeriesDto(
            seriesId: self::toInt($row->id),
            direction: self::toString($row->direction),
            detectedName: self::toString($row->detected_name),
            displayNameOverride: is_string($displayNameOverride) && $displayNameOverride !== ''
                ? $displayNameOverride
                : null,
            state: self::toString($row->state),
            cadence: self::toString($row->cadence),
            latestAmount: $latestAmount,
            eurEquivalent: $eurEquivalent,
            monthlyEquivalent: $monthlyEquivalent,
            latestFundingChainLinkId: isset($row->latest_funding_chain_link_id)
                ? self::toInt($row->latest_funding_chain_link_id)
                : null,
            nextExpectedAt: $nextExpectedAt,
            nextExpectedConfidenceLow: (bool) ($row->next_expected_confidence_low ?? false),
            varianceTolerancePercent: self::toInt($row->variance_tolerance_percent ?? 25),
            snoozedUntil: $snoozedUntil,
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
