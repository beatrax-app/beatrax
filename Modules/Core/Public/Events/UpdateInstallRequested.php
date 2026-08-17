<?php

declare(strict_types=1);

namespace Modules\Core\Public\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class UpdateInstallRequested
{
    use Dispatchable;

    public function __construct(public readonly string $latestVersion) {}
}
