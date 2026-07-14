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
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Mobile\Internal\Pairing\QrScanBridge;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Modules\Sync\Public\Services\PairingGateway;
use Throwable;

/**
 * The `/mobile/pair` route (D-01/D-02, MOBILE-01) — camera-first pairing
 * entry with a word-code fallback, extending the Phase 12 `PairingFlowModal`
 * step machine (15-PATTERNS.md §MobilePairingScan — "extend, don't
 * rebuild") into a standalone mobile page rather than a nested modal step.
 *
 * Step machine: `scan` (default, camera viewfinder) | `enter_code`
 * (fallback, D-02) → `confirm` (safety-number, D-07) → `success`. Reuses
 * `PairingFlowModal`'s exact METHOD NAMES (`enterACode()`, `submitCode()`)
 * and `#[Locked]` property discipline on `pairingTokenId`/`side` — the
 * trust-gate defense-in-depth this mobile entry point must not weaken
 * (T-15-19) — but is a SEPARATE class (structurally analogous, not literal
 * PHP inheritance): `PairingFlowModal` lives in `Modules\Sync\Internal\
 * Http\Livewire`, which this module may never import directly
 * (`App\PhpStan\Rules\BoundaryRule`), mirroring how `MobileLockScreen`
 * (Plan 06) is "structurally identical" to `LockScreen` via a SEPARATE
 * class, not inheritance.
 *
 * Cross-module rule: every Sync-module collaborator is reached exclusively
 * via `Modules\Sync\Public\Services\PairingGateway` (added alongside this
 * plan, mirrors `Modules\Auth\Public\Services\MobileLockGateway`'s Plan 06
 * precedent) or `Modules\Core\Public\Services\EncryptionMigrationService`
 * (already Public) — never `Modules\Sync\Internal\*` directly.
 *
 * Trust gate (D-07, unchanged): the safety-number is derived independently
 * on BOTH peers from BOTH stored public keys; `device_registry.confirmed_at`
 * is set ONLY after `PairingGateway::confirm()`'s underlying
 * `PairingTokenService::confirm()` both-confirm transition (T-15-17). Both
 * the camera-scan path (`submitCode($scannedPayload)`) and the typed
 * word-code path (`submitCode()`) funnel into the IDENTICAL `confirmMatch()`
 * gate below — no new trust mechanism (must_haves.truths).
 *
 * Constructor-free Livewire component (phpstan-strict-rules forbids a
 * constructor on a `Component` subclass) — every collaborator arrives as a
 * mount()/action-method parameter, exactly like the Auth/Sync analogs.
 */
final class MobilePairingScan extends Component
{
    /** The active step: scan|enter_code|confirm|success. */
    public string $step = 'scan';

    /**
     * The pairing_tokens row id for the in-flight handshake ('' when none).
     *
     * #[Locked] — the trust gate (D-07/CR-01) MUST NOT let the client
     * retarget which token is being confirmed. Only server code
     * (submitCode()) may set this; Livewire rejects any client-side
     * mutation. Mirrors PairingFlowModal::$pairingTokenId exactly.
     */
    #[Locked]
    public string $pairingTokenId = '';

    /**
     * Which side this device plays in the in-flight handshake. Always
     * 'responder' on this mobile entry point (it only ever scans/types the
     * OTHER device's code — "show my code" is not this plan's scope).
     *
     * #[Locked] — a client must never be able to flip its side and confirm
     * the peer's column (CR-01). The authoritative side is re-derived
     * server-side in PairingTokenService::confirm() from the caller's own
     * device id; this property is UI-only and locked as defense-in-depth.
     */
    #[Locked]
    public string $side = '';

    /** The typed word-code: the user-input on the enter_code fallback step. */
    public string $wordCode = '';

    /** Inline error / status message (invalid-code, expired, locked). */
    public string $flashMessage = '';

    /**
     * True when the camera-unavailable / permission-denied amber notice
     * should render on the enter_code step (UI-SPEC §1 copy contract) —
     * kept separate from $flashMessage so an actual invalid-code error
     * never gets overwritten by (or confused with) the notice's different
     * visual tone (amber vs rose).
     */
    public bool $cameraUnavailableNotice = false;

