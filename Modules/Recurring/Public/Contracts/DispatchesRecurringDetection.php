<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Contracts;

// Keeping the concrete DetectRecurringSeriesJob class on the Internal
// surface (never referenced here) preserves the cross-module boundary
// that App\PhpStan\Rules\BoundaryRule enforces.

interface DispatchesRecurringDetection
{
    /**
     * @return void idempotent at the contract layer — ShouldBeUniqueUntilProcessing inside
     *              the job class collapses duplicate dispatches for the same user into a single queued
     *              instance
     */
    public function dispatchForUser(int $userId): void;
}
