<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

// The accept side of Modules\Core\Public\Enums\SnoozeWindow: that enum offers
// the three targets, this refuses anything a queue may not defer to. Without
// one home the bound was spelled three times and missing from the fourth
// queue, where an unbounded payload took a row out of review for good.
final readonly class SnoozeUntil
{
    public const int MAX_MONTHS = 6;

    private function __construct(public CarbonImmutable $at) {}

    // Measured against the caller's own "now" rather than a clock read here,
    // so an action's injected Clock stays the only source of it.
    public static function from(CarbonImmutable $until, CarbonInterface $now): self
    {
        if ($until->lessThanOrEqualTo($now)) {
            throw new InvalidArgumentException('Snooze target must be in the future.');
        }
        if ($until->greaterThan($now->addMonthsNoOverflow(self::MAX_MONTHS))) {
            throw new InvalidArgumentException('Snooze target may not exceed six months from now.');
        }

        return new self($until);
    }

    // For the screens, which drop a stale popover's target rather than raising
    // on it; the action behind them still refuses the same value loudly.
    public static function tryFrom(CarbonImmutable $until, CarbonInterface $now): ?self
    {
        try {
            return self::from($until, $now);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function toDateTimeString(): string
    {
        return $this->at->toDateTimeString();
    }
}
