<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One envelope's figures for a single period, as computed by CarryoverQuery's
 * genesis-to-target fold (D-05): `available = assigned + carriedIn + netMoved
 * - spent`.
 *
 * `assignedMinor`, `spentMinor`, `carriedInMinor`, `netMovedMinor`, and
 * `availableMinor` are all signed integer minor units in `currency` — never a
 * float. `overspendMode` is 'reduce_to_budget' | 'carry_negative' (D-03),
 * resolved from `envelope_settings` or the D-12a implicit default when no
 * settings row exists for this (user, category).
 *
 * `nonEurSpentMinor` (CR-01) is the sum of this category's settled spend in
 * every currency OTHER than `currency` (EUR), in that currency's own minor
 * units. Envelopes are EUR-only (D-25), so this figure is NOT folded into
 * `spentMinor`/`availableMinor` — collapsing cross-currency spend into a EUR
 * total would violate the "multi-currency tracking required from v1"
 * constraint. It exists solely so the grid can SURFACE (not silently drop)
 * the fact that non-EUR spend hit this envelope; 0 means none. Because it may
 * mix multiple non-EUR currencies it is only ever a "there is spend not shown
 * here" signal, never an authoritative amount.
 *
 * `notifyThresholdPercent` (D-20) is the EFFECTIVE over-budget notification
 * threshold for this envelope — already null-coalesced by `CarryoverQuery` to
 * `CarryoverQuery::DEFAULT_NOTIFY_THRESHOLD_PERCENT` (90) when the envelope
 * has no explicit `envelope_settings.threshold_percent`. This is the Public
 * read seam plan 18-07's over-budget nudge job consumes: it reads the
 * already-resolved value here and never re-applies the default or reaches for
 * the raw column. It is unrelated to `spentMinor`/`availableMinor` — it is a
 * notification preference, not a money figure.
 */
final class EnvelopeRow extends Data
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $categoryName,
        public readonly int $assignedMinor,
        public readonly int $spentMinor,
        public readonly int $carriedInMinor,
        public readonly int $netMovedMinor,
        public readonly int $availableMinor,
        public readonly string $overspendMode,
        public readonly string $currency,
        public readonly int $nonEurSpentMinor = 0,
        public readonly int $notifyThresholdPercent = 90,
    ) {}
}
