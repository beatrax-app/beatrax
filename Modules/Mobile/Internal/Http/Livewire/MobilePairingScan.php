<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

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
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\Concerns\AcceptsPairingCode;
use Modules\Mobile\Internal\Pairing\QrScanBridge;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Psr\Log\LoggerInterface;
use Throwable;

final class MobilePairingScan extends Component
{
    use AcceptsPairingCode;
    use HoldsFlashMessage;

    // Read once by MobileLockScreen::mount(). A public property cannot carry
    // it across navigate:false, which is a full page load.
    public const LOCKED_IDENTITY_FLASH = 'mobile.pairing.locked_identity';

    // `native:` plus the PHP event class the plugin fired. Plain strings
    // because the plugin lives only in mobile-app/vendor, unresolvable here.
    private const EVENT_CODE_SCANNED = 'native:Native\Mobile\Events\Scanner\CodeScanned';

    private const EVENT_SCANNER_CANCELLED = 'native:Native\Mobile\Events\Scanner\ScannerCancelled';

    public string $step = 'scan';

    // Which way in the reader chose, remembered separately because `step` has
    // moved on by the time an attempt is reset: from 'confirm' it could only
    // ever read "not enter_code" and sent a word-code typist to the camera.
    public string $entryStep = 'scan';

    // Locked so the client cannot retarget which token the trust gate confirms.
    #[Locked]
    public string $pairingTokenId = '';

    // UI-only: always 'responder' here. The authoritative side is re-derived
    // server-side from the caller's own device id.
    #[Locked]
    public string $side = '';

    public string $wordCode = '';

    // Separate from $flashMessage so an invalid-code error is never
    // overwritten by (or confused with) the amber camera notice.
    public bool $cameraUnavailableNotice = false;

    // Set from ?mode=import at mount(). UI-only: the self-mint decision reads
    // MobileImportIntentGate instead, which survives a re-entry without it.
    #[Locked]
    public bool $importMode = false;

    // PAIR_RESPONDER_ACCEPT addressing stashed at submitCode(), so the poll
    // can re-emit the frame idempotently while awaiting the desktop's confirm.
    #[Locked]
    public string $importResponderTokenHash = '';

    #[Locked]
    public string $importDesktopDeviceId = '';

    public bool $awaitingPeer = false;

    /** @var list<string> the 6 derived safety-number words shown on the confirm step */
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

        $this->importMode = $request->query('mode') === 'import';

        // Echo the param into the durable marker the moment it is observed:
        // a re-entry without it (back button, relaunch) must still defer.
        if ($this->importMode) {
            $importIntent->markImporting($userId);
        }

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
                $urls->route($importIntent->isImporting($userId) ? 'mobile.setup' : 'data-devices.index'),
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

        // 'initiator' is refused as firmly as null: this screen holds no
        // code-showing path to resume into.
        if ($inFlight !== null && $side === 'responder') {
            $this->pairingTokenId = (string) $inFlight['id'];
            $this->safetyWords = $inFlight['safety_words'];
            $this->side = $side;
            $this->step = $inFlight['state'] === PairingGateway::STATE_CONFIRMED ? 'success' : 'confirm';

            // Rearms the poll's responder-accept re-emit: the addressing died
            // with the old component state, not with the ceremony, so without
            // this a resumed screen polls forever without ever retrying.
            $this->importResponderTokenHash = $inFlight['token_hash'];
            $this->importDesktopDeviceId = $inFlight['peer_device_id'];

            return;
        }

