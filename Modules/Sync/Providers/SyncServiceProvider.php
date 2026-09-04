<?php

declare(strict_types=1);

namespace Modules\Sync\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Auth\Public\Events\AppLockUnlocked;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
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
use Modules\Sync\Internal\Crypto\GdkRewrapContract;
use Modules\Sync\Internal\Crypto\GdkRewrapService;
use Modules\Sync\Internal\Crypto\LibsodiumPrimitives;
use Modules\Sync\Internal\Crypto\LocallyKeyedRowsProbe;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\RewrapGdkOnPassphraseChange;
use Modules\Sync\Internal\Crypto\SodiumPrimitives;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Http\Livewire\SyncHealthPage;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceNameDetector;
use Modules\Sync\Internal\Listeners\BackfillOpLogOnSyncEnabled;
use Modules\Sync\Internal\Listeners\HoldPairingCeremonyOpenOnUnlock;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\Merge\Strategies\LwwPerFieldStrategy;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Internal\OpLog\DeferredOpCaptures;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpLogWriterFactory;
use Modules\Sync\Internal\Pairing\Bip39WordList;
use Modules\Sync\Internal\Pairing\HeldPeerConfirm;
use Modules\Sync\Internal\Pairing\LanPairingFrameCourier;
use Modules\Sync\Internal\Pairing\LanPairingFramePuller;
use Modules\Sync\Internal\Pairing\LocalConfirmRecorder;
use Modules\Sync\Internal\Pairing\PairedDeviceAdmitter;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Pairing\PairingFrameCourier;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingOfferService;
use Modules\Sync\Internal\Pairing\PairingPeerOutbox;
use Modules\Sync\Internal\Pairing\PairingPullAuthorizer;
use Modules\Sync\Internal\Pairing\PairingStateMachine;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\PeerConfirmVerifier;
use Modules\Sync\Internal\Pairing\PendingPairingCourier;
use Modules\Sync\Internal\Pairing\QrPayloadBuilder;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\DaemonTicker;
use Modules\Sync\Internal\Transport\DaemonTimer;
use Modules\Sync\Internal\Transport\Discovery\BonjourBridgeQuery;
use Modules\Sync\Internal\Transport\Discovery\CachedPeerDiscovery;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\Discovery\MulticastMdnsQuery;
use Modules\Sync\Internal\Transport\Discovery\NativeBridge;
use Modules\Sync\Internal\Transport\Discovery\NativePhpBridge;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\SyncSession;
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
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;
use Modules\Sync\Public\Http\Livewire\SyncStatusSection;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\EncryptionMigrationSupport;
use Modules\Sync\Public\Services\ImportSyncCapture;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Public\Services\SyncDaemonIdentity;
use Modules\Sync\Public\Services\SyncStatusService;
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
        $this->app->singleton(OpLogFieldCrypto::class);
        $this->app->singleton(GdkKeyringService::class);
        $this->app->singleton(SensitiveColumnCodec::class);

        // The minimal Public wrapper EncryptionMigrationService consumes
        // instead of reaching into Modules\Sync\Internal\Crypto directly. NOT a
        // singleton — it caches one primed epoch's raw key material for the
        // duration of a single migration pass.
        $this->app->bind(EncryptionMigrationSupport::class);

        // Device-removal rotation orchestration. The ctor takes no Session —
        // every method that needs one takes it as a per-call parameter, so this
        // singleton can never capture a stale session.

        // Behind its own contract, because the passphrase-change listener wired
        // in boot() is the only caller and it names the contract.
        $this->app->bind(GdkRewrapContract::class, GdkRewrapService::class);

        $this->app->singleton(LocallyKeyedRowsProbe::class);
    }

    private function registerReplayer(): void
    {
        // Production OpLogReplayer fed the confirmed-only device-key map.
        // Tests inject their own throwaway map by constructing OpLogReplayer
        // directly.
        $this->app->bind(
            OpLogReplayer::class,
            function (): OpLogReplayer {
                $deviceKeys = $this->app->make(DeviceRegistryService::class)
                    ->deviceKeys($this->currentUserId());

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
        // Not a singleton: it holds no state, and caching one instance froze
        // whichever AppLockKeyService was bound at first resolve. A daemon
        // handoff now reads the identity during DeviceSyncEnabled, which is
        // early enough for that freeze to outlive a later rebind.
        $this->app->bind(DeviceIdentityLoader::class);

        $this->app->singleton(DeviceNameDetector::class);
        $this->app->singleton(DeviceRegistryService::class);

        // SafetyNumberDeriver needs the BIP39 word list as its sole constructor
        // arg; bind it explicitly so callers can resolve it without re-passing
        // the list.
        $this->app->singleton(
            SafetyNumberDeriver::class,
            fn (): SafetyNumberDeriver => new SafetyNumberDeriver(Bip39WordList::WORDS),
        );
    }

    private function registerPairingServices(): void
    {
        $this->app->singleton(PairingTokenService::class);
        $this->app->singleton(PairingStateMachine::class);
        $this->app->singleton(WordCodeEncoder::class);
        $this->app->singleton(QrPayloadBuilder::class);

        // Collaborators PairingTokenService delegates to: device-registry
        // admission, the local human's tap, and the relayed PAIR_CONFIRM
        // anti-forgery gate. Each owns the crypto deps it uses so the host
        // stays a thin orchestrator.
        $this->app->singleton(PairedDeviceAdmitter::class);
        $this->app->singleton(LocalConfirmRecorder::class);
        $this->app->singleton(PeerConfirmVerifier::class);
        $this->app->singleton(HeldPeerConfirm::class);

        // Applies an inbound frame whichever transport carried it, so the relay
        // drain and the LAN route cannot drift apart.
        $this->app->singleton(PairingFrameApplier::class);
        $this->app->singleton(LanPairingFrameCourier::class);
        $this->app->singleton(LanPairingFramePuller::class);
        $this->app->singleton(PairingPeerOutbox::class);
        $this->app->singleton(PairingPullAuthorizer::class);

        // Relay courier for the cross-device both-confirm handshake.
        // PairingFrame itself is static-only and needs no binding.
        $this->app->singleton(PairingFrameCourier::class);

        // What a pairing screen hands off rather than doing itself: the frames
        // and epochs the peer is owed, and the line each refusal renders as.

        // Redelivery with no pairing screen open anywhere. Bound, not a
        // singleton: it is resolved from a request tail and from a daemon
        // timer, and each of those wants the collaborators of its own process.
        $this->app->bind(PendingPairingCourier::class);

        // Singleton because it carries a memo of how full the queue is: record()
        // is called on every mutation of a locked device, and a COUNT per write
        // would make the queue cost grow with the length of the lock.
        $this->app->singleton(DeferredOpCaptures::class);

        // OpLogWriter takes four runtime primitives no autowiring can supply,
        // so EVERY resolution threw "Unresolvable dependency". SyncCaptureListener
        // swallowed that: nothing was ever captured, and a paired device
        // received an empty database.
        $this->app->bind(
            OpLogWriter::class,
            function (Container $app, array $parameters): OpLogWriter {
                /** @var array<string, mixed> $parameters */
                return new OpLogWriterFactory($app)->make($parameters);
            },
        );
    }

    private function registerTransportServices(): void
    {
        // Noise state-machine classes are deliberately absent: they hold mutable
        // crypto state and must be constructed fresh per call, so callers build
        // them directly rather than resolving them here.
        $this->app->singleton(SyncStatusService::class);
        $this->app->singleton(SyncSession::class);
        $this->app->singleton(PeerCatchUpExchanger::class);
        $this->app->singleton(RelayClient::class);
        $this->app->singleton(RelayConfig::class);

        $this->registerWebSocketHandler();
    }

    // Bound as a factory so the container can resolve it from
    // SyncServeCommand's constructor without requiring runtime device
    // credentials at bind time — placeholder credentials reject all peers
    // until the real host resolves them (see architecture doc).
    private function registerWebSocketHandler(): void
    {
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
        $this->app->singleton(RelayServeCommand::class);

        // Bound rather than newed inline so the daemon's periodic work can be
        // driven by a test with no event loop under it.
        $this->app->singleton(DaemonTimer::class, DaemonTicker::class);

        // The handler is a factory, not an instance: registering a console
        // command resolves it, and building it reaches the encrypted search
        // writer — which made every artisan call need an application key,
        // including the `key:generate` that mints one.
        $this->app->singleton(SyncServeCommand::class, fn (): SyncServeCommand => new SyncServeCommand(
            logger: $this->app->make(LoggerInterface::class),
            handler: fn () => $this->app->make(SyncWebSocketHandler::class),
            advertiser: $this->app->make(MdnsAdvertiser::class),
            shutdown: $this->app->make(DaemonShutdownSignal::class),
            offers: $this->app->make(PairingOfferService::class),
            offerRateLimiter: $this->app->make(PairingOfferRateLimiter::class),
            frameApplier: $this->app->make(PairingFrameApplier::class),
            peerOutbox: $this->app->make(PairingPeerOutbox::class),
            pullAuthorizer: $this->app->make(PairingPullAuthorizer::class),
            pendingCourier: fn () => $this->app->make(PendingPairingCourier::class),
            ticker: $this->app->make(DaemonTimer::class),
        ));

        $this->app->singleton(RelayMailbox::class);
        $this->app->singleton(MdnsAdvertiser::class);

        // A singleton because the cache IS the point: one pairing poll asks the
        // network three times, and three separate instances would each pay the
        // full browse timeout for the same answer.
        $this->app->singleton(
            PeerDiscovery::class,
            fn (): CachedPeerDiscovery => new CachedPeerDiscovery(self::discovery(
                $this->app->make(NativeBridge::class),
                $this->app->make(Repository::class),
            )),
        );

        $this->app->bind(NativeBridge::class, NativePhpBridge::class);
    }

    // Whichever road can actually put the question on the network: the shell's
    // Bonjour browser where it exists, the only one iOS does not drop; the raw
    // multicast query everywhere else. When NEITHER can look that query is still
    // bound, so the caller gets Unsupported rather than a silent "no peers".
    private static function discovery(NativeBridge $bridge, Repository $config): PeerDiscovery
    {
        $bonjour = new BonjourBridgeQuery($bridge);

        return $bonjour->reach()->silenceMeansNoPeers() ? $bonjour : new MulticastMdnsQuery(config: $config);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadModuleResources('sync');

        $this->registerCaptureListeners($events);
        $this->registerCryptoListeners($events);
        $this->registerBackfillListener($events);
        $this->registerPairingListeners($events);
        $this->registerLivewireComponents($livewire);
        $this->registerConsoleCommands();
    }

    // Each mutation event maps to one SyncCaptureListener handler, and the
    // listener is the single owner of that mapping.
    private function registerCaptureListeners(Dispatcher $events): void
    {
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
            $events->listen($eventClass, [SyncCaptureListener::class, $method]);
        }
    }

    // Enabling sync is the only moment a device knows it must start
    // carrying history: everything already in its database predates the
    // op log and would otherwise never reach a peer.
    private function registerBackfillListener(Dispatcher $events): void
    {
        $events->listen(DeviceSyncEnabled::class, [BackfillOpLogOnSyncEnabled::class, 'handle']);
    }

    private function registerCryptoListeners(Dispatcher $events): void
    {
        $events->listen(AppLockPassphraseChanged::class, [RewrapGdkOnPassphraseChange::class, 'handle']);
    }

    // The unlock itself, not a screen somebody may never open: a ceremony that
    // lapsed behind the lock is revived the moment the lock lifts.
    private function registerPairingListeners(Dispatcher $events): void
    {
        $events->listen(AppLockUnlocked::class, [HoldPairingCeremonyOpenOnUnlock::class, 'handle']);
    }

    private function registerLivewireComponents(LivewireManager $livewire): void
    {
        $livewire->component('sync.sync-health-page', SyncHealthPage::class);
        $livewire->component('sync.devices-and-sync-settings-section', DevicesAndSyncSettingsSection::class);
        $livewire->component('sync.pairing-flow-modal', PairingFlowModal::class);
        $livewire->component('sync.sync-status-section', SyncStatusSection::class);
    }

    // The sync:serve and relay:serve daemons register from boot() rather than
    // app/Console/Kernel.php, per the module boundary rule.
    private function registerConsoleCommands(): void
    {
        $this->commands([SyncServeCommand::class, RelayServeCommand::class]);
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
