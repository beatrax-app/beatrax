<?php

declare(strict_types=1);

namespace Modules\Core\Public\Events;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class UserInstalled
{
    public function __construct(public readonly int $userId) {}
}