    /**
     * Phase 15 import-join (Task 2): true when this pairing attempt reached
     * `/mobile/pair` via the "Import from another device" fresh-device
     * bootstrap CTA (`?mode=import`, set from `MobileImportBootstrap`'s
     * redirect). Toggles TWO independently-safe things, neither a trust
     * decision: (1) `submitCode()` seeds a LOCAL pairing_tokens row from
     * the scanned QR's initiator identity before accepting (G1), and (2)
     * `confirmMatch()`/`checkPairingState()` skip the self-mint
     * `EncryptionMigrationService::migrate()` call (B2) — the import
     * keyring stays empty until the desktop's real epochs are delivered.
     *
     * #[Locked] — read-only from `?mode=import` at mount() time; never
     * client-settable (defense-in-depth, though neither branch it gates
     * admits a device or mints/reveals any key material on its own).
     */
    #[Locked]
    public bool $importMode = false;

    /** Whether this side has confirmed the safety-number (awaits the peer). */
    public bool $awaitingPeer = false;

    /**
     * The 6 derived safety-number words shown on the confirm step.
     *
     * @var list<string>
     */
    public array $safetyWords = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function mount(QrScanBridge $qrBridge, Request $request, CurrentUser $currentUser, MobileImportIntentGate $importIntent): void
    {
        // Phase 15 import-join: read-only signal, set once at mount() from
        // the server-side request query — never re-derived from client
        // state afterward (#[Locked]).
        $this->importMode = $request->query('mode') === 'import';

        // MEDIUM-01 fix (15-import-join-REVIEW.md): the SELF-MINT-SKIP
        // decision below (confirmMatch()/checkPairingState()) must not
        // depend on this query param surviving every navigation — echo it
        // into the durable MobileImportIntentGate marker the moment it is
        // observed, so a LATER re-entry to this route WITHOUT the param
        // (back button, bookmark, a relaunched/tombstoned process) still
        // reads the durable signal this visit already persisted.
        // `MobileImportBootstrap::provisionDeviceLocally()` is the
        // AUTHORITATIVE source of this marker; this is defense-in-depth.
        if ($this->importMode) {
            $importIntent->markImporting($currentUser->user()->id);
        }

        $this->enterACode($qrBridge);
    }

    // -------------------------------------------------------------------------
    // Landing hook (D-01) — camera-first, word-code fallback (D-02)
    // -------------------------------------------------------------------------

    /**
     * Camera-first landing hook — mirrors `PairingFlowModal::enterACode()`'s
     * role/name exactly (15-PATTERNS.md), but on mobile the camera
     * viewfinder REPLACES the text-first `enter_code` step as the default
     * landing (UI-SPEC §1). Falls through to the SAME `enter_code` text
     * step, unchanged, when the native scanner is unavailable (D-02) — never
     * a dead end.
     */
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

    /**
     * The native camera reports permission-denied / no-camera at RUNTIME —
     * a DIFFERENT signal than `QrScanBridge::isAvailable()`'s coarse
     * plugin-resolvable check (the plugin can be resolvable while the OS
     * permission is still denied). Falls through to the SAME `enter_code`
     * text step (D-02) with the amber notice — never a dead end.
     */
    public function cameraDenied(): void
    {
        $this->cameraUnavailableNotice = true;
        $this->step = 'enter_code';
    }

    // -------------------------------------------------------------------------
    // Accept a code — camera decode OR typed word-code (D-01/D-02)
    // -------------------------------------------------------------------------

