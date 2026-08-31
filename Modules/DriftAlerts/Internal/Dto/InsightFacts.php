<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Dto;

use Modules\DriftAlerts\Internal\Enums\SavingsInsightKind;

// What the card is cached as: the facts a suggestion is made of, with no
// sentence and no formatted amount among them. Both of those follow the
// reader, and the reader of a cache entry is not the one who filled it.
/**
 * @link ../../../../.docs/features/drift-alerts/cached-facts-not-sentences.md
 */
final readonly class InsightFacts
{
    public function __construct(
        public SavingsInsightKind $kind,
        public int $seriesId,
        public string $name,
        public int $monthlyMinor,
        public string $currency,
        public string $actionUrl,
        public string $counterpartySlug,
    ) {}
}
