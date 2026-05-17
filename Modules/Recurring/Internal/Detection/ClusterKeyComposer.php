<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detection;

/**
 * Composes the per-cluster idempotency key written to
 * `recurring_series.cluster_key`. The output is the load-bearing
 * payload of the `(user_id, direction, cluster_key, latest_currency)`
 * UNIQUE constraint that gates duplicate-series creation across
 * sweep re-runs.
 *
 * Output shape: four lowercase tokens separated by `::`. Each token is
 * normalised so punctuation variance in the counterparty name (e.g.
 * `"Acme, Inc."` versus `"Acme Inc"`) collapses to the same key —
 * otherwise re-imports of the same merchant under slightly different
 * spellings would silently spawn parallel series.
 *
 * Each token is capped at 60 characters so the assembled key fits well
 * inside the default Laravel string column width.
 */
final readonly class ClusterKeyComposer
{
    private const MAX_PART_LENGTH = 60;

    public function compose(
        string $direction,
        string $counterpartyKey,
        string $originalCurrencyCode,
        string $cadenceBand,
    ): string {
        $direction = self::normalisePart($direction);
        $counterparty = self::normalisePart($counterpartyKey);
        $currency = self::normalisePart($originalCurrencyCode);
        $cadence = self::normalisePart($cadenceBand);

        return $direction.'::'.$counterparty.'::'.$currency.'::'.$cadence;
    }

    private static function normalisePart(string $value): string
    {
        $lower = strtolower($value);
        $hyphenated = (string) preg_replace('/[^a-z0-9]+/', '-', $lower);
        $trimmed = trim($hyphenated, '-');

        if (strlen($trimmed) > self::MAX_PART_LENGTH) {
            $trimmed = substr($trimmed, 0, self::MAX_PART_LENGTH);
            $trimmed = rtrim($trimmed, '-');
        }

        return $trimmed;
    }
}
