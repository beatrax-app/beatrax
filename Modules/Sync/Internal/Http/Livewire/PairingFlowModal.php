<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Pairing\InvalidPublicKeyException;
use Modules\Sync\Internal\Pairing\PairingStateMachine;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\QrPayloadBuilder;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Public\Services\PairingGateway;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bidirectional pairing-flow modal (PAIR-02, D-04/D-05/D-07/D-13).
 *
 * A step-based flow inside the Devices & Sync section's Flux modal:
 *
 *   choose_direction → show_code  → confirm → success   (this device shows)
 *   choose_direction → enter_code → confirm → success   (this device scans/types)
 *
 * Per-method DI throughout (never constructor DI) — mirrors AppLockSettingsSection
 * and keeps the component PHPStan-clean at level 10.
 *
 * Trust gate (D-07): the safety-number is derived independently on BOTH peers
 * from BOTH stored public keys; device_registry.confirmed_at is set ONLY after
 * bothConfirmed() — confirmMatch() routes through PairingTokenService::confirm()
 * which owns the both-confirm transition. An unconfirmed/forged device therefore
 * never enters DeviceRegistryService::deviceKeys() (T-12-15/T-12-17).
 *
 * Every pairing_tokens / device_registry read is user-scoped to the CurrentUser
 * id (T-12-16) — a user-A token can never be consumed under user B.
 *
 * Scope boundary: identity exchange + safety-number trust ONLY. No sockets, no
 * Noise transport, no mDNS (Phase 13); no revoke (Phase 14).
 */
final class PairingFlowModal extends Component
{
    /**
     * Whether the hosting flux:modal is open. Bound by the parent section.
     */
    public bool $open = false;

    /**
     * The active step: choose_direction|show_code|enter_code|confirm|success.
     */
    public string $step = 'choose_direction';

    /**
     * The pairing_tokens row id for the in-flight handshake ('' when none).
     *
     * #[Locked] — the trust gate (D-07/CR-01) MUST NOT let the client retarget
     * which token is being confirmed. Only server code (showMyCode/submitCode)
     * may set this; Livewire rejects any client-side mutation.
     */
    #[Locked]
    public string $pairingTokenId = '';

    /**
     * The typed word-code: displayed on show_code, the user-input on enter_code.
     */
    public string $wordCode = '';

    /**
     * The inline QR SVG markup for show_code.
     */
    public string $qrSvg = '';

    /**
     * Remaining seconds before the displayed code expires (D-13 countdown seed).
     */
    public int $expiresInSeconds = 600;

    /**
     * Inline error / status message (invalid-code, expired).
     */
    public string $flashMessage = '';

    /**
     * Whether this side has confirmed the safety-number (awaits the peer).
     */
    public bool $awaitingPeer = false;

    /**
     * Phase 15 import-join (Task 3, B1): non-blocking retry indicator for a
     * failed epoch fan-out on the desktop side — mirrors
     * `DevicesAndSyncSettingsSection::$encryptionActivationFailed`'s
     * established shape. A fan-out failure must never silently strand the
     * newly-confirmed phone permanently unable to decrypt, so this is
     * logged (never key material — device ids / epoch ids / counts only)
     * and surfaced here for a future retry affordance.
     */
    public bool $fanOutFailed = false;

    /**
     * Which side this device plays in the in-flight handshake.
     *
     * #[Locked] — a client must never be able to flip its side and confirm the
     * peer's column (CR-01). The authoritative side is re-derived server-side in
     * PairingTokenService::confirm() from the caller's own device id; this
     * property is UI-only and locked against tampering as defense-in-depth.
     */
    #[Locked]
    public string $side = '';

    /**
     * The 6 derived safety-number words shown on the confirm step.
     *
     * @var list<string>
     */
    public array $safetyWords = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Seed the open state from the parent section ($open mount param).
     */
    public function mount(bool $open = false): void
    {
        $this->open = $open;
    }

