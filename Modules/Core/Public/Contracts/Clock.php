<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

use Carbon\CarbonImmutable;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
