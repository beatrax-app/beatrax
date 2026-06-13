<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of a single anomaly_alerts row for the anomaly
 * section of the /drift alerts home, the dashboard badge composer, and
 * any future drill-in surface.
 *
 * Unlike `DriftAlertDto`, an anomaly alert carries NO annualized/
 * threshold fields — an unusual charge is a point-in-time event, not a
 * recurring drift. Instead it carries `reasons` (the list of detector
 * keys the charge tripped — `large` / `first_time` / `duplicate`,
 * canonically ordered, D-16) and `dismissedAs` (`expected` /
 * `dismissed` / null) so the renderer can narrate why the charge was
 * flagged and how it was resolved.
 *
 * `baselineAmount` and `latestAmount` are denominated in the charge's
 * settled currency (preserved verbatim on `anomaly_alerts.currency`).
 * Both are nullable in the schema — a first-time-merchant flag has no
 * prior per-merchant amount baseline — but the DTO always carries Money
 * objects; the mapper substitutes a zero-amount Money in the settled
 * currency when the column is null so call sites never branch on null.
 *
 * `displayName` is resolved at the query layer via
 * `CounterpartyProfileQuery::identitiesForIds` (falling back to the
 * empty string for an unresolved merchant) so call sites do not repeat
 * the lookup.
 *
 * `sensitivityPercentUsed` is captured at alert-open time so a later
 * change to the user-global anomaly sensitivity never rewrites the
 * historical audit trail.
 */
final class AnomalyAlertDto extends Data
{
    /**
     * @param  list<string>  $reasons  canonically-ordered detector keys
     */
    public function __construct(
        public readonly int $anomalyAlertId,
        public readonly int $transactionId,
        public readonly array $reasons,
        public readonly string $displayName,
        public readonly string $direction,
        public readonly string $state,
        public readonly Money $baselineAmount,
        public readonly Money $latestAmount,
        public readonly string $currency,
        public readonly int $sensitivityPercentUsed,
        public readonly ?string $dismissedAs,
        public readonly CarbonImmutable $detectedAt,
        public readonly ?CarbonImmutable $actionedAt,
        public readonly ?CarbonImmutable $snoozedUntil,
    ) {}
}