    /**
     * Open the modal from the parent "Pair a new device" button.
     *
     * The component is rendered unconditionally (always in the DOM) so the
     * hosting <flux:modal wire:model="open"> sees a real false→true transition
     * — Flux only shows the dialog on that transition, never on a fresh mount
     * that is already-true. Reset the flow to step 1 so a reopened modal never
     * resumes a stale/cancelled handshake.
     */
    #[On('open-pairing-modal')]
    public function openModal(): void
    {
        $this->step = 'choose_direction';
        $this->pairingTokenId = '';
        $this->wordCode = '';
        $this->qrSvg = '';
        $this->expiresInSeconds = 600;
        $this->flashMessage = '';
        $this->awaitingPeer = false;
        $this->fanOutFailed = false;
        $this->side = '';
        $this->safetyWords = [];
        $this->open = true;
    }

    // -------------------------------------------------------------------------
    // Step 1: choose direction
    // -------------------------------------------------------------------------

    /**
     * Show this device's code: load the identity, issue a token, build the QR +
     * word-code, and advance to show_code (D-04 show direction / D-05 word-code).
     */
    public function showMyCode(
        CurrentUser $currentUser,
        DeviceIdentityLoader $identityLoader,
        PairingTokenService $tokenService,
        QrPayloadBuilder $qrBuilder,
        WordCodeEncoder $wordEncoder,
        DatabaseManager $db,
        Session $session,
    ): void {
        $userId = $currentUser->user()->id;

        $identity = $identityLoader->load($userId, $session);
        if ($identity === null) {
            $this->flashMessage = 'Your device identity is locked. Unlock the app and try again.';

            return;
        }

        $token = $tokenService->issue(
            $userId,
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
        );

        $this->qrSvg = $qrBuilder->buildSvg(
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
            $token,
        );
        $this->wordCode = $wordEncoder->encode($token);
        $this->pairingTokenId = (string) $this->tokenRowId($db, $userId, $token);
        $this->side = 'initiator';
        $this->expiresInSeconds = 600;
        $this->flashMessage = '';
        $this->step = 'show_code';
    }

    /**
     * Advance to the enter-code step (D-04 scan/type direction).
     */
    public function enterACode(): void
    {
        $this->step = 'enter_code';
        $this->wordCode = '';
        $this->flashMessage = '';
        $this->side = 'responder';
    }

    // -------------------------------------------------------------------------
    // Step 2b: submit a typed code
    // -------------------------------------------------------------------------

    /**
     * Decode the typed word-code and accept the peer's token, binding this
     * device's responder keys. On success advance to confirm and derive the
     * shared safety-number; on failure stay on enter_code with the inline error.
     */
    public function submitCode(
        CurrentUser $currentUser,
        DeviceIdentityLoader $identityLoader,
        PairingTokenService $tokenService,
        WordCodeEncoder $wordEncoder,
        SafetyNumberDeriver $safetyDeriver,
        DatabaseManager $db,
        Session $session,
    ): void {
        $userId = $currentUser->user()->id;

        $identity = $identityLoader->load($userId, $session);
        if ($identity === null) {
            $this->flashMessage = 'Your device identity is locked. Unlock the app and try again.';

            return;
        }

        try {
            $tokenHex = $wordEncoder->decode($this->wordCode);
        } catch (\InvalidArgumentException) {
            $this->flashMessage = 'This code is invalid or has expired. Ask the other device to generate a new one.';

            return;
        }

        $accepted = $tokenService->accept(
            $tokenHex,
            $userId,
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
        );

        if ($accepted === false || ! $accepted instanceof \stdClass) {
            $this->flashMessage = 'This code is invalid or has expired. Ask the other device to generate a new one.';

            return;
        }

        $this->pairingTokenId = (string) (is_numeric($accepted->id) ? (int) $accepted->id : 0);
        $this->side = 'responder';
        $this->safetyWords = $this->deriveSafetyWords($db, $safetyDeriver, $userId);
        $this->flashMessage = '';
        $this->step = 'confirm';
    }

    // -------------------------------------------------------------------------
    // Poll: advance the flow when the peer acts (wire:poll.3s target)
    // -------------------------------------------------------------------------

