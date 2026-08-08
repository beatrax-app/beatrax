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
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Pairing\QrScanBridge;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Modules\Sync\Public\Services\PairingGateway;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class MobilePairingScan extends Component
{
    // The scanner plugin reaches the WebView by dispatching a Livewire
    // event named `native:` plus the PHP event class it fired. Spelled as
    // plain strings because the plugin lives only in mobile-app/vendor and
    // is unresolvable from the repo-root toolchain.
    private const EVENT_CODE_SCANNED = 'native:Native\Mobile\Events\Scanner\CodeScanned';

    private const EVENT_SCANNER_CANCELLED = 'native:Native\Mobile\Events\Scanner\ScannerCancelled';

    public string $step = 'scan';

    // The trust gate must not let the client retarget which token is
    // being confirmed - only server code (submitCode()) may set this;
    // Livewire rejects any client-side mutation.
    #[Locked]
    public string $pairingTokenId = '';

    // Always 'responder' on this mobile entry point (it only ever
    // scans/types the OTHER device's code). The authoritative side is
    // re-derived server-side from the caller's own device id; this
    // property is UI-only and locked as defense-in-depth.
    #[Locked]
    public string $side = '';

    public string $wordCode = '';

    public string $flashMessage = '';

    // True when the camera-unavailable/permission-denied amber notice
    // should render on the enter_code step - kept separate from
    // $flashMessage so an invalid-code error never gets overwritten by
    // (or confused with) the notice's different visual tone.
    public bool $cameraUnavailableNotice = false;

    // True when this pairing attempt reached the route via the "Import
    // from another device" fresh-device bootstrap CTA. See
    // .docs/features/mobile/architecture.md for the self-mint deferral
    // and cross-device handshake this toggles.
    #[Locked]
    public bool $importMode = false;

    // The cross-device PAIR_RESPONDER_ACCEPT addressing (the scanned
    // desktop device id + the token hash), stashed at submitCode() so
    // checkPairingState() can idempotently re-emit the responder-accept
    // on each poll while still awaiting the desktop's confirm.
    #[Locked]
    public string $importResponderTokenHash = '';

    #[Locked]
    public string $importDesktopDeviceId = '';

    public bool $awaitingPeer = false;

    /** @var list<string> the 6 derived safety-number words shown on the confirm step */
    public array $safetyWords = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function mount(QrScanBridge $qrBridge, Request $request, CurrentUser $currentUser, MobileImportIntentGate $importIntent): void
    {
        // Read-only signal, set once at mount() from the server-side
        // request query - never re-derived from client state afterward.
        $this->importMode = $request->query('mode') === 'import';

        // The self-mint-skip decision below must not depend on this query
        // param surviving every navigation - echo it into the durable
        // MobileImportIntentGate marker the moment it is observed, so a
        // later re-entry without the param still reads the durable signal.
        if ($this->importMode) {
            $importIntent->markImporting($currentUser->user()->id);
        }

        $this->enterACode($qrBridge);
    }

    // -------------------------------------------------------------------------
    // Landing hook - camera-first, word-code fallback
    // -------------------------------------------------------------------------

    // On mobile the camera viewfinder replaces the text-first enter_code
    // step as the default landing. Falls through to the same enter_code
    // text step, unchanged, when the native scanner is unavailable -
    // never a dead end.
    public function enterACode(QrScanBridge $qrBridge): void
    {
        $this->wordCode = '';
        $this->flashMessage = '';
        $this->side = 'responder';

        if ($qrBridge->isAvailable()) {
            $this->step = 'scan';
            $this->cameraUnavailableNotice = false;

            return;
        }

        $this->step = 'enter_code';
        $this->cameraUnavailableNotice = true;
    }

    // The deliberate "I'd rather type the code" choice, as opposed to
    // cameraDenied()'s forced fallback: same destination, but no amber
    // camera-unavailable notice, because nothing failed here.
    public function useWordCode(): void
    {
        $this->flashMessage = '';
        $this->cameraUnavailableNotice = false;
        $this->step = 'enter_code';
    }

    // Presents the OS scanner. Driven from the view rather than mount() so
    // the component is already live when the camera opens - a scan that
    // completes before Livewire is listening would drop its CodeScanned
    // event. Re-callable, so it serves the first open and every retry.
    public function startScan(QrScanBridge $qrBridge): void
    {
        $this->flashMessage = '';

        if (! $qrBridge->open(Lang::get('mobile::pairing.scan_prompt'))) {
            $this->cameraDenied();
        }
    }

    // The decoded payload arrives here from the native scanner after its
    // activity closes. Dependencies lead so the optional payload fields can
    // carry defaults; Livewire binds the payload by name, not position.
    #[On(self::EVENT_CODE_SCANNED)]
    public function onCodeScanned(
        CurrentUser $currentUser,
        QrScanBridge $qrBridge,
        PairingGateway $gateway,
        Session $session,
        LoggerInterface $logger,
        string $data = '',
        string $format = '',
        ?string $id = null,
    ): void {
        if ($data === '') {
            return;
        }

        $this->submitCode($data, $currentUser, $qrBridge, $gateway, $session, $logger);
    }

    // Fired when the user backs out of the scanner or the OS refuses the
    // camera permission. Both land on the same typed-code fallback - the
    // flow is never a dead end.
    #[On(self::EVENT_SCANNER_CANCELLED)]
    public function onScannerCancelled(bool $cancelled = true, ?string $reason = null, ?string $id = null): void
    {
        $this->cameraDenied();
    }

    // The native camera reports permission-denied/no-camera at runtime -
    // a different signal than QrScanBridge::isAvailable()'s coarse
    // plugin-resolvable check (the plugin can be resolvable while the OS
    // permission is still denied). Falls through to the enter_code step.
    public function cameraDenied(): void
    {
        $this->cameraUnavailableNotice = true;
        $this->step = 'enter_code';
    }

    // -------------------------------------------------------------------------
    // Accept a code - camera decode or typed word-code
    // -------------------------------------------------------------------------

    // Accepts a decoded QR payload or the typed word-code fallback,
    // entirely via QrScanBridge/PairingGateway. On success, auto-advances
    // to the same confirm step the word-code path uses.
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
    ): void {
        $userId = $currentUser->user()->id;

        // The typed word-code carries only the token - no initiator
        // identity to seed a cross-device row from - so import supports
        // the QR path only. Never a dead end: the copy directs the user
        // back to the QR.
        if ($this->importMode && $scannedPayload === null) {
            $this->flashMessage = Lang::get('mobile::pairing.errors.import_needs_qr');

            return;
        }

        // On a genuinely fresh, separate device database, the underlying
        // accept() call finds no local pending row - seed one from the
        // scanned QR's initiator identity first (import mode only, which
        // also carries the QR's optional relay/rtok params).
        $identity = null;

        if ($this->importMode) {
            $identity = $qrBridge->extractIdentity($scannedPayload);

            if ($identity === null) {
                $this->flashMessage = Lang::get('mobile::pairing.errors.invalid_code');

                return;
            }

            // Auto-configure this device's relay transport from the QR
            // before seeding/accepting, so the responder-accept send below
            // has somewhere to deliver to. No-op when the QR carried no
            // relay param.
            $gateway->configureRelayFromQr($identity['relayEndpoint'], $identity['relayAuthToken']);

            $gateway->seedResponderToken(
                $identity['token'],
                $identity['deviceId'],
                $identity['ed25519PubHex'],
                $identity['x25519PubHex'],
                $userId,
            );
        }

        $result = $scannedPayload !== null
            ? $qrBridge->accept($scannedPayload, $userId, $session)
            : $gateway->acceptWordCode($this->wordCode, $userId, $session);

        if ($result === null) {
            $this->flashMessage = Lang::get('mobile::pairing.errors.invalid_code');

            return;
        }

        $this->pairingTokenId = $result['pairingTokenId'];
        $this->side = 'responder';
        $this->safetyWords = $result['safetyWords'];
        $this->flashMessage = '';
        $this->cameraUnavailableNotice = false;
        $this->step = 'confirm';

        // Propagate this device's responder identity to the desktop's own
        // database over the relay (import mode only). Best-effort: a
        // relay failure never dead-ends the confirm step already
        // rendered above - the desktop's poll will not advance until retried.
        if ($this->importMode) {
            $tokenHash = hash('sha256', $identity['token']);

            // Stash the addressing so the poll can idempotently re-emit
            // this responder-accept if the initial delivery below is lost.
            $this->importResponderTokenHash = $tokenHash;
            $this->importDesktopDeviceId = $identity['deviceId'];

            try {
                $gateway->sendResponderAccept($userId, $tokenHash, $identity['deviceId'], $session);
            } catch (Throwable $e) {
                $logger->warning('MobilePairingScan: cross-device PAIR_RESPONDER_ACCEPT relay delivery failed.', [
                    'pairing_token_id' => $this->pairingTokenId,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Poll: advance the flow when the peer acts (wire:poll.3s target on
    // the confirm step)
    // -------------------------------------------------------------------------

    // The side that confirms FIRST never sees a CONFIRMED return from its
    // own confirmMatch() call - it learns of the completed both-confirm
    // HERE, via this poll. Guarded to the actual confirm -> success
    // transition so it does not re-run the migration on every poll tick.
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

        // Apply any inbound cross-device frame BEFORE reading tokenState()
        // below - this drives this device's local row to CONFIRMED, which
        // in turn lets shouldDeferSelfMint() correctly keep this device
        // from self-minting once its keyring is meant to converge instead.
        try {
            $gateway->drainPairingFrames($userId);
        } catch (Throwable $e) {
            $logger->warning('MobilePairingScan: cross-device relay drain failed during poll.', [
                'user_id' => $userId,
                'exception' => $e::class,
            ]);
        }

        $state = $gateway->tokenState((int) $this->pairingTokenId, $userId);

        // The initial responder-accept is single-shot; if its relay
        // delivery was lost the desktop never binds. Re-emit it
        // idempotently on each poll while still on the confirm step and
        // not yet confirmed - a transient relay failure self-heals.
        if ($this->importMode
            && $state !== PairingGateway::STATE_CONFIRMED
            && $this->step === 'confirm'
            && $this->importResponderTokenHash !== ''
            && $this->importDesktopDeviceId !== ''
        ) {
            try {
                $gateway->sendResponderAccept($userId, $this->importResponderTokenHash, $this->importDesktopDeviceId, $session);
            } catch (Throwable $e) {
                $logger->warning('MobilePairingScan: cross-device PAIR_RESPONDER_ACCEPT relay re-emit failed during poll.', [
                    'user_id' => $userId,
                    'exception' => $e::class,
                ]);
            }
        }

        if ($state === PairingGateway::STATE_CONFIRMED && $this->step !== 'success') {
            $this->step = 'success';

            // The import branch never self-mints - it defers epoch
            // acquisition entirely to the desktop's delivered epochs.
            // shouldDeferSelfMint() derives the decision from durable
            // state, never $this->importMode alone.
            if (! $this->shouldDeferSelfMint($userId, $importIntent, $db)) {
                try {
                    $migrationService->migrate($currentUser->user(), $session);
                } catch (Throwable) {
                    // Best-effort - a migration failure never undoes the
                    // just-completed pairing.
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Step 3: confirm the safety-number (the trust gate)
    // -------------------------------------------------------------------------

    // Identical trust gate reached via PairingGateway::confirm() - this
    // mobile entry point introduces no new admission path. The moment
    // bothConfirmed() admits this device as a peer, at-rest encryption
    // auto-activates (idempotent, no decline affordance).
    public function confirmMatch(
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

        // Bind the confirming side to THIS device's real identity - the
        // gateway derives the side from this device id, never from
        // client state.
        $deviceId = $gateway->currentDeviceId($userId, $session);
        if ($deviceId === null) {
            $this->flashMessage = Lang::get('mobile::pairing.errors.identity_locked');

            return;
        }

        $state = $gateway->confirm((int) $this->pairingTokenId, $userId, $deviceId);

        // Send this device's own signed PAIR_CONFIRM to the peer over the
        // relay, safe regardless of $state - the frame is only ever
        // consumable once the peer's own local side independently confirms
        // too. Unconditional (unlike the import-mode-only send above).
        $this->sendConfirmOverRelay($gateway, $userId, $db, $session, $logger);

        if ($state === PairingGateway::STATE_CONFIRMED) {
            $this->awaitingPeer = false;
            $this->step = 'success';

            // Same self-mint deferral as checkPairingState() - see
            // shouldDeferSelfMint(). The create-account (non-import) path
            // is unchanged.
            if (! $this->shouldDeferSelfMint($userId, $importIntent, $db)) {
                try {
                    $migrationService->migrate($currentUser->user(), $session);
                } catch (Throwable) {
                    // Best-effort - a migration failure never undoes the
                    // just-completed pairing.
                }
            }

            return;
        }

        $this->awaitingPeer = true;
    }

    // Delivers this device's signed PAIR_CONFIRM frame to the bound
    // initiator side of the in-flight token (this component always plays
    // 'responder'). Best-effort: a RuntimeException from the gateway is
    // caught and logged, never surfaced as a flash error.
    private function sendConfirmOverRelay(
        PairingGateway $gateway,
        int $userId,
        DatabaseManager $db,
        Session $session,
        LoggerInterface $logger,
    ): void {
        $initiatorDeviceId = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
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

    // Whether the self-mint migrate() call above must be skipped -
    // derived from durable state (isImporting() + an empty keyring),
    // never $this->importMode alone, since a re-entry without the query
    // param must still defer correctly. See .docs/features/mobile/architecture.md.
    private function shouldDeferSelfMint(int $userId, MobileImportIntentGate $importIntent, DatabaseManager $db): bool
    {
        if (! $importIntent->isImporting($userId)) {
            return false;
        }

        $currentEpoch = $db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->value('current_epoch');

        return $currentEpoch === null;
    }

    // -------------------------------------------------------------------------
    // Cancel / finish
    // -------------------------------------------------------------------------

    // Only expires a still-pending/awaiting_confirm token, never a
    // just-confirmed one.
    public function cancelPairing(
        CurrentUser $currentUser,
        PairingGateway $gateway,
        UrlGenerator $urls,
    ): void {
        if ($this->pairingTokenId !== '') {
            $gateway->expire((int) $this->pairingTokenId, $currentUser->user()->id);
        }

        $this->redirect($urls->route('sync.index'), navigate: false);
    }

    // Does not touch the now-confirmed token. In import mode this
    // advances into the blocking, resumable initial-sync gate (the phone
    // needs to pull its full history before landing on a populated
    // dashboard); the non-import path still returns to the sync screen.
    public function finishPairing(UrlGenerator $urls): void
    {
        $route = $this->importMode ? 'mobile.setup' : 'sync.index';

        $this->redirect($urls->route($route), navigate: false);
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.mobile-pairing-scan');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('mobile::pairing.page_title').' · Beatrax']);

        return $view;
    }
}
