<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One "You could save here" suggestion: a recurring subscription paired with an
 * actionable link from the support-resource corpus (a cheaper plan, a
 * cancellation page after a price rise, or a periodic review nudge). Purely
 * informational — beatrax surfaces the official link, it never acts.
 *
 * `key` is a stable per-(type, series) identifier used to persist dismissals.
 */
final class SavingsInsight extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly int $seriesId,
        public readonly string $name,
        public readonly int $monthlyMinor,
        public readonly string $currency,
        public readonly string $message,
        public readonly string $actionLabel,
        public readonly string $actionUrl,
        public readonly ?string $counterpartySlug = null,
    ) {}
}
