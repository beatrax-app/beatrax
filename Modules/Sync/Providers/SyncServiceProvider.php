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
use Modules\Notifications\Public\Events\NotificationPreferenceMutated;
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
use Modules\Sync\Public\Events\NotificationMutated;
use Modules\Sync\Public\Events\SavedReportMutated;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\EncryptionMigrationSupport;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../.docs/features/sync/architecture.md
 */
final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Transient — fresh HLC instance per resolve (mutable state must
        // not be shared across unrelated callers).
        $this->app->bind(HybridLogicalClock::class);

        $this->app->singleton(LwwPerFieldStrategy::class);
        $this->app->singleton(GCounterStrategy::class);
        $this->app->singleton(OrSetStrategy::class);
        $this->app->singleton(MergeRulesRegistry::class);
        $this->app->singleton(DeviceKeySigner::class);

        // GDK crypto primitives (class_exists-guarded so this provider
        // stays clean at every intermediate commit — stateless/DI-resolved,
        // safe to share).
        if (class_exists(OpLogFieldCrypto::class)) {
            $this->app->singleton(OpLogFieldCrypto::class);
        }
        if (class_exists(GdkKeyringService::class)) {
            $this->app->singleton(GdkKeyringService::class);
        }
        if (class_exists(SensitiveColumnCodec::class)) {
            $this->app->singleton(SensitiveColumnCodec::class);
        }

        // Minimal Public wrapper EncryptionMigrationService consumes
        // instead of reaching into Modules\Sync\Internal\Crypto directly.
        // NOT a singleton — it caches one primed epoch's raw key material
        // for the duration of a single migration pass.
        if (class_exists(EncryptionMigrationSupport::class)) {
            $this->app->bind(EncryptionMigrationSupport::class);
        }

        // Single-owner forward registration: downstream classes are wired
        // the moment they exist, referenced by runtime-built FQCN so
        // PHPStan stays clean before each class exists.
        $cryptoNamespace = 'Modules\Sync\Internal\Crypto\\';
        // Device-removal rotation orchestration. The ctor takes no Session
        // — every method that needs one takes it as a per-call parameter,
        // so this singleton can never capture a stale session.
        $this->singletonIfExists($cryptoNamespace.'GdkRotationService');

        // GdkRewrapService bound behind its own contract (the listener
        // wire is registered in boot() below). GdkRewrapContract is an
        // INTERFACE — class_exists() always returns false for interfaces,
        // so the guard uses interface_exists() instead.
        $gdkRewrapContract = $cryptoNamespace.'GdkRewrapContract';
        $gdkRewrapService = $cryptoNamespace.'GdkRewrapService';
        if (interface_exists($gdkRewrapContract) && class_exists($gdkRewrapService)) {
            $this->app->bind($gdkRewrapContract, $gdkRewrapService);
        }

        $this->singletonIfExists($cryptoNamespace.'GdkEpochControlHandler');

        // Production OpLogReplayer fed the confirmed-only device-key map.
        // Tests inject their own throwaway map by constructing OpLogReplayer
        // directly.
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

        // Device-identity services (class_exists-guarded). Each is
        // stateless / DI-resolved, safe to share.
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

        // SafetyNumberDeriver needs the BIP39 word list as its sole
        // constructor arg; bind it explicitly so callers can resolve it
        // without re-passing the list.
        if (class_exists(SafetyNumberDeriver::class) && class_exists(Bip39WordList::class)) {
            $this->app->singleton(
                SafetyNumberDeriver::class,
                fn () => new SafetyNumberDeriver(Bip39WordList::WORDS),
            );
        }

        // Pairing classes auto-wire the moment they exist. Referenced by
        // runtime-built FQCN (not `use` imports / `::class`) so this
        // provider stays PHPStan-clean before they exist.
        $pairingNamespace = 'Modules\Sync\Internal\Pairing\\';
        foreach ([
            'PairingTokenService',
            'PairingStateMachine',
            'WordCodeEncoder',
            'QrPayloadBuilder',
            // Relay courier for the cross-device both-confirm handshake
            // (PairingFrame is static-only, no binding needed).
            'PairingRelayCourier',
        ] as $pairingClass) {
            $this->singletonIfExists($pairingNamespace.$pairingClass);
        }

        // OpLogWriter requires device credentials resolved at runtime.
        // Concrete construction (device id, keys, userId) is the caller's
        // responsibility: resolve it with explicit constructor args via
        // app(OpLogWriter::class, [...]) or a factory closure.
        if (class_exists(OpLogWriter::class)) {
            $this->app->singleton(OpLogWriter::class);
        }

        // Transport services (class_exists-guarded), referenced by
        // runtime-built FQCN strings so PHPStan stays clean before the
        // classes exist. This provider is the single owner.
        $transportNamespace = 'Modules\Sync\Internal\Transport\\';
        $discoveryNamespace = $transportNamespace.'Discovery\\';
        $relayNamespace = $transportNamespace.'Relay\\';

        // Noise state machine classes are NOT singletons — they hold
        // mutable crypto state and must be constructed fresh per call;
        // callers instantiate directly (no DI container resolution needed).

        $syncStatusService = 'Modules\Sync\Public\Services\SyncStatusService';
        $this->singletonIfExists($syncStatusService);

        // SyncSession: per-peer session (mutable, not singleton — each
        // connection gets its own). The guard lets the container resolve
        // it directly, but in practice it's constructed by the WS handler.
        $this->singletonIfExists($transportNamespace.'SyncSession');

        $this->singletonIfExists($transportNamespace.'PeerCatchUpExchanger');

        $this->singletonIfExists($relayNamespace.'RelayClient');
        $this->singletonIfExists($relayNamespace.'RelayConfig');

        // Wraps a long-lived dns-sd/avahi process — singleton so the
        // container reuses the same process handle across resolutions.
        $this->singletonIfExists($discoveryNamespace.'MdnsBrowser');

        // Bound as a factory so the container can resolve it from
        // SyncServeCommand's constructor without requiring runtime device
        // credentials at bind time — placeholder credentials reject all
        // peers until the real host resolves them (see architecture doc).
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
                    // Placeholder credentials — real values injected at
                    // daemon start by NativePHP ChildProcess or DeviceIdentityLoader.
                    localStaticSecret: '',
                    localStaticPublic: '',
                    localDeviceId: '',
                    userId: 0,
                    // Container-bound merge registry + FTS writer so the
                    // live sync replay path keeps the search index fresh,
                    // matching every other replay path.
                    rules: $this->app->make(MergeRulesRegistry::class),
                    searchWriter: $this->app->bound(SearchIndexWriterContract::class)
                        ? $this->app->make(SearchIndexWriterContract::class)
                        : null,
                ),
            );
        }

        if (class_exists(RelayServeCommand::class)) {
            $this->app->singleton(RelayServeCommand::class);
        }

        if (class_exists(SyncServeCommand::class)) {
            // The handler is a factory, not an instance: registering a console
            // command resolves it, and building it reaches the encrypted search
            // writer — which made every artisan call need an application key,
            // including the `key:generate` that mints one.
            $this->app->singleton(SyncServeCommand::class, fn () => new SyncServeCommand(
                logger: $this->app->make(LoggerInterface::class),
                handler: fn () => $this->app->make(SyncWebSocketHandler::class),
                advertiser: $this->app->make(MdnsAdvertiser::class),
            ));
        }

        // Relay mailbox + mDNS advertiser singletons. RelayConfig is
        // already registered above via singletonIfExists().
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

        // Capture listener wired once the class exists — this is the
        // single-owner forward-registration precedent used throughout.
        if (class_exists(SyncCaptureListener::class) &&
            class_exists(TransactionMutated::class)) {
            $events->listen(
                TransactionMutated::class,
                [SyncCaptureListener::class, 'handle'],
            );
        }

        // Split-leg capture listener. Same class_exists-guarded pattern as
        // the TransactionMutated wiring above.
        if (class_exists(SyncCaptureListener::class) &&
            class_exists(TransactionSplitMutated::class)) {
            $events->listen(
                TransactionSplitMutated::class,
                [SyncCaptureListener::class, 'handleSplit'],
            );
        }

        // Envelope-table capture listeners. Same class_exists-guarded
        // pattern as the wiring above.
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

        // Saved-report capture listener. Same class_exists-guarded pattern
        // as the envelope wiring above.
        if (class_exists(SyncCaptureListener::class) &&
            class_exists(SavedReportMutated::class)) {
            $events->listen(
                SavedReportMutated::class,
                [SyncCaptureListener::class, 'handleSavedReport'],
            );
        }

        // Notification capture listeners. Same class_exists-guarded
        // pattern as the saved-report wiring above.
        if (class_exists(SyncCaptureListener::class) &&
            class_exists(NotificationMutated::class)) {
            $events->listen(
                NotificationMutated::class,
                [SyncCaptureListener::class, 'handleNotificationMutated'],
            );
        }

        // Per-device notification-preference capture listener. Same
        // class_exists-guarded pattern as the wiring above.
        if (class_exists(SyncCaptureListener::class) &&
            class_exists(NotificationPreferenceMutated::class)) {
            $events->listen(
                NotificationPreferenceMutated::class,
                [SyncCaptureListener::class, 'handleNotificationPreferenceMutated'],
            );
        }

        // Passphrase-change GDK re-wrap. Referenced by runtime-built FQCN
        // so this provider stays PHPStan-clean before either class exists —
        // the single-owner forward-registration precedent used throughout.
        $authPassphraseChanged = 'Modules\Auth\Public\Events\AppLockPassphraseChanged';
        $rewrapListener = 'Modules\Sync\Internal\Crypto\RewrapGdkOnPassphraseChange';
        if (class_exists($authPassphraseChanged) && class_exists($rewrapListener)) {
            $events->listen($authPassphraseChanged, [$rewrapListener, 'handle']);
        }

        // Sync health-check Livewire component. Registered by runtime FQCN
        // so PHPStan stays clean before the class exists.
        if (class_exists(LivewireManager::class) &&
            class_exists(SyncHealthPage::class)) {
            /** @var LivewireManager $livewire */
            $livewire = $this->app->make(LivewireManager::class);
            $livewire->component(
                'sync.sync-health-page',
                SyncHealthPage::class,
            );
        }

        // Register sync:serve and relay:serve artisan daemons. Both are
        // class_exists-guarded so the provider stays clean before they
        // ship. Registered in boot() (not app/Console/Kernel.php) — per
        // the module boundary rule.
        if (class_exists(SyncServeCommand::class)) {
            $this->commands([SyncServeCommand::class]);
        }

        if (class_exists(RelayServeCommand::class)) {
            $this->commands([RelayServeCommand::class]);
        }

        // Devices & Sync settings section + pairing-flow modal. Referenced
        // by runtime-built FQCN (not `use` imports / `::class`) so this
        // provider stays PHPStan-clean before they exist; they register
        // the moment they exist on disk.
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

            // Sync-status Livewire surface: shows per-peer online/offline
            // status + last-sync time. Registered by runtime FQCN so
            // PHPStan stays clean before it ships.
            $syncStatus = $livewireNamespace.'SyncStatusSection';
            if (class_exists($syncStatus)) {
                $livewire->component('sync.sync-status-section', $syncStatus);
            }
        }
    }

    // Register a singleton for a class that may not exist yet (forward-
    // looking wiring). The class name arrives as a runtime-built string so
    // PHPStan does not fold the class_exists() guard to an impossible type.
    private function singletonIfExists(string $class): void
    {
        if (class_exists($class)) {
            $this->app->singleton($class);
        }
    }

    // Resolve the authenticated user id for the OpLogReplayer device-key
    // map, falling back to 0 in console / unauthenticated contexts so the
    // bind never throws during boot.
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