        $this->enterACode($qrBridge);
    }

    public function enterACode(QrScanBridge $qrBridge): void
    {
        $this->wordCode = '';
        $this->flashMessage = '';
        $this->side = 'responder';

        if ($qrBridge->isAvailable()) {
            $this->step = 'scan';
            $this->entryStep = 'scan';
            $this->cameraUnavailableNotice = false;

            return;
        }

        $this->step = 'enter_code';
        $this->entryStep = 'enter_code';
        $this->cameraUnavailableNotice = true;
    }

    // The deliberate "I'd rather type it" choice, unlike cameraDenied()'s
    // forced fallback: same step, no amber notice, because nothing failed.
    public function useWordCode(): void
    {
        $this->flashMessage = '';
        $this->cameraUnavailableNotice = false;
        $this->step = 'enter_code';
        $this->entryStep = 'enter_code';
    }

    // Driven from the view rather than mount() so the component is already
    // live when the camera opens: a scan that completes before Livewire is
    // listening would drop its CodeScanned event.
    public function startScan(QrScanBridge $qrBridge): void
    {
        $this->flashMessage = '';

        if (! $qrBridge->open(Lang::get('mobile::pairing.scan_prompt'))) {
            $this->cameraDenied();
        }
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

    // A runtime permission-denied/no-camera signal, distinct from
    // QrScanBridge::isAvailable(): the plugin can resolve while the OS
    // permission is still denied.
    public function cameraDenied(): void
    {
        $this->cameraUnavailableNotice = true;
        $this->step = 'enter_code';
        $this->entryStep = 'enter_code';
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

        // Come back to the arm they were on: the dashboard default dropped
        // mode=import, returning an importing device to a pairing screen
        // offering the arm import deliberately hides.
        $session->put(
            MobileLockGateway::SESSION_INTENDED_URL,
            $this->importMode
                ? $urls->route('mobile.pair').'?mode=import'
                : $urls->route('mobile.pair'),
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
        $this->side = 'responder';
        $this->safetyWords = $result['safetyWords'];
        $this->hydrateDeviceNames($gateway, $devices, $userId);
        $this->flashMessage = '';
        $this->cameraUnavailableNotice = false;
        $this->step = 'confirm';

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
            $gateway->drainPairingFrames($userId);
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
            && $this->step === 'confirm'
            && $this->importResponderTokenHash !== ''
            && $this->importDesktopDeviceId !== ''
        ) {
            try {
                $gateway->sendResponderAccept($userId, $this->importResponderTokenHash, $this->importDesktopDeviceId, $session);
                $this->flashMessage = '';
            } catch (Throwable $e) {
                // The courier throws when no relay is configured. Swallowing
                // that left the user watching a spinner forever.
                $this->flashMessage = Lang::get('mobile::pairing.errors.relay_unreachable');

                $logger->warning('MobilePairingScan: cross-device PAIR_RESPONDER_ACCEPT relay re-emit failed during poll.', [
                    'user_id' => $userId,
                    'exception' => $e::class,
                ]);
            }
        }

        // Re-emitted for the same reason the accept above is: a confirm the peer
        // deferred, or lost in flight, is otherwise never sent again and the
        // ceremony completes on one device only. Gated on having actually
        // tapped — sending it sooner would assert a confirmation never given.
        if ($this->awaitingPeer && $state !== PairingGateway::STATE_CONFIRMED) {
            $this->sendConfirmOverRelay($gateway, $userId, $db, $session, $logger);
        }

        // Expired, refused or cancelled on the other device: without this the
        // poll spins forever on a handshake that already ended out of sight.
        if ($state === null || $state === PairingGateway::STATE_EXPIRED) {
            $this->resetPairingAttempt();
            $this->awaitingPeer = false;
            $this->flashMessage = Lang::get('mobile::pairing.errors.invalid_code');

            return;
        }

        if ($state === PairingGateway::STATE_CONFIRMED && $this->step !== 'success') {
            $this->step = 'success';

            if (! $this->shouldDeferSelfMint($userId, $importIntent, $db)) {
                try {
                    $migrationService->migrate($currentUser->user(), $session);
                } catch (Throwable) {
                    // A migration failure never undoes the finished pairing.
                }
            }
        }
    }

    private function resetPairingAttempt(): void
    {
        // Keep the entry method the reader chose: someone typing a word code
        // lands back on the keypad, not thrown to the camera.
        $this->step = $this->entryStep;
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

        // Bound to the words on screen, not to whatever the row says now.
        $state = $gateway->confirm(
            (int) $this->pairingTokenId,
            $userId,
            $deviceId,
            $this->safetyWords === [] ? '' : hash('sha256', implode('|', $this->safetyWords)),
        );

        // Safe regardless of $state: the frame is only consumable once the
        // peer's own local side independently confirms too.
        $this->sendConfirmOverRelay($gateway, $userId, $db, $session, $logger);

        if ($state === PairingGateway::STATE_CONFIRMED) {
            $this->awaitingPeer = false;
            $this->step = 'success';

            if (! $this->shouldDeferSelfMint($userId, $importIntent, $db)) {
                try {
                    $migrationService->migrate($currentUser->user(), $session);
                } catch (Throwable) {
                    // A migration failure never undoes the finished pairing.
                }
            }

            return;
        }

        $this->awaitingPeer = true;
    }

    private function sendConfirmOverRelay(
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

    // Deliberately NOT cancelPairing(): nothing has been submitted yet, so
    // there is no token to expire and no reason to leave the ceremony.
    public function backToScan(QrScanBridge $qrBridge): void
    {
        $this->wordCode = '';
        $this->flashMessage = '';
        $this->enterACode($qrBridge);
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
            $urls->route($this->importMode ? 'mobile.import' : 'data-devices.index'),
            navigate: false,
        );
    }

    // Import mode advances into the blocking initial-sync gate: the phone
    // must pull its history before it can show a populated dashboard.
    public function finishPairing(UrlGenerator $urls): void
    {
        $route = $this->importMode ? 'mobile.setup' : 'data-devices.index';

        $this->redirect($urls->route($route), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.mobile-pairing-scan');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.lock', ['title' => Lang::get('mobile::pairing.page_title').' · Beatrax']);

        return $view;
    }
}
