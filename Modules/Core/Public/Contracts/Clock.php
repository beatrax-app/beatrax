<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

use Carbon\CarbonImmutable;

/**
 * Public contract for "the current moment". Domain code injects this contract
 * instead of calling the `now()` helper or `Carbon::now()` so time can be
 * frozen in tests and the rest of the codebase stays facade-free.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
