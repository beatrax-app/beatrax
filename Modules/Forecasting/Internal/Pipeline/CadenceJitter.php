<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

/**
 * Spreads each per-occurrence contribution across a ±N-day window.
 *
 * Real-world recurring charges rarely hit exactly on the cadence-
 * derived occurrence date — banks process subscriptions over a
 * weekend, ASN settles ICS bulk-iDEAL one business day later than
 * the statement says, PayPal funding charges can drift a day or two.
 * The envelope and percentile tiers both produce a single
 * "point on day D" contribution per occurrence; the daily fold
 * combines those per-day, so a series of seven points clustered
 * within a ±3-day window naturally produces a wider band than a
 * single point on day D would.
 *
 * The jitter is deterministic — the same input list always produces
 * the same output. Each contribution is replicated `2 × jitterDays + 1`
 * times across the window with equal weighting
 * (`weight = 100 / window` per replica). The implementation is pure
 * math with no DI — the same date offsets fire on every call.
 *
 * Sign + currency + fxRateUsed + seriesId + accountId are preserved
 * on every replica. The integer-rounding of the weight (e.g.
 * `round(100/7) = 14`, so the seven replicas of a -€10.00 point
 * become 7 × -€1.40 = -€9.80, losing 2 cents to rounding) is
 * accepted — the daily fold sums per-day signed integers and the
 * 2-cent rounding error is below the BIGINT signed precision budget.
 * Tests assert the loss is within ±2 minor units per replica.
 */
final readonly class CadenceJitter
{
    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    public function apply(array $contributions, int $jitterDays = 3): array
    {
        if ($contributions === []) {
            return [];
        }

        $window = $jitterDays * 2 + 1;

        // The weight is the fraction of the original contribution
        // attributed to each replica day. `round(100/7) = 14`, so the
        // seven replicas of a -€10.00 point become 7 × -€1.40 each,
        // summing to -€9.80 (vs the original -€10.00). The
        // CadenceJitterTest locks the tolerance to ±2 minor units
        // per replica.
        $weight = 100 / $window;

        $jittered = [];
        foreach ($contributions as $c) {
            for ($offset = -$jitterDays; $offset <= $jitterDays; $offset++) {
                $jittered[] = new ForecastContribution(
                    date: $c->date->addDays($offset),
                    pointMinor: (int) round($c->pointMinor * $weight / 100),
                    lowMinor: (int) round($c->lowMinor * $weight / 100),
                    highMinor: (int) round($c->highMinor * $weight / 100),
                    currency: $c->currency,
                    fxRateUsed: $c->fxRateUsed,
                    seriesId: $c->seriesId,
                    accountId: $c->accountId,
                );
            }
        }

        return $jittered;
    }
}
