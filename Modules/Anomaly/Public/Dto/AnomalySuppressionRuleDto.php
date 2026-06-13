<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of a single anomaly_suppression_rules row for the
 * settings surface (D-18). Every rule the user has created by dismissing
 * an anomaly "as expected" is listed here so it can be reviewed and
 * removed — nothing is muted invisibly.
 *
 * `bandLow` / `bandHigh` are the server-computed ±15% amount band in the
 * rule's settled `currency`. `displayName` is resolved at the query
 * layer via `CounterpartyProfileQuery::identitiesForIds`; a rule keyed on
 * a NULL `counterpartyId` (normalized-name fallback for an unresolved
 * merchant) carries the empty string.
 */
final class AnomalySuppressionRuleDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $counterpartyId,
        public readonly string $displayName,
        public readonly string $detector,
        public readonly string $direction,
        public readonly Money $bandLow,
        public readonly Money $bandHigh,
        public readonly string $currency,
    ) {}
}
