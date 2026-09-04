<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Http\Livewire\Concerns\AnnouncesStepChanges;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\Concerns\AcceptsPairingCode;
use Modules\Mobile\Internal\Http\Livewire\Concerns\ChoosesCodeEntryArm;
use Modules\Mobile\Internal\Http\PairingEntryUrl;
use Modules\Mobile\Internal\Pairing\QrScanBridge;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Modules\Sync\Public\Enums\PairingSide;
use Modules\Sync\Public\Enums\PairingWizardStep;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Psr\Log\LoggerInterface;
use Throwable;

final class MobilePairingScan extends Component
{
    use AcceptsPairingCode;
    use AnnouncesStepChanges;
    use ChoosesCodeEntryArm;
    use HoldsFlashMessage;

    // Read once by MobileLockScreen::mount(). A public property cannot carry
    // it across navigate:false, which is a full page load.
    public const string LOCKED_IDENTITY_FLASH = 'mobile.pairing.locked_identity';

    // Put, not flashed: the lock screen render, the PIN post and the redirect
    // back are three requests, and a flash survives only the first.
    public const string TYPED_CODE_SESSION = 'mobile.pairing.typed_code';

    // `native:` plus the PHP event class the plugin fired. Plain strings
    // because the plugin lives only in mobile-app/vendor, unresolvable here.
    private const string EVENT_CODE_SCANNED = 'native:Native\Mobile\Events\Scanner\CodeScanned';

    private const string EVENT_SCANNER_CANCELLED = 'native:Native\Mobile\Events\Scanner\ScannerCancelled';

    // Stays a string on the wire: a public property is rehydrated straight from
    // the client payload with no enum coercion, so typing it would turn a
    // crafted step into a 500. currentStep() is the enum every reader uses.
    public string $step = PairingWizardStep::Scan->value;

    // Which way in the reader chose, remembered separately because `step` has
    // moved on by the time an attempt is reset: from 'confirm' it could only
    // ever read "not enter_code" and sent a word-code typist to the camera.
    public string $entryStep = PairingWizardStep::Scan->value;

    // Locked so the client cannot retarget which token the trust gate confirms.
    // Only submitCode() and the resume in mount() may set it.
    #[Locked]
    public string $pairingTokenId = '';

    // UI-only: always the responder here, and a string because a public
    // property is rehydrated straight from the client payload with no enum
    // coercion. The authoritative side is re-derived server-side from the
    // caller's own device id.
    #[Locked]
    public string $side = '';

    public string $wordCode = '';

    // Separate from $flashMessage so an invalid-code error is never
    // overwritten by (or confused with) the amber camera notice.
    public bool $cameraUnavailableNotice = false;

    // Whether this READER is mid-import, read from MobileImportIntentGate and
    // never from ?mode=import: the phone is killed and relaunched mid-flow as a
    // matter of course, and a re-entry that lost the query string is the same
    // import. #[Locked] so no client may claim to be one.
    #[Locked]
    public bool $importing = false;

    // PAIR_RESPONDER_ACCEPT addressing stashed at submitCode(), so the poll
    // can re-emit the frame idempotently while awaiting the desktop's confirm.
    #[Locked]
    public string $importResponderTokenHash = '';

    #[Locked]
    public string $importDesktopDeviceId = '';

    public bool $awaitingPeer = false;

    // #[Locked] — this is what the confirmation digest is taken from, so an
    // unlocked property would let the client decide what its own tap is bound
    // to. Every writer below is server-side.
    /** @var list<string> the 6 derived safety-number words shown on the confirm step */
    #[Locked]
    public array $safetyWords = [];

    // Shown beside the words so the user confirms WHICH two devices are
    // pairing, not just that six words match on two screens.
    public string $selfDeviceName = '';

    public string $peerDeviceName = '';

