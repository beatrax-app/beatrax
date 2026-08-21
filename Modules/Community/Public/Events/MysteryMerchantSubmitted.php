<?php

declare(strict_types=1);

namespace Modules\Community\Public\Events;

final class MysteryMerchantSubmitted
{
    public function __construct(
        public readonly int $userId,
        public readonly string $pattern,
    ) {}
}
