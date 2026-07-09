<?php

declare(strict_types=1);

namespace Modules\Sync\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Commands\RelayServeCommand;
use Modules\Sync\Commands\SyncServeCommand;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Http\Livewire\SyncHealthPage;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Identity\DeviceNameDetector;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\Merge\Strategies\LwwPerFieldStrategy;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\Pairing\Bip39WordList;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;
use Modules\Sync\Public\Events\SavedReportMutated;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;

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
 *   Phase 13 Plan 05: SyncServeCommand, RelayServeCommand, SyncWebSocketHandler
 *            factory binding, MdnsAdvertiser/RelayMailbox/RelayConfig singletons
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

        // Phase 14 Plan 02: GDK crypto primitives (class_exists-guarded so
        // this provider stays clean at every intermediate commit of this
        // very plan — both classes are stateless/DI-resolved, safe to share).
        if (class_exists(OpLogFieldCrypto::class)) {
            $this->app->singleton(OpLogFieldCrypto::class);
        }
        if (class_exists(GdkKeyringService::class)) {
            $this->app->singleton(GdkKeyringService::class);
        }
        if (class_exists(SensitiveColumnCodec::class)) {
            $this->app->singleton(SensitiveColumnCodec::class);
        }

        // Phase 14 single-owner forward registration (mirrors the Phase 13
        // transport-namespace pattern above): Plans 05/07/08 create these
        // classes; this guard wires them the moment they exist so those
        // plans never need to touch this provider (STATE [11-02] precedent).
        // Referenced by runtime-built FQCN so PHPStan stays clean before
        // each class exists.
        $cryptoNamespace = 'Modules\Sync\Internal\Crypto\\';
        // Plan 05: device-removal rotation orchestration (D-04/D-05).
        $this->singletonIfExists($cryptoNamespace.'GdkRotationService');

        // Plan 07: GdkRewrapService bound behind its own contract, and the
        // Auth AppLockPassphraseChanged -> RewrapGdkOnPassphraseChange
        // listener wire (registered in boot() below, once both classes exist).
        // GdkRewrapContract is an INTERFACE, not a class — class_exists()
        // always returns false for interfaces (PHP semantics), so the guard
        // uses interface_exists() for it (Rule 1 bug fix, 14-07).
        $gdkRewrapContract = $cryptoNamespace.'GdkRewrapContract';
        $gdkRewrapService = $cryptoNamespace.'GdkRewrapService';
        if (interface_exists($gdkRewrapContract) && class_exists($gdkRewrapService)) {
            $this->app->bind($gdkRewrapContract, $gdkRewrapService);
        }

        // Plan 08: GDK_EPOCH_WRAP control-message handler.
        $this->singletonIfExists($cryptoNamespace.'GdkEpochControlHandler');

        // Production OpLogReplayer fed the confirmed-only device-key map
        // (Phase 12: turns the empty Phase 11 stub into the real registry map).
        // Tests inject their own throwaway map by constructing OpLogReplayer directly.
        $this->app->bind(
            OpLogReplayer::class,
            function () {
                $deviceKeys = class_exists(DeviceRegistryService::class)
                    ? $this->app->make(DeviceRegistryService::class)->deviceKeys($this->currentUserId())
                    : [];

                return new OpLogReplayer(
                    $this->app->make(DatabaseManager::class),
                    $deviceKeys,
                    $this->app->make(MergeRulesRegistry::class),
                );
            },
        );

        // Phase 12: device-identity services (class_exists-guarded so plans wire
        // independently). Each is stateless / DI-resolved, safe to share.
        if (class_exists(DeviceIdentityService::class)) {
            $this->app->singleton(DeviceIdentityService::class);
        }
        if (class_exists(DeviceIdentityLoader::class)) {
            $this->app->singleton(DeviceIdentityLoader::class);
        }
        if (class_exists(DeviceNameDetector::class)) {
            $this->app->singleton(DeviceNameDetector::class);
        }
        if (class_exists(DeviceRegistryService::class)) {
            $this->app->singleton(DeviceRegistryService::class);
        }

        // Phase 12 pairing services. SafetyNumberDeriver needs the BIP39 word
        // list as its sole constructor arg; bind it explicitly so callers can
        // resolve it without re-passing the list.
        if (class_exists(SafetyNumberDeriver::class) && class_exists(Bip39WordList::class)) {
            $this->app->singleton(
                SafetyNumberDeriver::class,
                fn () => new SafetyNumberDeriver(Bip39WordList::WORDS),
            );
        }

        // The Plan 03 pairing classes auto-wire the moment they exist. They are
        // referenced by runtime-built FQCN (not `use` imports / `::class`) so
        // this provider stays PHPStan-clean before Plan 03 ships them.
        $pairingNamespace = 'Modules\Sync\Internal\Pairing\\';
        foreach ([
            'PairingTokenService',
            'PairingStateMachine',
            'WordCodeEncoder',
            'QrPayloadBuilder',
        ] as $pairingClass) {
            $this->singletonIfExists($pairingNamespace.$pairingClass);
        }

        // Plan 03 injection point: OpLogWriter requires device credentials resolved
        // at runtime. This guard lets Plan 03 wire the real singleton without modifying
        // this provider. Until then, the class simply does not exist.
        if (class_exists(OpLogWriter::class)) {
            // Concrete construction (device id, keys, userId) is Plan 03's responsibility.
            // Downstream callers must resolve OpLogWriter with explicit constructor args
            // via app(OpLogWriter::class, [...]) or a factory closure added in Plan 03.
            $this->app->singleton(OpLogWriter::class);
        }

        // Phase 13 transport services (class_exists-guarded — Waves 2-4 create these).
        // Referenced by runtime-built FQCN strings so PHPStan stays clean before
        // the classes exist. The provider is the single owner; Waves 2/3/4 create the
        // classes and this guard picks them up without re-editing the provider.
        // Plan 05 / Wave 5 is the ONLY other plan permitted to touch this provider
        // (for sync:serve / relay:serve command host-wiring).
        $transportNamespace = 'Modules\Sync\Internal\Transport\\';
        $noiseNamespace = $transportNamespace.'Noise\\';
        $discoveryNamespace = $transportNamespace.'Discovery\\';
        $relayNamespace = $transportNamespace.'Relay\\';

        // Phase 13 Wave 2: Noise state machine classes.
        // NOT singletons — these hold mutable crypto state and must be constructed fresh.
        // The guards exist so the provider knows about them; actual wiring is per-call.
        // No singleton registration: callers instantiate directly (NoiseHandshakeState
        // does not need DI container resolution; it's a pure crypto state machine).

        // Phase 13 Wave 3: transport session + peer exchanger + status service.
        // SyncStatusService: stateless DB reader, safe as singleton (mirrors DeviceRegistryService).
        $syncStatusService = 'Modules\Sync\Public\Services\SyncStatusService';
        $this->singletonIfExists($syncStatusService);

        // SyncSession: per-peer session (mutable, not singleton — each connection
        // gets its own SyncSession). The guard ensures the container can resolve it
        // if directly requested, but in practice constructed by the WS handler.
        $this->singletonIfExists($transportNamespace.'SyncSession');

        // PeerCatchUpExchanger: stateless request-response helper, safe as singleton.
        $this->singletonIfExists($transportNamespace.'PeerCatchUpExchanger');

        // Phase 13 Wave 4: relay client + config.
        $this->singletonIfExists($relayNamespace.'RelayClient');
        $this->singletonIfExists($relayNamespace.'RelayConfig');

        // Phase 13 Wave 5 (mDNS discovery, D-07):
        // MdnsBrowser wraps a long-lived dns-sd/avahi process.
        // Guard ensures class_exists check remains in one canonical place.
        $this->singletonIfExists($discoveryNamespace.'MdnsBrowser');

        // Plan 05: SyncWebSocketHandler — bound as a factory so the container can
        // resolve it from SyncServeCommand's constructor without requiring runtime
        // device credentials at bind time. The factory provides empty placeholder
        // credentials (empty string); the handler's auth gate (SyncSession::authenticate)
        // will reject all peers when userId=0 (no confirmed devices for userId 0).
        // In production, the NativePHP ChildProcess host resolves real credentials via
        // DeviceIdentityLoader before starting sync:serve. The headless daemon exits
        // self::FAILURE if credentials are unavailable, and NativePHP auto-restarts it.
        if (class_exists(SyncWebSocketHandler::class)) {
            $this->app->bind(
                SyncWebSocketHandler::class,
                fn () => new SyncWebSocketHandler(
                    registryService: $this->app->make(DeviceRegistryService::class),
                    signer: $this->app->make(DeviceKeySigner::class),
                    framer: new TransportFramer,
                    catchUp: $this->app->make(PeerCatchUpExchanger::class),
                    db: $this->app->make(DatabaseManager::class),
                    clock: $this->app->make(Clock::class),
                    logger: $this->app->make(LoggerInterface::class),
                    localStaticSecret: '', // Placeholder — real credentials injected at daemon start
                    localStaticPublic: '',  // by NativePHP ChildProcess or DeviceIdentityLoader.
                    localDeviceId: '',
                    userId: 0,
                    // WR-07: pass the container-bound merge registry + FTS writer so the
                    // live sync replay path keeps the search index fresh, matching every
                    // other replay path. searchWriter is resolved when Search is present.
                    rules: $this->app->make(MergeRulesRegistry::class),
                    searchWriter: $this->app->bound(SearchIndexWriterContract::class)
                        ? $this->app->make(SearchIndexWriterContract::class)
                        : null,
                ),
            );
        }

        // Plan 05: relay commands — singletons (stateless long-running processes).
        if (class_exists(RelayServeCommand::class)) {
            $this->app->singleton(RelayServeCommand::class);
        }

        if (class_exists(SyncServeCommand::class)) {
            $this->app->singleton(SyncServeCommand::class);
        }

        // Plan 05: relay mailbox + mDNS advertiser singletons.
        // RelayConfig is already registered above via singletonIfExists($relayNamespace.'RelayConfig').
        if (class_exists(RelayMailbox::class)) {
            $this->app->singleton(RelayMailbox::class);
        }

        if (class_exists(MdnsAdvertiser::class)) {
            $this->app->singleton(MdnsAdvertiser::class);
        }
    }

    public function boot(Dispatcher $events): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'sync');

        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }

        // Plan 04: capture listener wired once the class exists (D-05).
        if (class_exists(SyncCaptureListener::class) &&
            class_exists(TransactionMutated::class)) {
            $events->listen(
                TransactionMutated::class,
                [SyncCaptureListener::class, 'handle'],
            );
        }

        // 13.1-03: split-leg capture listener (Req 10 / D-12). Same
        // class_exists-guarded pattern as the TransactionMutated wiring above.
        if (class_exists(SyncCaptureListener::class) &&
            class_exists(TransactionSplitMutated::class)) {
            $events->listen(
                TransactionSplitMutated::class,
                [SyncCaptureListener::class, 'handleSplit'],
            );
        }

        // 13.2-05 (Req 11 / D-25): envelope-table capture listeners. Same
        // class_exists-guarded pattern as the TransactionMutated/
        // TransactionSplitMutated wiring above.
        if (class_exists(SyncCaptureListener::class) &&
            class_exists(EnvelopeAssignmentMutated::class)) {
            $events->listen(
                EnvelopeAssignmentMutated::class,
                [SyncCaptureListener::class, 'handleEnvelopeAssignment'],
            );
        }

        if (class_exists(SyncCaptureListener::class) &&
            class_exists(EnvelopeMoveMutated::class)) {
            $events->listen(
                EnvelopeMoveMutated::class,
                [SyncCaptureListener::class, 'handleEnvelopeMove'],
            );
        }

        if (class_exists(SyncCaptureListener::class) &&
            class_exists(EnvelopeSettingMutated::class)) {
            $events->listen(
                EnvelopeSettingMutated::class,
                [SyncCaptureListener::class, 'handleEnvelopeSetting'],
            );
        }

        // 999.6-01 (Req 9/10, D-02): saved-report capture listener. Same
        // class_exists-guarded pattern as the envelope wiring above.
        if (class_exists(SyncCaptureListener::class) &&
            class_exists(SavedReportMutated::class)) {
            $events->listen(
                SavedReportMutated::class,
                [SyncCaptureListener::class, 'handleSavedReport'],
            );
        }

        // Phase 14 Plan 07: passphrase-change GDK re-wrap (D-10). Referenced by
        // runtime-built FQCN so this provider stays PHPStan-clean before Plan
        // 07 ships either class — the single-owner forward-registration
        // precedent (STATE [11-02]) so Plan 07 never needs to edit this file.
        $authPassphraseChanged = 'Modules\Auth\Public\Events\AppLockPassphraseChanged';
        $rewrapListener = 'Modules\Sync\Internal\Crypto\RewrapGdkOnPassphraseChange';
        if (class_exists($authPassphraseChanged) && class_exists($rewrapListener)) {
            $events->listen($authPassphraseChanged, [$rewrapListener, 'handle']);
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

        // Plan 05: Register sync:serve and relay:serve artisan daemons.
        // Both are class_exists-guarded so the provider stays clean before Plan 05 ships them.
        // Registered in boot() (not app/Console/Kernel.php) — per the module boundary rule
        // (PATTERNS.md BackupDatabaseCommand analog).
        if (class_exists(SyncServeCommand::class)) {
            $this->commands([SyncServeCommand::class]);
        }

        if (class_exists(RelayServeCommand::class)) {
            $this->commands([RelayServeCommand::class]);
        }

        // Plan 04: Devices & Sync settings section + pairing-flow modal.
        // Referenced by runtime-built FQCN (not `use` imports / `::class`) so
        // this provider stays PHPStan-clean before Plan 04 ships them; they
        // register the moment they exist on disk.
        if (class_exists(LivewireManager::class)) {
            /** @var LivewireManager $livewire */
            $livewire = $this->app->make(LivewireManager::class);

            $livewireNamespace = 'Modules\Sync\Internal\Http\Livewire\\';
            $devicesSection = $livewireNamespace.'DevicesAndSyncSettingsSection';
            if (class_exists($devicesSection)) {
                $livewire->component('sync.devices-and-sync-settings-section', $devicesSection);
            }

            $pairingModal = $livewireNamespace.'PairingFlowModal';
            if (class_exists($pairingModal)) {
                $livewire->component('sync.pairing-flow-modal', $pairingModal);
            }

            // Phase 13 Wave 3: sync-status Livewire surface (D-06).
            // SyncStatusSection shows per-peer online/offline status + last-sync time.
            // Registered by runtime FQCN so PHPStan stays clean before Wave 3 ships it.
            // sync:serve and relay:serve command registration is Plan 05 scope — NOT here.
            $syncStatus = $livewireNamespace.'SyncStatusSection';
            if (class_exists($syncStatus)) {
                $livewire->component('sync.sync-status-section', $syncStatus);
            }
        }
    }

    /**
     * Register a singleton for a class that may not exist yet (forward-looking
     * Plan 03/04 wiring). The class name arrives as a runtime-built string so
     * PHPStan does not fold the class_exists() guard to an impossible type.
     */
    private function singletonIfExists(string $class): void
    {
        if (class_exists($class)) {
            $this->app->singleton($class);
        }
    }

    /**
     * Resolve the authenticated user id for the OpLogReplayer device-key map,
     * falling back to 0 in console / unauthenticated contexts so the bind
     * never throws during boot.
     */
    private function currentUserId(): int
    {
        if (! $this->app->bound(CurrentUser::class)) {
            return 0;
        }

        try {
            return $this->app->make(CurrentUser::class)->user()->id;
        } catch (NotAuthenticatedException) {
            return 0;
        }
    }
}