    public function mount(
        QrScanBridge $qrBridge,
        Request $request,
        CurrentUser $currentUser,
        MobileImportIntentGate $importIntent,
        PairingGateway $gateway,
        DeviceRegistryService $devices,
        UrlGenerator $urls,
        Session $session,
    ): void {
        $userId = $currentUser->user()->id;

        // Echo the param into the durable marker the moment it is observed, then
        // read the marker back: it is the only one of the two a back button or
        // a relaunch cannot lose.
        if ($request->query(PairingEntryUrl::MODE_PARAM) === PairingEntryUrl::MODE_IMPORT) {
            $importIntent->markImporting($userId);
        }

        $this->importing = $importIntent->isImporting($userId);

        // Resume the ceremony the SERVER is in: component state dies with a
        // reload or an app-lock while the pairing row carries on, and
        // restarting at the scanner rescans against an unreferenced token.
        $inFlight = $gateway->inFlightFor($userId);

        // Treating only the gone case as finished let the back button land on
        // the passed "device paired" step.
        $ceremonyFinished = $inFlight === null
            || $inFlight['state'] === PairingGateway::STATE_CONFIRMED;

        if ($ceremonyFinished && $devices->otherDeviceNames($userId) !== []) {
            $this->redirect(
                $urls->route($this->importing ? 'mobile.setup' : Destination::DataDevices->routeName()),
                navigate: false,
            );

            return;
        }

        // inFlightFor() answers for the ACCOUNT, not this device: two other
        // devices mid-handshake put the user in front of a trust gate for a
        // pairing the phone was no part of.
        $side = $inFlight === null
            ? null
            : $gateway->sideOwnedBySelf(
                $inFlight['initiator_device_id'],
                $inFlight['responder_device_id'],
                $userId,
                $session,
            );

        // The initiator is refused as firmly as null: this screen holds no
        // code-showing path to resume into.
        if ($inFlight !== null && $side === PairingSide::Responder) {
            $this->pairingTokenId = (string) $inFlight['id'];
            $this->safetyWords = $inFlight['safety_words'];
            $this->side = $side->value;
            $this->moveTo($inFlight['state'] === PairingGateway::STATE_CONFIRMED
                ? PairingWizardStep::Success
                : PairingWizardStep::Confirm);
            $this->awaitingPeer = $inFlight['responder_confirmed']
                && $inFlight['state'] !== PairingGateway::STATE_CONFIRMED;

            // Rearms the poll's responder-accept re-emit: the addressing died
            // with the old component state, not with the ceremony, so without
            // this a resumed screen polls forever without ever retrying.
            $this->importResponderTokenHash = $inFlight['token_hash'];
            $this->importDesktopDeviceId = $inFlight['peer_device_id'];

            return;
        }

        $this->enterACode($qrBridge);
        $this->restoreCodeTypedBeforeTheLock($session);
    }

    // enterACode() has just cleared wordCode and sent a camera-capable device to
    // the scanner, which is the right default for an arrival and the wrong one
    // for a return: this reader had typed a code and was interrupted.
    private function restoreCodeTypedBeforeTheLock(Session $session): void
    {
        $typed = $session->pull(self::TYPED_CODE_SESSION);

        if (! is_string($typed) || $typed === '') {
            return;
        }

        $this->wordCode = $typed;
        $this->useWordCode();
    }

    private function currentStep(): PairingWizardStep
    {
        return PairingWizardStep::tryFrom($this->step) ?? PairingWizardStep::Scan;
    }

    // The only mid-flow writer of $step, so a later branch cannot advance the
    // wizard without the page being told. Guarded on a real change because the
    // poll re-derives the same step every three seconds, and announcing that
    // would haul the reader back to the top while they read.
    private function moveTo(PairingWizardStep $step): void
    {
        if ($this->step === $step->value) {
            return;
        }

        $this->step = $step->value;
        $this->announceStepChange();
    }

    // Dependencies lead so the optional payload fields can carry defaults;
    // Livewire binds the payload by name, not position.
    #[On(self::EVENT_CODE_SCANNED)]
    public function onCodeScanned(
        CurrentUser $currentUser,
        QrScanBridge $qrBridge,
        PairingGateway $gateway,
        Session $session,
        LoggerInterface $logger,
        DeviceRegistryService $devices,
        UrlGenerator $urls,
        AppLockClientConfig $lock,
        string $data = '',
        string $format = '',
        ?string $id = null,
    ): void {
        if ($data === '') {
            return;
        }

        $this->submitCode($data, $currentUser, $qrBridge, $gateway, $session, $logger, $devices, $urls, $lock);
    }

