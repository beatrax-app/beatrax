<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Sync\Public\Services\PairingGateway;
use Throwable;

/**
 * The `/mobile/import` route (Phase 15 import-join, Task 5) — the
 * fresh-device local-identity bootstrap the mobile welcome screen's
 * "Import from another device" CTA now leads into, closing the deferred
 * item `15-onboarding-SUMMARY.md` recorded verbatim: "the deeper
 * fresh-device pairing/identity-bootstrap flow ... is explicitly out of
 * scope and deferred."
 *
 * Deliberately OUTSIDE the `auth` group (like `mobile.welcome`) — it
 * renders BEFORE any user account exists on this device, gated in front of
 * it by `MobileEnsureDatabaseReady` (which exempts `mobile.import` the
 * same way it already exempts `mobile.welcome`/`signup`/`mobile.pair`).
 *
 * Step machine: `collect_pin` (username/password/PIN form) →
 * `provisioning_failed` (idempotent-safe retry, only reached if step 2/3
 * below throw AFTER signup already committed) → `recovery_codes` (shown
 * once, mirrors the desktop signup ceremony) → redirect to
 * `mobile.pair?mode=import`.
 *
 * On submit, in STRICT order (each is a hard LOCK-04/D-02 precondition for
 * the next):
 *   1. `SignupAction::__invoke()` — the SAME first-user ceremony CREATE-
 *      ACCOUNT uses (Public, unmodified). On a fresh phone `User::count()
 *      === 0`, so the D-03 gate is open. Also logs the user in and
 *      dispatches `UserInstalled`.
 *   2. `MobileLockGateway::enableAppLock()` — mints the KEK, leaves the
 *      session UNLOCKED with the key present (the LOCK-04 precondition
 *      every keyring/identity write below requires).
 *   3. `PairingGateway::enableSyncIdentityWithoutEpoch()` — enables sync
 *      identity WITHOUT minting a GDK epoch (B2): the phone's keyring
 *      starts empty so the desktop's delivered epoch ids install without
 *      colliding against `GdkEpochControlHandler`'s idempotency guard.
 *
 * Steps 2-3 are made idempotent-safe (checked via a bare table read, not a
 * new cross-module class) so a genuine mid-flow failure AFTER signup has
 * already committed can be safely retried without re-running `SignupAction`
 * (which would fail the D-03 `User::count() === 0` gate a second time) and
 * without double-inserting/needlessly re-rotating an already-succeeded
 * step — SignupAction itself never leaves a half-provisioned USER (it is
 * fully transactional), but this component must never leave a
 * half-provisioned DEVICE silently once the user row exists.
 *
 * Constructor-free Livewire component (phpstan-strict-rules forbids a
 * constructor on a `Component` subclass) — every collaborator arrives as a
 * mount()/action-method parameter.
 */
final class MobileImportBootstrap extends Component
{
    /** Session key SignupAction stashes the plaintext recovery codes under. */
    private const RECOVERY_CODES_SESSION_KEY = 'auth.signup.recovery_codes_plain';

    private const MINIMUM_PASSWORD_LENGTH = 12;

    /** collect_pin|provisioning_failed|recovery_codes. */
    public string $step = 'collect_pin';

    public string $username = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $pin = '';

    public string $confirmPin = '';

    public string $flashMessage = '';

    /**
     * Collect the first-user credentials + PIN, provision the account +
     * app-lock + sync identity (no epoch mint), and advance to the
     * recovery-codes ceremony.
     */
    public function submit(
        SignupAction $signup,
        MobileLockGateway $lockGateway,
        PairingGateway $pairingGateway,
        Session $session,
        DatabaseManager $db,
    ): void {
        if ($this->password !== $this->passwordConfirmation) {
            $this->flashMessage = 'Passwords do not match.';
            $this->password = '';
            $this->passwordConfirmation = '';

            return;
        }

        if (strlen($this->password) < self::MINIMUM_PASSWORD_LENGTH) {
            $this->flashMessage = 'Use at least 12 characters.';
            $this->password = '';
            $this->passwordConfirmation = '';

            return;
        }

        if (strlen($this->pin) < 4) {
            $this->flashMessage = 'PIN must be at least 4 digits.';

            return;
        }

        if ($this->pin !== $this->confirmPin) {
            $this->flashMessage = "PINs don't match. Try again.";

            return;
        }

        try {
            $result = $signup->__invoke($this->username, $this->password);
        } catch (ValidationException $e) {
            $this->flashMessage = self::firstErrorMessage($e);
            $this->password = '';
            $this->passwordConfirmation = '';

            return;
        }

        $userId = $result['user']->id;

        // The plaintext PIN/password are needed by provisionDeviceLocally()
        // below and, on a retry, by retryProvisioning() — but must never
        // linger in the component's public wire snapshot once used.
        $pin = $this->pin;
        $accountPassword = $this->password;
        $this->pin = '';
        $this->confirmPin = '';
        $this->password = '';
        $this->passwordConfirmation = '';

        if (! $this->provisionDeviceLocally($userId, $pin, $accountPassword, $session, $lockGateway, $pairingGateway, $db)) {
            $this->step = 'provisioning_failed';

            return;
        }

        $this->step = 'recovery_codes';
    }

