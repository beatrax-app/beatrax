<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One row on the Goals page: a savings goal with its contribution progress
 * against the target, derived status, and projected finish date.
 *
 * `fractionComplete` is a float ratio (contributedMinor / targetMinor),
 * lower-bounded at 0.0 when targetMinor <= 0. It is NOT upper-clamped — values
 * above 1.0 flow through (the view clamps the rendered bar to 100%); values
 * >= 1.0 indicate "reached".
 *
 * `progressState` buckets the goal for bar colour:
 *   `in_progress` — below target, within deadline
 *   `reached`     — contributed >= target (stays active until explicitly completed)
 *   `overdue`     — past target_date without reaching the target
 *
 * `projectedFinishDate` is 'Y-m-d' or null (no history / reached / unlinked).
 * `projectionBeyondHorizon` is true when the finish date is >90 days out
 * (extrapolated run-rate, lower-confidence — show "(projection)" caption).
 *
 * All money is signed minor units in `currency` (the goal's immutable
 * `target_currency`, D-05 — not necessarily the user's current base currency);
 * `contributedMinor` is converted into that same currency so it shares a unit
 * with `targetMinor`. The view wraps them in `Money::ofMinor()` for locale
 * formatting.
 *
 * `targetDate` is the goal's 'Y-m-d' target date, threaded through so the edit
 * modal can prefill it.
 */
final class GoalProgressRow extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $accountId,
        public readonly ?string $accountName,
        public readonly int $targetMinor,
        public readonly int $contributedMinor,
        public readonly string $currency,
        public readonly float $fractionComplete,
        public readonly string $targetDate,
        public readonly string $status,
        public readonly string $progressState,
        public readonly ?string $projectedFinishDate,
        public readonly bool $projectionBeyondHorizon,
    ) {}

    public function remainingMinor(): int
    {
        return max(0, $this->targetMinor - $this->contributedMinor);
    }
}
