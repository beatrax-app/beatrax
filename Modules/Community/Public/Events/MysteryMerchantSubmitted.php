<?php

declare(strict_types=1);

namespace Modules\Community\Public\Events;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
final class MysteryMerchantSubmitted
{
    public function __construct(
        public readonly int $userId,
        public readonly string $pattern,
    ) {}
}
