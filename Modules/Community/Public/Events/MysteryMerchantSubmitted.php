<?php

declare(strict_types=1);

namespace Modules\Community\Public\Events;

final readonly class MysteryMerchantSubmitted
{
    public function __construct(
        public int $userId,
        public string $pattern,
    ) {}
}