    /**
     * Poll the in-flight token: advance show_code → confirm when the responder
     * has accepted (state awaiting_confirm), and any step → success when both
     * sides have confirmed (state confirmed). Scoped to the CurrentUser id.
     *
     * D-07 (Rule 2 — missing critical functionality): the side that confirms
     * FIRST never sees a CONFIRMED return from its own confirmMatch() call (it
     * only sets awaitingPeer=true) — it learns of the completed both-confirm
     * HERE, via this poll. Without the same mandatory-encryption auto-trigger
     * confirmMatch() runs, that device would stay unencrypted despite being a
     * fully-confirmed sync peer — the exact gap D-07 forbids. Guarded to the
     * actual show_code/confirm → success TRANSITION so it does not re-run on
     * every subsequent poll tick while the success step stays on screen.
     */
    public function checkPairingState(
        CurrentUser $currentUser,
        DatabaseManager $db,
        SafetyNumberDeriver $safetyDeriver,
        Session $session,
        EncryptionMigrationService $migrationService,
        PairingGateway $gateway,
        LoggerInterface $logger,
    ): void {
        if ($this->pairingTokenId === '') {
            return;
        }

        $userId = $currentUser->user()->id;

        $row = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
            ->where('user_id', $userId)
            ->first(['state']);

        if ($row === null) {
            return;
        }

        if ($row->state === PairingStateMachine::AWAITING_CONFIRM && $this->step === 'show_code') {
            $this->safetyWords = $this->deriveSafetyWords($db, $safetyDeriver, $userId);
            $this->step = 'confirm';

            return;
        }

        if ($row->state === PairingStateMachine::CONFIRMED && $this->step !== 'success') {
            $this->step = 'success';

            try {
                $migrationService->migrate($currentUser->user(), $session);
            } catch (Throwable) {
                // Best-effort — see confirmMatch()'s docblock.
            }

            // Phase 15 import-join (Task 3, B1): fan out EVERY desktop epoch
            // to the just-confirmed device — ONLY reachable here, inside the
            // state === CONFIRMED branch (trust-gate ordering, threat-model
            // item 1). migrate() runs FIRST so a desktop that had never
            // enabled encryption has something to deliver.
            $this->fanOutToNewlyConfirmedDevice($db, $gateway, $logger, $userId, $session);

            $this->dispatch('pairing-confirmed');
        }
    }

    // -------------------------------------------------------------------------
    // Step 3: confirm the safety-number (the trust gate, D-07)
    // -------------------------------------------------------------------------

    /**
     * Record this side's safety-number confirmation. PairingTokenService::confirm
     * sets device_registry.confirmed_at ONLY once bothConfirmed() — this is the
     * sole gate admitting the peer device to the trusted registry (D-07). When
     * the peer has already confirmed, this advances to success; otherwise this
     * side waits for the peer (the poll flips to success).
     *
     * D-07 mandatory-when-synced (Phase 14): the moment bothConfirmed() admits
     * this device as a peer, at-rest encryption auto-activates via
     * EncryptionMigrationService::migrate() — no decline affordance exists on
     * this path, mirroring DevicesAndSyncSettingsSection::enableSync()'s own
     * auto-trigger. migrate() is idempotent (a no-op once already enabled).
     */
    public function confirmMatch(
        CurrentUser $currentUser,
        DeviceIdentityLoader $identityLoader,
        PairingTokenService $tokenService,
        Session $session,
        EncryptionMigrationService $migrationService,
        DatabaseManager $db,
        PairingGateway $gateway,
        LoggerInterface $logger,
    ): void {
        if ($this->pairingTokenId === '') {
            return;
        }

        $userId = $currentUser->user()->id;

        // Bind the confirming side to THIS device's real identity (CR-01) — the
        // service derives the side from this device id, never from client state.
        $identity = $identityLoader->load($userId, $session);
        if ($identity === null) {
            $this->flashMessage = 'Your device identity is locked. Unlock the app and try again.';

            return;
        }

        // The service is the single source of truth for the trust decision: it
        // returns the resulting state, so we never re-read the row and re-derive
        // bothConfirmed() here (IN-04).
        $state = $tokenService->confirm((int) $this->pairingTokenId, $userId, $identity->deviceId);

        if ($state === PairingStateMachine::CONFIRMED) {
            $this->awaitingPeer = false;
            $this->step = 'success';

            // D-07 mandatory auto-activation — no decline path. A migration
            // failure never undoes the just-completed pairing; the encryption
            // row simply keeps rendering its mandatory/off state until the
            // next successful pass (mirrors enableSync()'s own best-effort
            // handling).
            try {
                $migrationService->migrate($currentUser->user(), $session);
            } catch (Throwable) {
                // Best-effort — see docblock above.
            }

            // Phase 15 import-join (Task 3, B1): fan out EVERY desktop epoch
            // to the just-confirmed device — ONLY reachable here, inside the
            // state === CONFIRMED branch (trust-gate ordering, threat-model
            // item 1). migrate() runs FIRST so a desktop that had never
            // enabled encryption has something to deliver.
            $this->fanOutToNewlyConfirmedDevice($db, $gateway, $logger, $userId, $session);

            $this->dispatch('pairing-confirmed');

            return;
        }

        // This side has confirmed; wait for the peer (the poll advances to success).
        $this->awaitingPeer = true;
    }

