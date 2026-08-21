<?php

declare(strict_types=1);

namespace Modules\Sync\Providers;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Import\Public\Contracts\CapturesImportForSync;
use Modules\Ledger\Public\Contracts\CapturesTransactionsForSync;
use Modules\Notifications\Public\Events\NotificationPreferenceMutated;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Commands\RelayServeCommand;
use Modules\Sync\Commands\SyncServeCommand;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\LibsodiumPrimitives;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Crypto\SodiumPrimitives;
use Modules\Sync\Internal\Http\Livewire\SyncHealthPage;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Identity\DeviceNameDetector;
use Modules\Sync\Internal\Listeners\BackfillOpLogOnSyncEnabled;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\Merge\Strategies\LwwPerFieldStrategy;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\Pairing\Bip39WordList;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingOfferService;
use Modules\Sync\Internal\Pairing\PairingPeerOutbox;
use Modules\Sync\Internal\Pairing\PairingPullAuthorizer;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\Discovery\CachedPeerDiscovery;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\Discovery\MulticastMdnsQuery;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Events\DeviceSyncEnabled;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;
use Modules\Sync\Public\Events\GoalContributionMutated;
use Modules\Sync\Public\Events\GoalMutated;
use Modules\Sync\Public\Events\NotificationMutated;
use Modules\Sync\Public\Events\SavedReportMutated;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Modules\Sync\Public\Services\BlindIndexCodec;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\EncryptionMigrationSupport;
use Modules\Sync\Public\Services\ImportSyncCapture;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Public\Services\SyncDaemonIdentity;
use Psr\Log\LoggerInterface;

