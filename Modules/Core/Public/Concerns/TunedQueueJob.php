<?php

declare(strict_types=1);

namespace Modules\Core\Public\Concerns;

// The shared retry profile every queued job in the app carries: at most three
// attempts, backing off 1 / 5 / 15 minutes between them. A job `use`s this and
// overrides $tries or $backoff only when it needs a different profile.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
trait TunedQueueJob
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];
}