    /**
     * Resolve the just-confirmed RESPONDER device's `device_registry.id`
     * from this in-flight token, then fan out every keyring epoch to it
     * (Phase 15 import-join, Task 3). Best-effort — a fan-out failure
     * surfaces `$fanOutFailed` (non-blocking retry indicator, mirrors
     * `DevicesAndSyncSettingsSection::$encryptionActivationFailed`) but
     * NEVER undoes the just-completed pairing; a silently-swallowed
     * failure would leave the phone permanently unable to decrypt, so it is
     * logged (device/epoch ids and counts only — NEVER key material).
     *
     * Guarded exactly like the existing `migrate()` auto-trigger — reached
     * ONLY from the `state === CONFIRMED` branch in `confirmMatch()`/
     * `checkPairingState()` above, i.e. only after
     * `PairingTokenService::confirm()` returned CONFIRMED via
     * `bothConfirmed()` (T-15-17/T-15-19).
     */
    private function fanOutToNewlyConfirmedDevice(
        DatabaseManager $db,
        PairingGateway $gateway,
        LoggerInterface $logger,
        int $userId,
        Session $session,
    ): void {
        $tokenRow = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
            ->where('user_id', $userId)
            ->first(['responder_device_id']);

        $responderDeviceId = $tokenRow !== null && is_string($tokenRow->responder_device_id)
            ? $tokenRow->responder_device_id
            : null;

        if ($responderDeviceId === null) {
            return;
        }

        $deviceRegistryId = $db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $responderDeviceId)
            ->where('is_self', 0)
            ->value('id');

        if (! is_numeric($deviceRegistryId)) {
            return;
        }

        $this->fanOutFailed = false;

