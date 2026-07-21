<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Dto;

/**
 * @see SuppressionEvaluator::shouldDeliver()
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final readonly class DeliveryDecision
{
    public function __construct(
        public bool $deliver,
        public string $reason,
        public bool $hideDetails,
    ) {}
}
