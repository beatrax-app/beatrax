<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

use Carbon\CarbonImmutable;

interface Clock
{
    public function now(): CarbonImmutable;
}