final class SyncServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        // Sync is loaded, so an import can be recorded for the user's other
        // devices. Overrides the Import module's no-op default.
        $this->app->singleton(CapturesImportForSync::class, ImportSyncCapture::class);
        $this->app->singleton(CapturesTransactionsForSync::class, ImportSyncCapture::class);

        $this->registerMergeAndSigning();
        $this->registerCryptoServices();
        $this->registerReplayer();
        $this->registerIdentityServices();
        $this->registerPairingServices();
        $this->registerTransportServices();
        $this->registerCommandsAndMailbox();
    }

    private function registerMergeAndSigning(): void
    {
        // Transient — fresh HLC instance per resolve (mutable state must
        // not be shared across unrelated callers).
        $this->app->bind(HybridLogicalClock::class);

        $this->app->singleton(LwwPerFieldStrategy::class);
        $this->app->singleton(GCounterStrategy::class);
        $this->app->singleton(OrSetStrategy::class);
        $this->app->singleton(MergeRulesRegistry::class);
        $this->app->singleton(DeviceKeySigner::class);

        // The libsodium conversions the crypto paths run inside their
        // try-blocks, behind an interface so a test can make them fail.
        $this->app->singleton(SodiumPrimitives::class, LibsodiumPrimitives::class);
    }

    private function registerCryptoServices(): void
    {
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
        if (class_exists(BlindIndexCodec::class)) {
            $this->app->singleton(BlindIndexCodec::class);
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
    }

    private function registerReplayer(): void
    {
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
    }

    private function registerIdentityServices(): void
    {
        // Device-identity services (class_exists-guarded). Each is
        // stateless / DI-resolved, safe to share.
        if (class_exists(DeviceIdentityService::class)) {
            $this->app->singleton(DeviceIdentityService::class);
        }
        // Not a singleton: it holds no state, and caching one instance froze
        // whichever AppLockKeyService was bound at first resolve. A daemon
        // handoff now reads the identity during DeviceSyncEnabled, which is
        // early enough for that freeze to outlive a later rebind.
        if (class_exists(DeviceIdentityLoader::class)) {
            $this->app->bind(DeviceIdentityLoader::class);
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
    }

    private function registerPairingServices(): void
    {
        // Pairing classes auto-wire the moment they exist. Referenced by
        // runtime-built FQCN (not `use` imports / `::class`) so this
        // provider stays PHPStan-clean before they exist.
        $pairingNamespace = 'Modules\Sync\Internal\Pairing\\';
        foreach ([
            'PairingTokenService',
            'PairingStateMachine',
            'WordCodeEncoder',
            'QrPayloadBuilder',
            // Collaborators PairingTokenService delegates to: device-registry
            // admission and the relayed PAIR_CONFIRM anti-forgery gate. Each
            // owns the crypto deps it uses so the host stays a thin orchestrator.
            'PairedDeviceAdmitter',
            'PeerConfirmVerifier',
            // Applies an inbound frame whichever transport carried it, so the
            // relay drain and the LAN route cannot drift apart.
            'PairingFrameApplier',
            'LanPairingFrameCourier',
            'LanPairingFramePuller',
            'PairingPeerOutbox',
            // Decides who may collect what the LAN return leg holds.
            'PairingPullAuthorizer',
            // Relay courier for the cross-device both-confirm handshake
            // (PairingFrame is static-only, no binding needed).
            'PairingFrameCourier',
        ] as $pairingClass) {
            $this->singletonIfExists($pairingNamespace.$pairingClass);
        }

        // OpLogWriter takes four runtime primitives no autowiring can
        // supply, so EVERY resolution threw "Unresolvable dependency".
        // SyncCaptureListener swallowed that: nothing was ever captured,
        // and a paired device received an empty database.
        if (class_exists(OpLogWriter::class)) {
            $this->app->bind(
                OpLogWriter::class,
                function (Container $app, array $parameters): OpLogWriter {
                    /** @var array<string, mixed> $parameters */
                    return $parameters === []
                        ? $this->makeOpLogWriter($app)
                        : $this->makeOpLogWriterWith($app, $parameters);
                },
            );
        }
    }

    // Throws (not returns null) when no identity is available: an unlocked
    // key is a precondition for signing, and callers already treat a failed
    // resolution as "capture is not possible right now".
    /**
     * @throws BindingResolutionException when sync is off, locked, or the
     *                                    request has no authenticated user.
     */
    private function makeOpLogWriter(Container $app): OpLogWriter
    {
        $currentUser = $app->make(CurrentUser::class);

        if (! $currentUser->isAuthenticated()) {
            throw new BindingResolutionException('OpLogWriter: no authenticated user to capture for.');
        }

        $userId = $currentUser->id();
        $sessionFactory = $app->make(SessionFactory::class);
        $identity = $app->make(DeviceIdentityLoader::class)->load($userId, $sessionFactory());

        if ($identity === null) {
            throw new BindingResolutionException('OpLogWriter: no usable device identity (sync off or locked).');
        }

        return $this->buildOpLogWriter(
            $app,
            $identity->deviceId,
            $userId,
            sodium_hex2bin($identity->ed25519SecretKeyHex),
            sodium_hex2bin($identity->ed25519PublicKeyHex),
        );
    }

    // Callers that already hold credentials (tests, and any future
    // multi-identity caller) pass them explicitly to app(); honouring that
    // keeps the make-with-parameters contract this binding replaced.
    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws BindingResolutionException when a credential is missing or the wrong type.
     */
    private function makeOpLogWriterWith(Container $app, array $parameters): OpLogWriter
    {
        $deviceId = $parameters['deviceId'] ?? null;
        $userId = $parameters['userId'] ?? null;
        $secretKey = $parameters['secretKey'] ?? null;
        $publicKey = $parameters['publicKey'] ?? null;

        if (! is_string($deviceId) || ! is_int($userId) || ! is_string($secretKey) || ! is_string($publicKey)) {
            throw new BindingResolutionException('OpLogWriter: explicit credentials are incomplete.');
        }

        return $this->buildOpLogWriter($app, $deviceId, $userId, $secretKey, $publicKey);
    }

    private function buildOpLogWriter(
        Container $app,
        string $deviceId,
        int $userId,
        string $secretKey,
        string $publicKey,
    ): OpLogWriter {
        return new OpLogWriter(
            clock: $app->make(HybridLogicalClock::class),
            db: $app->make(DatabaseManager::class),
            signer: $app->make(DeviceKeySigner::class),
            wallClock: $app->make(Clock::class),
            deviceId: $deviceId,
            userId: $userId,
            secretKey: $secretKey,
            publicKey: $publicKey,
            sensitiveFields: $app->make(SensitiveFieldRegistry::class),
            fieldCrypto: $app->make(OpLogFieldCrypto::class),
            keyring: $app->make(GdkKeyringService::class),
            session: $app->make(SessionFactory::class),
        );
    }

    private function registerTransportServices(): void
    {
        // Transport services (class_exists-guarded), referenced by
        // runtime-built FQCN strings so PHPStan stays clean before the
        // classes exist. This provider is the single owner.
        $transportNamespace = 'Modules\Sync\Internal\Transport\\';
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

        $this->registerWebSocketHandler();
    }

    // Bound as a factory so the container can resolve it from
    // SyncServeCommand's constructor without requiring runtime device
    // credentials at bind time — placeholder credentials reject all peers
    // until the real host resolves them (see architecture doc).
    private function registerWebSocketHandler(): void
    {
        if (! class_exists(SyncWebSocketHandler::class)) {
            return;
        }

        $this->app->bind(
            SyncWebSocketHandler::class,
            function (): SyncWebSocketHandler {
                $daemon = SyncDaemonIdentity::fromEnvironment();

                return new SyncWebSocketHandler(
                    registryService: $this->app->make(DeviceRegistryService::class),
                    signer: $this->app->make(DeviceKeySigner::class),
                    framer: new TransportFramer,
                    catchUp: $this->app->make(PeerCatchUpExchanger::class),
                    db: $this->app->make(DatabaseManager::class),
                    clock: $this->app->make(Clock::class),
                    logger: $this->app->make(LoggerInterface::class),
                    // Handed to the daemon as environment at spawn. These were
                    // hard-coded empty with a comment promising injection that
                    // was never written, so the responder answered every Noise
                    // handshake with an unusable key.
                    localStaticSecret: $daemon === null ? '' : sodium_hex2bin($daemon['secret']),
                    localStaticPublic: $daemon === null ? '' : sodium_hex2bin($daemon['public']),
                    localDeviceId: $daemon === null ? '' : $daemon['deviceId'],
                    userId: $daemon === null ? 0 : $daemon['userId'],
                    // Container-bound merge registry + FTS writer so the
                    // live sync replay path keeps the search index fresh,
                    // matching every other replay path.
                    rules: $this->app->make(MergeRulesRegistry::class),
                    searchWriter: $this->app->bound(SearchIndexWriterContract::class)
                        ? $this->app->make(SearchIndexWriterContract::class)
                        : null,
                );
            },
        );
    }

    private function registerCommandsAndMailbox(): void
    {
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
                shutdown: $this->app->make(DaemonShutdownSignal::class),
                offers: $this->app->make(PairingOfferService::class),
                offerRateLimiter: $this->app->make(PairingOfferRateLimiter::class),
                frameApplier: $this->app->make(PairingFrameApplier::class),
                peerOutbox: $this->app->make(PairingPeerOutbox::class),
                pullAuthorizer: $this->app->make(PairingPullAuthorizer::class),
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

        // A singleton because the cache IS the point: one pairing poll asks the
        // network three times, and three separate instances would each pay the
        // full browse timeout for the same answer.
        if (class_exists(CachedPeerDiscovery::class) && class_exists(MulticastMdnsQuery::class)) {
            $this->app->singleton(
                PeerDiscovery::class,
                fn () => new CachedPeerDiscovery(new MulticastMdnsQuery),
            );
        }
    }

    public function boot(Dispatcher $events): void
    {
        $this->loadModuleResources('sync');

        $this->registerCaptureListeners($events);
        $this->registerCryptoListeners($events);
        $this->registerBackfillListener($events);
        $this->registerLivewireComponents();
        $this->registerConsoleCommands();
    }

    // Each mutation event maps to one SyncCaptureListener handler. Wired
    // once the listener and event both exist — the single-owner forward-
    // registration precedent used throughout. Referenced by ::class (the
    // events are imported), still class_exists-guarded before they ship.
    private function registerCaptureListeners(Dispatcher $events): void
    {
        if (! class_exists(SyncCaptureListener::class)) {
            return;
        }

        $wirings = [
            [TransactionMutated::class, 'handle'],
            [TransactionSplitMutated::class, 'handleSplit'],
            [EnvelopeAssignmentMutated::class, 'handleEnvelopeAssignment'],
            [EnvelopeMoveMutated::class, 'handleEnvelopeMove'],
            [EnvelopeSettingMutated::class, 'handleEnvelopeSetting'],
            [GoalContributionMutated::class, 'handleGoalContribution'],
            [GoalMutated::class, 'handleGoal'],
            [EntityMutated::class, 'handleEntity'],
            [SavedReportMutated::class, 'handleSavedReport'],
            [NotificationMutated::class, 'handleNotificationMutated'],
            [NotificationPreferenceMutated::class, 'handleNotificationPreferenceMutated'],
        ];

        foreach ($wirings as [$eventClass, $method]) {
            if (class_exists($eventClass)) {
                $events->listen($eventClass, [SyncCaptureListener::class, $method]);
            }
        }
    }

    // Enabling sync is the only moment a device knows it must start
    // carrying history: everything already in its database predates the
    // op log and would otherwise never reach a peer.
    private function registerBackfillListener(Dispatcher $events): void
    {
        if (class_exists(BackfillOpLogOnSyncEnabled::class)) {
            $events->listen(DeviceSyncEnabled::class, [BackfillOpLogOnSyncEnabled::class, 'handle']);
        }
    }

    private function registerCryptoListeners(Dispatcher $events): void
    {
        // Passphrase-change GDK re-wrap. Referenced by runtime-built FQCN
        // so this provider stays PHPStan-clean before either class exists —
        // the single-owner forward-registration precedent used throughout.
        $authPassphraseChanged = 'Modules\Auth\Public\Events\AppLockPassphraseChanged';
        $rewrapListener = 'Modules\Sync\Internal\Crypto\RewrapGdkOnPassphraseChange';
        if (class_exists($authPassphraseChanged) && class_exists($rewrapListener)) {
            $events->listen($authPassphraseChanged, [$rewrapListener, 'handle']);
        }
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(LivewireManager::class)) {
            return;
        }

        /** @var LivewireManager $livewire */
        $livewire = $this->app->make(LivewireManager::class);

        // Sync health-check Livewire component. Registered by runtime FQCN
        // so PHPStan stays clean before the class exists.
        if (class_exists(SyncHealthPage::class)) {
            $livewire->component('sync.sync-health-page', SyncHealthPage::class);
        }

        // Devices & Sync settings section, pairing-flow modal, and sync-
        // status surface. Referenced by runtime-built FQCN (not `use`
        // imports / `::class`) so this provider stays PHPStan-clean before
        // they exist; they register the moment they exist on disk.
        $internal = 'Modules\Sync\Internal\Http\Livewire\\';
        $public = 'Modules\Sync\Public\Http\Livewire\\';
        $components = [
            'sync.devices-and-sync-settings-section' => $public.'DevicesAndSyncSettingsSection',
            'sync.pairing-flow-modal' => $internal.'PairingFlowModal',
            'sync.sync-status-section' => $public.'SyncStatusSection',
        ];

        foreach ($components as $alias => $componentClass) {
            if (class_exists($componentClass)) {
                $livewire->component($alias, $componentClass);
            }
        }
    }

    // Register sync:serve and relay:serve artisan daemons. Both are
    // class_exists-guarded so the provider stays clean before they ship.
    // Registered in boot() (not app/Console/Kernel.php) — per the module
    // boundary rule.
    private function registerConsoleCommands(): void
    {
        if (class_exists(SyncServeCommand::class)) {
            $this->commands([SyncServeCommand::class]);
        }

        if (class_exists(RelayServeCommand::class)) {
            $this->commands([RelayServeCommand::class]);
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
