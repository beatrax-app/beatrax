<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking\Dto;

use Carbon\CarbonImmutable;

/**
 * Decoded `:60F:` / `:62F:` balance tag content — signed integer minor
 * amount, currency, and balance date. Used by `Mt940Adapter` to
 * populate the statement-summary metadata.
 *
 * Lives under `Internal/` because the shape is parser-implementation
 * detail.
 */
final readonly class Mt940BalanceTuple
{
    public function __construct(
        public int $minor,
        public string $currency,
        public CarbonImmutable $date,
    ) {}
}