    /**
     * Idempotent-safe retry of step 2/3 ONLY — never re-runs SignupAction
     * (the account already exists; D-03's `User::count() === 0` gate would
     * reject a second signup attempt outright). Reached only from the
     * `provisioning_failed` step, for the ALREADY-authenticated user
     * SignupAction just logged in.
     */
    public function retryProvisioning(
        CurrentUser $currentUser,
        MobileLockGateway $lockGateway,
        PairingGateway $pairingGateway,
        Session $session,
        DatabaseManager $db,
    ): void {
        $userId = $currentUser->user()->id;

        if (! $this->provisionDeviceLocally($userId, $this->pin, $this->password, $session, $lockGateway, $pairingGateway, $db)) {
            $this->flashMessage = 'Still could not finish setting up this device. Please try again.';

            return;
        }

        $this->pin = '';
        $this->confirmPin = '';
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->flashMessage = '';
        $this->step = 'recovery_codes';
    }

    /**
     * Leave the recovery-codes ceremony and enter the pairing flow —
     * mirrors `RecoveryCodesDisplay::continueAfterSave()`'s session-forget
     * hygiene, redirecting into `mobile.pair?mode=import` instead of the
     * desktop dashboard.
     */
    public function continueToPairing(Session $session, UrlGenerator $urls): void
    {
        $session->forget(self::RECOVERY_CODES_SESSION_KEY);

        $this->redirect($urls->route('mobile.pair', ['mode' => 'import']), navigate: false);
    }

    public function render(ViewFactory $views, Session $session): View
    {
        $view = $views->make('mobile::livewire.mobile-import-bootstrap', [
            'codes' => $this->step === 'recovery_codes' ? $this->recoveryCodesFromSession($session) : [],
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Import from another device · beatrax']);

        return $view;
    }

    /**
     * Steps 2-3 of the provision sequence, made idempotent-safe via a bare
     * table read (not a new cross-module class) so a retry after a partial
     * failure never double-inserts a device_registry row or needlessly
     * re-rotates an already-minted app-lock data key. Returns false on any
     * failure (never throws onward) — the caller surfaces the retry step.
     */
    private function provisionDeviceLocally(
        int $userId,
        string $pin,
        string $accountPassword,
        Session $session,
        MobileLockGateway $lockGateway,
        PairingGateway $pairingGateway,
        DatabaseManager $db,
    ): bool {
        try {
            $lockAlreadyEnabled = (bool) ($db->connection()
                ->table('user_app_lock_configs')
                ->where('user_id', $userId)
                ->value('lock_enabled') ?? false);

            if (! $lockAlreadyEnabled) {
                $lockGateway->enableAppLock($userId, $pin, $accountPassword, $session);
            }

            $identityAlreadyExists = $db->connection()
                ->table('device_registry')
                ->where('user_id', $userId)
                ->where('is_self', 1)
                ->exists();

            if (! $identityAlreadyExists) {
                $pairingGateway->enableSyncIdentityWithoutEpoch($userId, $session);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function recoveryCodesFromSession(Session $session): array
    {
        /** @var list<string>|null $codes */
        $codes = $session->get(self::RECOVERY_CODES_SESSION_KEY);

        return $codes ?? [];
    }

    /**
     * Extracts the first message of the first failing field, mirroring
     * `Modules\Auth\Internal\Http\Livewire\SignupPage`'s identical helper.
     */
    private static function firstErrorMessage(ValidationException $exception): string
    {
        /** @var array<string, mixed> $errors */
        $errors = $exception->errors();

        foreach ($errors as $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }

        return 'Could not create the account.';
    }
}
