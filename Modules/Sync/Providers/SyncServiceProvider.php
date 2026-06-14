<?php

declare(strict_types=1);

namespace Modules\Sync\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Http\Livewire\SyncHealthPage;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\Merge\Strategies\LwwPerFieldStrategy;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Events\TransactionMutated;

/**
 * Single-owner provider for the Sync module.
 *
 * CRITICAL: This provider is the ONLY file downstream plans ever need from
 * Plans 01/02. They create the named classes; this provider automatically
 * wires them via class_exists()-guarded blocks.
 * No downstream plan edits this file.
 *
 * Service bind inventory (by implementing plan):
 *   Plan 01: Migrations (op_log_entries, hlc_clock_state, op_log_quarantine)
 *   Plan 02: MergeRulesRegistry, LwwPerFieldStrategy, GCounterStrategy,
 *            OrSetStrategy, OpLogReplayer, DeviceKeySigner, HybridLogicalClock
 *   Plan 03: OpLogWriter (class_exists-guarded stub injection point)
 *   Plan 04: SyncCaptureListener (class_exists-guarded event wire)
 *   Plan 05: SyncHealthPage Livewire component (class_exists-guarded)
 *
 * ## HybridLogicalClock is TRANSIENT (bind, not singleton)
 *
 * The HLC holds mutable $l/$c state; sharing a singleton would leak clock
 * state across unrelated callers. Each resolve gets a fresh zero-state HLC.
 */
final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // TRANSIENT — fresh HLC instance per resolve (mutable state must not be shared).
        $this->app->bind(HybridLogicalClock::class);

        // Strategy singletons (stateless, safe to share).
        $this->app->singleton(LwwPerFieldStrategy::class);
        $this->app->singleton(GCounterStrategy::class);
        $this->app->singleton(OrSetStrategy::class);

        // MergeRulesRegistry singleton (immutable config, safe to share).
        $this->app->singleton(MergeRulesRegistry::class);

        // DeviceKeySigner singleton (stateless verifier, safe to share).
        $this->app->singleton(DeviceKeySigner::class);

        // Production OpLogReplayer with empty device-key map by default.
        // Tests inject their own throwaway map by constructing OpLogReplayer directly.
        $this->app->bind(
            OpLogReplayer::class,
            fn () => new OpLogReplayer(
                $this->app->make(DatabaseManager::class),
                [],
                $this->app->make(MergeRulesRegistry::class),
            ),
        );

        // Plan 03 injection point: OpLogWriter requires device credentials resolved
        // at runtime. This guard lets Plan 03 wire the real singleton without modifying
        // this provider. Until then, the class simply does not exist.
        if (class_exists(OpLogWriter::class)) {
            // Concrete construction (device id, keys, userId) is Plan 03's responsibility.
            // Downstream callers must resolve OpLogWriter with explicit constructor args
            // via app(OpLogWriter::class, [...]) or a factory closure added in Plan 03.
            $this->app->singleton(OpLogWriter::class);
        }
    }

    public function boot(Dispatcher $events): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Plan 04: capture listener wired once the class exists (D-05).
        if (class_exists(SyncCaptureListener::class) &&
            class_exists(TransactionMutated::class)) {
            $events->listen(
                TransactionMutated::class,
                [SyncCaptureListener::class, 'handle'],
            );
        }

        // Plan 05: Sync health-check Livewire component (class_exists-guarded).
        if (class_exists(LivewireManager::class) &&
            class_exists(SyncHealthPage::class)) {
            /** @var LivewireManager $livewire */
            $livewire = $this->app->make(LivewireManager::class);
            $livewire->component(
                'sync.sync-health-page',
                SyncHealthPage::class,
            );
        }
    }
}