    #[On(self::EVENT_SCANNER_CANCELLED)]
    public function onScannerCancelled(bool $cancelled = true, ?string $reason = null, ?string $id = null): void
    {
        $this->cameraDenied();
    }

    private function sendToUnlock(UrlGenerator $urls, Session $session, AppLockClientConfig $lock, int $userId): void
    {
        // With no app lock the identity is unreadable because none was ever
        // set up, and the PIN-only lock screen is a dead end. Say so instead.
        if (! $lock->isEnabled($userId)) {
            $this->flashMessage = Lang::get('mobile::pairing.errors.identity_needs_lock');

            return;
        }

        // Flashed, not set on $this: navigate:false is a full page load into
        // MobileLockScreen, which renders its own flashMessage. Setting this
        // component's property sent the user to a PIN pad with no explanation.
        $session->flash(self::LOCKED_IDENTITY_FLASH, Lang::get('mobile::pairing.errors.identity_locked'));

        // The code is already typed, and mount() clears it on the way back, so
        // the reader retyped 26 characters against a ten-minute TTL the lock
        // had just spent five minutes of.
        if ($this->wordCode !== '') {
            $session->put(self::TYPED_CODE_SESSION, $this->wordCode);
        }

        // Come back carrying the import: the bare route is the spelling a device
        // that is NOT importing gets, and the gate that guards an unfinished
        // import redirects to the other one, so returning here loses the flow.
        $session->put(
            MobileLockGateway::SESSION_INTENDED_URL,
            $this->importing
                ? PairingEntryUrl::importingFrom($urls)
                : PairingEntryUrl::bareFrom($urls),
        );

        $this->redirect($urls->route('mobile.lock'), navigate: false);
    }

    /**
     * @param  string|null  $scannedPayload  The raw decoded QR string from
     *                                       the camera path, or null when
     *                                       called from the enter_code
     *                                       form (reads $this->wordCode).
     */
    public function submitCode(
        ?string $scannedPayload,
        CurrentUser $currentUser,
        QrScanBridge $qrBridge,
        PairingGateway $gateway,
        Session $session,
        LoggerInterface $logger,
        DeviceRegistryService $devices,
        UrlGenerator $urls,
        AppLockClientConfig $lock,
    ): void {
        $userId = $currentUser->user()->id;

        $identity = $this->initiatorIdentity($scannedPayload, $qrBridge, $gateway);

        // false, never null: a typed code outside import mode legitimately
        // carries no identity, while false means one was named and could not
        // be read — and the reason is already on screen.
        if ($identity === false) {
            return;
        }

        if ($identity !== null) {
            $this->adoptInitiator($gateway, $identity, $userId);
        }

        if (! $this->ownIdentityReady($gateway, $session, $userId)) {
            $this->sendToUnlock($urls, $session, $lock, $userId);

            return;
        }

        $result = $scannedPayload !== null
            ? $qrBridge->accept($scannedPayload, $userId, $session)
            : $gateway->acceptWordCode($this->wordCode, $userId, $session);

        if ($result === null) {
            $this->reportRejectedCode($gateway, $urls, $session, $lock, $userId);

            return;
        }

        $this->pairingTokenId = $result['pairingTokenId'];
        $this->side = PairingSide::Responder->value;
        $this->safetyWords = $result['safetyWords'];
        $this->hydrateDeviceNames($gateway, $devices, $userId);
        $this->flashMessage = '';
        $this->cameraUnavailableNotice = false;
        $this->moveTo(PairingWizardStep::Confirm);

        // Best-effort: a relay failure never dead-ends the confirm step
        // already rendered above; the desktop's poll simply does not advance.
        if ($identity !== null) {
            $this->announceResponderAccept($gateway, $logger, $identity, $userId, $session);
        }
    }

