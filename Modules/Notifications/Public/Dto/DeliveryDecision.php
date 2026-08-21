<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Dto;

/**
 * @see SuppressionEvaluator::shouldDeliver()
 */
final readonly class DeliveryDecision
{
    public function __construct(
        public bool $deliver,
        public string $reason,
        public bool $hideDetails,
    ) {}
}
