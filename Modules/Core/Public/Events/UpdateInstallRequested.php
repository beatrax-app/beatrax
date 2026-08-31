<?php

declare(strict_types=1);

namespace Modules\Core\Public\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class UpdateInstallRequested
{
    use Dispatchable;

    public function __construct(public string $latestVersion) {}
}
