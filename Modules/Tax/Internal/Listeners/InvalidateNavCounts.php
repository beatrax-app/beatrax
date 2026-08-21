<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Listeners;

use Modules\Core\Public\Services\NavCountsService;
use Modules\Tax\Public\Events\TransactionTagged;
use Modules\Tax\Public\Events\TransactionUntagged;

final class InvalidateNavCounts
{
    public function __construct(
        private readonly NavCountsService $navCounts,
    ) {}

    public function handle(TransactionTagged|TransactionUntagged $event): void
    {
        $this->navCounts->forget($event->userId);
    }
}