    /**
     * Accept a decoded QR payload OR the typed word-code fallback — same
     * method name as `PairingFlowModal::submitCode()` (15-PATTERNS.md),
     * extended with an optional scanned-payload argument for the camera
     * path. Mirrors `submitCode()`'s decode-then-accept-then-derive-safety-
     * words shape exactly, entirely via `QrScanBridge`/`PairingGateway`
     * (`Modules\Sync\Internal\*` is off-limits to this module —
     * BoundaryRule).
     *
     * On success: auto-advances to the SAME `confirm` step the word-code
     * path uses — no new confirmation screen (UI-SPEC §1).
     *
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
    ): void {
        $userId = $currentUser->user()->id;

        // Phase 15 import-join (Task 2, OQ-2): the typed word-code carries
        // only the token — no initiator identity to seed a cross-device row
        // from (G1) — so v1 import supports the QR path only. Never a dead
        // end: the copy directs the user back to the QR.
        if ($this->importMode && $scannedPayload === null) {
            $this->flashMessage = 'Scan the QR code shown on the other device to import.';

            return;
        }

        // Phase 15 import-join (G1): on a genuinely fresh, separate device
        // database, acceptToken()'s underlying PairingTokenService::accept()
        // finds no local pending row — seed one from the scanned QR's
        // initiator identity FIRST. A seed failure (malformed key material)
        // simply means the subsequent accept() call below finds no pending
        // row and falls through to the SAME generic invalid/expired flash
        // as any other bad code. $scannedPayload is guaranteed non-null
        // here — the guard above already returned for the importMode +
        // null-payload (word-code) combination.
        if ($this->importMode) {
            $identity = $qrBridge->extractIdentity($scannedPayload);

            if ($identity === null) {
                $this->flashMessage = 'This code is invalid or has expired. Ask the other device to generate a new one.';

                return;
            }

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
            $this->flashMessage = 'This code is invalid or has expired. Ask the other device to generate a new one.';

            return;
        }

        $this->pairingTokenId = $result['pairingTokenId'];
        $this->side = 'responder';
        $this->safetyWords = $result['safetyWords'];
        $this->flashMessage = '';
        $this->cameraUnavailableNotice = false;
        $this->step = 'confirm';
    }

    // -------------------------------------------------------------------------
    // Poll: advance the flow when the peer acts (wire:poll.3s target on the
    // confirm step) — mirrors PairingFlowModal::checkPairingState()
    // -------------------------------------------------------------------------

    /**
     * D-07 (Rule 2 — missing critical functionality if omitted): the side
     * that confirms FIRST never sees a CONFIRMED return from its own
     * confirmMatch() call (it only sets awaitingPeer=true) — it learns of
     * the completed both-confirm HERE, via this poll, exactly like
     * `PairingFlowModal::checkPairingState()`. Guarded to the actual
     * confirm → success TRANSITION so it does not re-run the migration on
     * every subsequent poll tick while the success step stays on screen.
     */
    public function checkPairingState(
        CurrentUser $currentUser,
        PairingGateway $gateway,
        Session $session,
        EncryptionMigrationService $migrationService,
        MobileImportIntentGate $importIntent,
        DatabaseManager $db,
    ): void {
        if ($this->pairingTokenId === '') {
            return;
        }

        $userId = $currentUser->user()->id;
        $state = $gateway->tokenState((int) $this->pairingTokenId, $userId);

        if ($state === PairingGateway::STATE_CONFIRMED && $this->step !== 'success') {
            $this->step = 'success';

            // Phase 15 import-join (B2): the import branch NEVER self-mints
            // — it defers epoch acquisition entirely to the desktop's
            // delivered epochs. Calling migrate() here would mint a
            // colliding local epoch 1 and permanently strand every
            // desktop epoch-1 entry in gdk_decrypt_failed quarantine
            // (GdkEpochControlHandler's idempotency guard silently drops an
            // already-present epoch id). The CREATE-ACCOUNT (non-import)
            // path is UNCHANGED — it still self-mints here exactly as
            // before.
            //
            // MEDIUM-01 fix (15-import-join-REVIEW.md): the decision is
            // now derived from DURABLE state (the MobileImportIntentGate
            // marker + an empty keyring), not `$this->importMode` alone —
            // see shouldDeferSelfMint()'s own docblock.
            if (! $this->shouldDeferSelfMint($userId, $importIntent, $db)) {
                try {
                    $migrationService->migrate($currentUser->user(), $session);
                } catch (Throwable) {
                    // Best-effort — see PairingFlowModal::checkPairingState()'s
                    // identical docblock. A migration failure never undoes the
                    // just-completed pairing.
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Step 3: confirm the safety-number (the trust gate, D-07)
    // -------------------------------------------------------------------------

    /**
     * Record this side's safety-number confirmation — IDENTICAL trust gate
     * to `PairingFlowModal::confirmMatch()` (D-07), reached via
     * `PairingGateway::confirm()`. `device_registry.confirmed_at` is set
     * ONLY once bothConfirmed() inside that call — this mobile entry point
     * introduces NO new admission path (T-15-17/T-15-19).
     *
     * D-07 mandatory-when-synced (Phase 14): the moment bothConfirmed()
     * admits this device as a peer, at-rest encryption auto-activates via
     * `EncryptionMigrationService::migrate()` — no decline affordance,
     * mirroring `PairingFlowModal::confirmMatch()`'s own auto-trigger.
     * `migrate()` is idempotent (a no-op once already enabled).
     */
    public function confirmMatch(
        CurrentUser $currentUser,
        PairingGateway $gateway,
        Session $session,
        EncryptionMigrationService $migrationService,
        MobileImportIntentGate $importIntent,
        DatabaseManager $db,
    ): void {
        if ($this->pairingTokenId === '') {
            return;
        }

        $userId = $currentUser->user()->id;

        // Bind the confirming side to THIS device's real identity (CR-01) —
        // the gateway derives the side from this device id, never from
        // client state.
        $deviceId = $gateway->currentDeviceId($userId, $session);
        if ($deviceId === null) {
            $this->flashMessage = 'Your device identity is locked. Unlock the app and try again.';

            return;
        }

        $state = $gateway->confirm((int) $this->pairingTokenId, $userId, $deviceId);

        if ($state === PairingGateway::STATE_CONFIRMED) {
            $this->awaitingPeer = false;
            $this->step = 'success';

            // Phase 15 import-join (B2) — see checkPairingState()'s
            // identical guard docblock. The CREATE-ACCOUNT (non-import)
            // path is UNCHANGED.
            //
            // MEDIUM-01 fix (15-import-join-REVIEW.md): see
            // shouldDeferSelfMint()'s own docblock.
            if (! $this->shouldDeferSelfMint($userId, $importIntent, $db)) {
                try {
                    $migrationService->migrate($currentUser->user(), $session);
                } catch (Throwable) {
                    // Best-effort — see docblock above.
                }
            }

            return;
        }

        // This side has confirmed; wait for the peer (the poll advances to success).
        $this->awaitingPeer = true;
    }

    /**
     * MEDIUM-01 fix (15-import-join-REVIEW.md): whether the self-mint
     * `EncryptionMigrationService::migrate()` call above must be skipped —
     * derived from DURABLE state, never `$this->importMode` alone. The
     * previous implementation gated purely on the `?mode=import` query
     * param read once at mount() — a re-entry to this route WITHOUT the
     * param (back button, bookmark, a relaunched/tombstoned NativePHP
     * process) would self-mint a colliding local epoch 1, silently
     * stranding the desktop's delivered epoch-1 history in
     * `gdk_decrypt_failed` quarantine (`GdkEpochControlHandler`'s
     * idempotency guard).
     *
     * Deferral requires BOTH:
     *   1. `MobileImportIntentGate::isImporting()` — this device's account
     *      was bootstrapped (or has ever visited this route) via the
     *      import flow.
     *   2. The keyring is still genuinely empty
     *      (`sync_encryption_state.current_epoch IS NULL`) — read via a
     *      bare table query, mirroring `InitialSyncPuller::
     *      keyringIsNonEmpty()`'s identical "a raw read of a public,
     *      non-secret column crosses no module boundary" precedent
     *      (`Modules\Sync\Internal\Crypto\GdkKeyringService` is off-limits
     *      to this module directly).
     *
     * The second condition matters even for a genuinely marked-import
     * device: once its keyring has converged (the desktop's epochs
     * arrived), this must stop returning true — otherwise a LATER,
     * unrelated pairing (e.g. adding a THIRD device) on the same phone
     * would also incorrectly defer self-mint forever.
     */
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

    /**
     * Cancel an in-flight pairing (mirrors PairingFlowModal::cancelPairing()
     * — only expire a still-pending/awaiting_confirm token, never a
     * just-confirmed one) and leave the page.
     */
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

    /**
     * Leave the page after a SUCCESSFUL pairing (the success-step "Done"
     * button) — does not touch the now-confirmed token.
     *
     * Phase 15 import-join: in import mode this advances into the
     * blocking, resumable initial-sync gate (`mobile.setup`) — the phone
     * has no meaningful sync SETTINGS to show yet, it needs to pull its
     * full history (and receive the desktop's epochs) before landing on a
     * populated dashboard. The non-import (existing) path is UNCHANGED —
     * it still returns to the sync/devices screen.
     */
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
        $view->extends('layouts.app', ['title' => 'Pair a device · beatrax']);

        return $view;
    }
}
