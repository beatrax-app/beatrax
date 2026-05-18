<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Events;

use Illuminate\Support\Carbon;

/**
 * Emitted by `ShortfallDetector` when a new
 * `forecast_shortfall_windows` row is written.
 *
 * Operational-hardening hooks (Phase 11) can subscribe to trigger
 * backups, surface health-monitor pings, or notify a webhook when the
 * projected balance dips below a per-account buffer. The payload
 * carries the captured `bufferUsedMinor` so a later buffer edit cannot
 * silently rewrite the historical narrative (Phase 9 honest-audit
 * precedent).
 *
 * `scenarioId` is null when the shortfall belongs to the baseline
 * projection and the scenario id otherwise.
 */
final readonly class ForecastShortfallDetected
{
    public function __construct(
        public int $userId,
        public int $accountId,
        public ?int $scenarioId,
        public Carbon $startsAt,
        public Carbon $endsAt,
        public int $lowestBalanceMinor,
        public string $currency,
        public int $bufferUsedMinor,
    ) {}
}
