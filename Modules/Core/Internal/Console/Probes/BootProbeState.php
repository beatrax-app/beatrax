<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

// A DI'd singleton (not `private static bool $booted` on the provider
// itself) sidesteps the reflection-on-static-state code path
// larastan-strict-rules flags, and gives tests a first-class injection seam
// (`$this->app->instance(BootProbeState::class, new BootProbeState())`).
final class BootProbeState
{
    public bool $booted = false;
}
