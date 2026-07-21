<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Listeners;

use Modules\Core\Public\Services\NavCountsService;
use Modules\Tax\Public\Events\TransactionTagged;
use Modules\Tax\Public\Events\TransactionUntagged;

// Drops the per-user nav-counts cache whenever a tax tag is added or
// removed, so the sidebar tax_tagged badge reflects the change on the
// next render instead of waiting out the cache TTL. NavCountsService is
// Core Public — calling it from Tax honours the module boundary.
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
