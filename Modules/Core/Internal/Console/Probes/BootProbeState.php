<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

/**
 * Mutable singleton that holds the per-process `booted` flag used by
 * `HealthCheckServiceProvider`'s `ConnectionEstablished` listener to
 * de-duplicate writes within the same process. Tests reset the flag by
 * binding a fresh instance via `$this->app->instance(BootProbeState::class,
 * new BootProbeState());`.
 *
 * This class deliberately replaces the `private static bool $booted`
 * pattern on the provider itself: a DI'd singleton sidesteps the
 * reflection-on-static-state code path that larastan-strict-rules
 * flags, and gives tests a first-class injection seam instead of
 * `ReflectionClass::setStaticPropertyValue`.
 */
final class BootProbeState
{
    public bool $booted = false;
}
