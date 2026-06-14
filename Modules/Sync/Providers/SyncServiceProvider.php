<?php

declare(strict_types=1);

namespace Modules\Sync\Providers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\OpLog\OpLogReplayer;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

/**
 * Wires the Sync spike module.
 *
 * register() binds the three Internal services:
 *   - HybridLogicalClock as TRANSIENT (bind, not singleton) — the HLC holds
 *     mutable $l/$c state; sharing a singleton would leak clock state across
 *     unrelated callers. Each resolve gets a fresh zero-state HLC.
 *   - OpLogReplayer with an empty device-id → hex-pubkey map by default;
 *     tests inject their own throwaway map at construction time.
 *   - DeviceKeySigner as singleton — stateless verifier, safe to share.
 *
 * boot() is empty for the spike: no migrations, no routes, no views,
 * no Livewire components.
 */
final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // TRANSIENT — fresh HLC instance per resolve (mutable state must not be shared).
        $this->app->bind(HybridLogicalClock::class);

        // Default empty device-key map; tests override by constructing OpLogReplayer directly.
        $this->app->bind(
            OpLogReplayer::class,
            fn ($app) => new OpLogReplayer($app->make(DatabaseManager::class), []),
        );

        $this->app->singleton(DeviceKeySigner::class);
    }

    public function boot(): void
    {
        // Spike module: no migrations, routes, views, or Livewire components.
    }
}