    // The side that confirms FIRST never sees CONFIRMED back from its own
    // confirmMatch() — it learns of the completed both-confirm here. Guarded
    // to the confirm -> success transition so the migration runs once.
    public function checkPairingState(
        CurrentUser $currentUser,
        PairingGateway $gateway,
        Session $session,
        EncryptionMigrationService $migrationService,
        MobileImportIntentGate $importIntent,
        DatabaseManager $db,
        LoggerInterface $logger,
    ): void {
        if ($this->pairingTokenId === '') {
            return;
        }

        $userId = $currentUser->user()->id;

        // Before tokenState() below: draining drives the local row to
        // CONFIRMED, which is what shouldDeferSelfMint() then reads.
        try {
            $gateway->drainPairingFrames($userId, $session);
        } catch (Throwable $e) {
            $logger->warning('MobilePairingScan: cross-device relay drain failed during poll.', [
                'user_id' => $userId,
                'exception' => $e::class,
            ]);
        }

        $state = $gateway->tokenState((int) $this->pairingTokenId, $userId);

        // The initial responder-accept is single-shot, so a lost delivery
        // means the desktop never binds. Re-emitting idempotently on each
        // poll lets a transient relay failure self-heal.
        if ($state !== PairingGateway::STATE_CONFIRMED
            && $this->currentStep() === PairingWizardStep::Confirm
            && $this->importResponderTokenHash !== ''
            && $this->importDesktopDeviceId !== ''
        ) {
            try {
                $gateway->sendResponderAccept($userId, $this->importResponderTokenHash, $this->importDesktopDeviceId, $session);
                $this->flashMessage = '';
            } catch (Throwable $e) {
                // The courier throws only when no road home is open at all.
                // Swallowing that left the user watching a spinner forever.
                $this->flashMessage = Lang::get($this->undeliveredAcceptKey($gateway, $this->importResponderTokenHash, $this->importDesktopDeviceId));

                $logger->warning('MobilePairingScan: cross-device PAIR_RESPONDER_ACCEPT relay re-emit failed during poll.', [
                    'user_id' => $userId,
                    'exception' => $e::class,
                ]);
            }
        }

        // Re-emitted for the same reason the accept above is: a confirm the peer
        // deferred, or lost in flight, is otherwise never sent again and the
        // ceremony completes on one device only. Gated on the row's own stamp,
        // not on a flag a refused tap also sets.
        if ($state !== PairingGateway::STATE_CONFIRMED
            && $gateway->hasConfirmedLocally((int) $this->pairingTokenId, $userId, $session)) {
            $this->sendConfirmToPeer($gateway, $userId, $db, $session, $logger);
        }

        // Expired, refused or cancelled on the other device: without this the
        // poll spins forever on a handshake that already ended out of sight.
        if ($state === null || $state === PairingGateway::STATE_EXPIRED) {
            $this->resetPairingAttempt();
            $this->awaitingPeer = false;
            $this->flashMessage = Lang::get('mobile::pairing.errors.invalid_code');

            return;
        }

        if ($state === PairingGateway::STATE_CONFIRMED && $this->currentStep() !== PairingWizardStep::Success) {
            $this->moveTo(PairingWizardStep::Success);

            $this->settleConfirmedPairing($currentUser, $gateway, $session, $migrationService, $importIntent, $db, $logger);
        }
    }

    // The confirm arrives two ways — this device's own tap, and the poll that
    // learns the peer completed first — and both have to settle the same, or a
    // ceremony finished on the other screen leaves this device's epochs where
    // no peer can reach them.
    private function settleConfirmedPairing(
        CurrentUser $currentUser,
        PairingGateway $gateway,
        Session $session,
        EncryptionMigrationService $migrationService,
        MobileImportIntentGate $importIntent,
        DatabaseManager $db,
        LoggerInterface $logger,
    ): void {
        $userId = $currentUser->user()->id;

        if (! $this->shouldDeferSelfMint($userId, $importIntent, $db)) {
            try {
                $migrationService->migrate($currentUser->user(), $session);
            } catch (Throwable) {
                // Best-effort: the pairing is already recorded, and the
                // encryption row keeps rendering its own state until a
                // later pass succeeds.
            }
        }

        // The phone sends too. A device that only ever received left the
        // desktop settling the blind-index tie over a keyed-rows flag it
        // was never sent, and a phone holding the ledger kept a key no
        // other device could learn.
        $this->fanOutToConfirmedPeers($gateway, $db, $logger, $userId, $session);
    }