        try {
            $gateway->deliverAllEpochsToDevice($userId, (int) $deviceRegistryId, $session);
        } catch (Throwable $e) {
            $this->fanOutFailed = true;
            $logger->warning('GDK epoch fan-out to newly-confirmed device failed.', [
                'user_id' => $userId,
                'device_registry_id' => (int) $deviceRegistryId,
                'exception' => $e::class,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Expiry / regenerate / cancel
    // -------------------------------------------------------------------------

    /**
     * Surface the expired state when the countdown reaches zero (D-13). The
     * displayed code is no longer valid; the user taps "Generate new code".
     */
    public function onCodeExpired(
        CurrentUser $currentUser,
        PairingTokenService $tokenService,
    ): void {
        // Mark the abandoned/expired token so it is not left dangling as pending
        // (WR-04). The next issue() prunes it; expiring it now keeps the row's
        // state honest the moment the countdown hits zero.
        if ($this->pairingTokenId !== '') {
            $tokenService->expire((int) $this->pairingTokenId, $currentUser->user()->id);
        }

        $this->flashMessage = 'Code expired.';
        $this->expiresInSeconds = 0;
    }

    /**
     * Mint a fresh code after expiry (D-13 regenerate) — re-runs the show flow.
     */
    public function regenerateCode(
        CurrentUser $currentUser,
        DeviceIdentityLoader $identityLoader,
        PairingTokenService $tokenService,
        QrPayloadBuilder $qrBuilder,
        WordCodeEncoder $wordEncoder,
        DatabaseManager $db,
        Session $session,
    ): void {
        $this->showMyCode($currentUser, $identityLoader, $tokenService, $qrBuilder, $wordEncoder, $db, $session);
    }

    /**
     * Cancel an IN-FLIGHT pairing: expire the still-live token (so the peer must
     * restart) and reset the flow to the start (D-13 mid-flow cancel).
     *
     * WR-03: only expire a token that is still pending/awaiting_confirm — never a
     * just-confirmed token (that would flip a CONFIRMED token to EXPIRED and
     * muddy the lifecycle). The success "Done" button uses closeModal() instead.
     * The flux:modal @close hook can fire cancelPairing() a second time after we
     * set open=false, but by then reset() has cleared pairingTokenId to '' so the
     * expire branch below is skipped — the second call is an idempotent no-op.
     */
    public function cancelPairing(
        CurrentUser $currentUser,
        PairingTokenService $tokenService,
        DatabaseManager $db,
    ): void {
        if ($this->pairingTokenId !== '') {
            $userId = $currentUser->user()->id;
            $tokenId = (int) $this->pairingTokenId;

            $row = $db->connection()->table('pairing_tokens')
                ->where('id', $tokenId)
                ->where('user_id', $userId)
                ->first(['state']);

            // Only an unfinished handshake gets expired. A confirmed token is a
            // completed pairing — leave its terminal state intact.
            if ($row !== null && in_array($row->state, [
                PairingStateMachine::PENDING,
                PairingStateMachine::AWAITING_CONFIRM,
            ], true)) {
                $tokenService->expire($tokenId, $userId);
            }
        }

        $this->resetAndClose();
    }

    /**
     * Close the modal after a SUCCESSFUL pairing (the success-step "Done"
     * button, WR-03). Resets UI state and notifies the parent WITHOUT touching
     * the now-confirmed token — closing success is not a cancel.
     */
    public function closeModal(): void
    {
        $this->resetAndClose();
    }

    /**
     * Reset the flow to its initial state, close the modal, and tell the parent
     * section to clear its open flag + refresh its device list. Shared tail of
     * cancelPairing() and closeModal().
     */
    private function resetAndClose(): void
    {
        $this->reset();
        $this->open = false;
        // Tell the parent section to clear pairingModalOpen + refresh its list.
        $this->dispatch('pairing-closed');
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        return $views->make('sync::livewire.pairing-flow-modal');
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Look up the just-issued token's row id (user-scoped) so the poll can read
     * its state. The token is hashed at rest, so we match on the stored hash.
     */
    private function tokenRowId(DatabaseManager $db, int $userId, string $token): int
    {
        $row = $db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', hash('sha256', $token))
            ->first(['id']);

        return $row !== null && is_numeric($row->id) ? (int) $row->id : 0;
    }

    /**
     * Derive the shared 6-word safety-number from BOTH stored public keys of the
     * in-flight token (D-07/D-08) — order-independent, identical on both peers.
     *
     * @return list<string>
     */
    private function deriveSafetyWords(
        DatabaseManager $db,
        SafetyNumberDeriver $safetyDeriver,
        int $userId,
    ): array {
        $row = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
            ->where('user_id', $userId)
            ->first(['initiator_ed25519_pub_hex', 'responder_ed25519_pub_hex']);

        if ($row === null) {
            return [];
        }

        $initiatorEd = is_string($row->initiator_ed25519_pub_hex) ? $row->initiator_ed25519_pub_hex : null;
        $responderEd = is_string($row->responder_ed25519_pub_hex) ? $row->responder_ed25519_pub_hex : null;

        if ($initiatorEd === null || $responderEd === null) {
            return [];
        }

        // The stored hex is validated at the accept/issue trust boundary, but
        // guard the decode anyway (WR-01): a malformed key yields the generic
        // invalid-code flash instead of an uncaught SodiumException 500.
        try {
            return $safetyDeriver->deriveWords($initiatorEd, $responderEd);
        } catch (InvalidPublicKeyException) {
            return [];
        }
    }
}