    // Every confirmed peer, asked of the permanent device_registry rather than
    // this transient token: prune() drops that row on the next issue(), so
    // resolving the recipient from it delivers nothing once the ceremony
    // outlives its own token.
    private function fanOutToConfirmedPeers(
        PairingGateway $gateway,
        DatabaseManager $db,
        LoggerInterface $logger,
        int $userId,
        Session $session,
    ): void {
        $recipients = $db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 0)
            ->whereNotNull('confirmed_at')
            ->pluck('id');

        foreach ($recipients as $deviceRegistryId) {
            if (! is_numeric($deviceRegistryId)) {
                continue;
            }

            try {
                $gateway->deliverAllEpochsToDevice($userId, (int) $deviceRegistryId, $session);
            } catch (Throwable $e) {
                // Best-effort: the pairing is already recorded, and the wraps
                // are re-enqueued by the next confirmed pairing rather than
                // undoing a ceremony that succeeded.
                $logger->warning('MobilePairingScan: GDK epoch fan-out to a confirmed device failed.', [
                    'user_id' => $userId,
                    'device_registry_id' => (int) $deviceRegistryId,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    private function resetPairingAttempt(): void
    {
        // Keep the entry method the reader chose: someone typing a word code
        // lands back on the keypad, not thrown to the camera.
        $this->moveTo($this->entryArm());
        $this->pairingTokenId = '';
        $this->safetyWords = [];
        $this->importResponderTokenHash = '';
        $this->importDesktopDeviceId = '';
    }

    // The same PairingGateway::confirm() trust gate the desktop uses — this
    // mobile entry point introduces no new admission path.
    public function confirmMatch(
        CurrentUser $currentUser,
        PairingGateway $gateway,
        Session $session,
        EncryptionMigrationService $migrationService,
        MobileImportIntentGate $importIntent,
        DatabaseManager $db,
        LoggerInterface $logger,
        UrlGenerator $urls,
        AppLockClientConfig $lock,
    ): void {
        if ($this->pairingTokenId === '') {
            return;
        }

        $userId = $currentUser->user()->id;

        // The gateway derives the confirming side from this device id, never
        // from client state.
        $deviceId = $gateway->currentDeviceId($userId, $session);
        if ($deviceId === null) {
            $this->sendToUnlock($urls, $session, $lock, $userId);

            return;
        }

        // Bound to the words on screen rather than to whatever the row says
        // now, so a responder that rebinds cannot inherit this tap.
        $state = $gateway->confirm(
            (int) $this->pairingTokenId,
            $userId,
            $deviceId,
            $gateway->safetyDigestOf($this->safetyWords),
        );

        // Refused: the keys behind those words are not the ones the row binds
        // any more. Silence here reads as "waiting for the other device", which
        // is how a responder that rebinds stalls a ceremony unseen.
        if ($state === null) {
            $this->awaitingPeer = false;
            $this->safetyWords = $gateway->safetyWordsFor((int) $this->pairingTokenId, $userId);
            $this->flashMessage = Lang::get('mobile::pairing.errors.safety_number_changed');

            return;
        }

        // Safe regardless of $state: the frame is only consumable once the
        // peer's own local side independently confirms too.
        $this->sendConfirmToPeer($gateway, $userId, $db, $session, $logger);

        $this->awaitingPeer = $state !== PairingGateway::STATE_CONFIRMED;

        if ($state === PairingGateway::STATE_CONFIRMED) {
            $this->moveTo(PairingWizardStep::Success);

            $this->settleConfirmedPairing($currentUser, $gateway, $session, $migrationService, $importIntent, $db, $logger);
        }
    }

    private function sendConfirmToPeer(
        PairingGateway $gateway,
        int $userId,
        DatabaseManager $db,
        Session $session,
        LoggerInterface $logger,
    ): void {
        // Scoped as well as #[Locked]: this runs whatever confirm() returned,
        // so the id's provenance is the only thing keeping the read in-account.
        $initiatorDeviceId = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
            ->where('user_id', $userId)
            ->value('initiator_device_id');

        if (! is_string($initiatorDeviceId) || $initiatorDeviceId === '') {
            return;
        }

        try {
            $gateway->sendConfirm($userId, (int) $this->pairingTokenId, $initiatorDeviceId, $session);
        } catch (Throwable $e) {
            $logger->warning('MobilePairingScan: cross-device PAIR_CONFIRM relay delivery failed.', [
                'pairing_token_id' => $this->pairingTokenId,
                'exception' => $e::class,
            ]);
        }
    }

    // A confirmed peer alone suffices: this screen only plays responder, so
    // reaching it means joining a group whose epochs exist, and a rival
    // epoch 1 made the peer's own epoch 1 look like a duplicate on arrival.
    private function shouldDeferSelfMint(int $userId, MobileImportIntentGate $importIntent, DatabaseManager $db): bool
    {
        $currentEpoch = $db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->value('current_epoch');

        if ($currentEpoch !== null) {
            return false;
        }

        return $importIntent->isImporting($userId) || $this->hasConfirmedPeer($userId, $db);
    }

    private function hasConfirmedPeer(int $userId, DatabaseManager $db): bool
    {
        return $db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 0)
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    // The way out of a first-run choice. layouts.lock draws no navigation and
    // MobileEnsureImportCompleted returns every gated route to this screen
    // while the marker stands, so without this the only exit is a reinstall.
    public function abandonImport(
        CurrentUser $currentUser,
        PairingGateway $gateway,
        MobileImportIntentGate $importIntent,
        Dispatcher $events,
        UrlGenerator $urls,
    ): void {
        $userId = $currentUser->user()->id;

        if ($this->pairingTokenId !== '') {
            $gateway->expire((int) $this->pairingTokenId, $userId);
        }

        // Retiring the marker is the gate's OWN convergence move, so the exit
        // satisfies the invariant rather than punching a hole in the exempt
        // list: past here this device is indistinguishable from one that never
        // chose to import.
        $importIntent->clearImporting($userId);

        // The import path signs up with seedsStarterData: false, because those
        // rules were to arrive over sync. Nothing will now send them, and this
        // is the same re-dispatch InstallCommand uses to heal a missing seed.
        $events->dispatch(new UserInstalled($userId));

        $this->redirect($urls->route(Destination::Dashboard->routeName()), navigate: false);
    }

    public function cancelPairing(
        CurrentUser $currentUser,
        PairingGateway $gateway,
        UrlGenerator $urls,
    ): void {
        if ($this->pairingTokenId !== '') {
            $gateway->expire((int) $this->pairingTokenId, $currentUser->user()->id);
        }

        // Cancelling mid-onboarding returns to the wizard: Devices & Sync
        // dropped an unfinished setup into settings with no route back.
        $this->redirect(
            $urls->route($this->importing ? 'mobile.import' : Destination::DataDevices->routeName()),
            navigate: false,
        );
    }

    // Import mode advances into the blocking initial-sync gate: the phone
    // must pull its history before it can show a populated dashboard.
    public function finishPairing(UrlGenerator $urls): void
    {
        $route = $this->importing ? 'mobile.setup' : Destination::DataDevices->routeName();

        $this->redirect($urls->route($route), navigate: false);
    }

    public function render(ViewFactory $views, PairingGateway $gateway): View
    {
        // Read here rather than at submit: reach() is a config lookup that
        // touches no network, so the screen can say a typed code has nothing to
        // find BEFORE thirty-two base-32 characters of one are typed into it.
        $typedCodeCanFindPeer = $gateway->lanDiscoveryReach()->silenceMeansNoPeers();

        // Under its own name, not $step: view data cannot shadow a public
        // property, and the view needs the resolved enum rather than the raw
        // string the client last put on the wire.
        $view = $views->make('mobile::livewire.mobile-pairing-scan', [
            'wizardStep' => $this->currentStep(),
            'typedCodeCanFindPeer' => $typedCodeCanFindPeer,
            'entryNotice' => $this->entryArmNotice($typedCodeCanFindPeer),
        ]);

        $view->extends('layouts.lock', ['title' => Lang::get('mobile::pairing.page_title').' · Beatrax']);

        return $view;
    }
}
