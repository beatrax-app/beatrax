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
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\ValidationMessages;
use Modules\Mobile\Internal\Identity\MobileProvisioningCredentials;
use Modules\Mobile\Internal\Identity\RecoveryCodesExportBridge;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Modules\Sync\Public\Services\PairingGateway;
use Throwable;

final class MobileImportBootstrap extends Component
{
    use HoldsFlashMessage;

    private const RECOVERY_CODES_SESSION_KEY = 'auth.signup.recovery_codes_plain';

    // Server-side only, for the lifetime of the provisioning_failed retry
    // window, and forgotten the moment provisioning succeeds.
    private const PENDING_CREDENTIALS_SESSION_KEY = 'mobile.import.pending_credentials';

    private const MINIMUM_PASSWORD_LENGTH = 12;

    public string $step = 'collect_pin';

    // True when this device already has an account: the signup form is then
    // an offer the user cannot take, so the view points at pairing instead.
    public bool $alreadyProvisioned = false;

    // `step` is a public property, so any fresh mount — a back navigation, or
    // the reload after an expired page — dropped an already-registered user
    // back on the signup form with no way forward.
    public function mount(CurrentUser $currentUser, Session $session): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        // Codes still in the session means signup just happened and the
        // one-time display is still owed.
        if ($this->recoveryCodesFromSession($session) !== []) {
            $this->step = 'recovery_codes';

            return;
        }

        // Flagged rather than redirected: a failed provisioning also leaves an
        // authenticated user with no codes, and its retry screen must stay
        // reachable.
        $this->alreadyProvisioned = true;
    }

    public string $username = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $pin = '';

    public string $confirmPin = '';

    public function submit(
        SignupAction $signup,
        MobileLockGateway $lockGateway,
        PairingGateway $pairingGateway,
        Session $session,
        DatabaseManager $db,
        MobileImportIntentGate $importIntent,
    ): void {
        if (($passwordError = $this->passwordError()) !== null) {
            $this->flashMessage = $passwordError;
            $this->password = '';
            $this->passwordConfirmation = '';

            return;
        }

        if (($pinError = $this->pinError()) !== null) {
            $this->flashMessage = $pinError;

            return;
        }

        try {
            // This screen JOINS a desktop's account: its starter rules arrive
            // over sync, and a local set would collide id-for-id with them.
            $userId = $signup->__invoke($this->username, $this->password, seedsStarterData: false)['user']->id;
        } catch (ValidationException $e) {
            $this->flashMessage = ValidationMessages::first($e, 'mobile::import.errors.account_failed');
            $this->password = '';
            $this->passwordConfirmation = '';

            return;
        }

        // A public Livewire property survives re-renders inside the serialized
        // wire:snapshot sent to the browser on every subsequent request, so
        // the plaintext PIN and password must not linger in one.
        $credentials = new MobileProvisioningCredentials($userId, $this->pin, $this->password);
        $this->pin = '';
        $this->confirmPin = '';
        $this->password = '';
        $this->passwordConfirmation = '';

        // Stash the credentials server-side (session, never rendered to
        // the client) so a genuine mid-flow failure after signup already
        // committed can still be retried with the same real credentials -
        // never the empty properties cleared immediately above.
        $session->put(self::PENDING_CREDENTIALS_SESSION_KEY, ['pin' => $credentials->pin, 'password' => $credentials->accountPassword]);

        if ($this->provisionDeviceLocally($credentials, $session, $lockGateway, $pairingGateway, $db, $importIntent)) {
            $session->forget(self::PENDING_CREDENTIALS_SESSION_KEY);
            $this->step = 'recovery_codes';
        } else {
            $this->step = 'provisioning_failed';
        }
    }

    private function passwordError(): ?string
    {
        return match (true) {
            $this->password !== $this->passwordConfirmation => Lang::get('mobile::import.errors.passwords_mismatch'),
            strlen($this->password) < self::MINIMUM_PASSWORD_LENGTH => Lang::get('mobile::import.errors.password_length'),
            default => null,
        };
    }

    private function pinError(): ?string
    {
        return match (true) {
            strlen($this->pin) < 6 => Lang::get('mobile::import.errors.pin_length'),
            $this->pin !== $this->confirmPin => Lang::get('mobile::import.errors.pins_mismatch'),
            default => null,
        };
    }

    // Idempotent-safe retry of the provisioning steps only - never
    // re-runs SignupAction (the account already exists). Reads the
    // originally submitted PIN/password from the server-side session
    // stash, never the emptied `$this->pin`/`$this->password` properties.
    public function retryProvisioning(
        CurrentUser $currentUser,
        MobileLockGateway $lockGateway,
        PairingGateway $pairingGateway,
        Session $session,
        DatabaseManager $db,
        MobileImportIntentGate $importIntent,
    ): void {
        $userId = $currentUser->user()->id;

        /** @var array{pin: string, password: string}|null $pending */
        $pending = $session->get(self::PENDING_CREDENTIALS_SESSION_KEY);

        if ($pending === null || $pending['pin'] === '' || $pending['password'] === '') {
            // The session copy is genuinely gone - never provision with an
            // empty PIN/password; the only safe recovery is a full
            // re-entry through collect_pin so the user re-types real
            // credentials.
            $this->flashMessage = Lang::get('mobile::import.errors.session_expired');
            $this->step = 'collect_pin';

            return;
        }

        $credentials = new MobileProvisioningCredentials($userId, $pending['pin'], $pending['password']);

        if (! $this->provisionDeviceLocally($credentials, $session, $lockGateway, $pairingGateway, $db, $importIntent)) {
            $this->flashMessage = Lang::get('mobile::import.errors.retry_failed');

            return;
        }

        $session->forget(self::PENDING_CREDENTIALS_SESSION_KEY);
        $this->pin = '';
        $this->confirmPin = '';
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->flashMessage = '';
        $this->step = 'recovery_codes';
    }

    // Leaves the recovery-codes ceremony and enters the pairing flow,
    // redirecting into mobile.pair?mode=import instead of the desktop
    // dashboard.
    public function continueToPairing(Session $session, UrlGenerator $urls): void
    {
        $session->forget(self::RECOVERY_CODES_SESSION_KEY);

        $this->redirect($urls->route('mobile.pair', ['mode' => 'import']), navigate: false);
    }

    public function render(
        ViewFactory $views,
        Session $session,
        UrlGenerator $urls,
        CurrentUser $currentUser,
        RecoveryCodeFormatter $formatter,
        RecoveryCodesExportBridge $exportBridge,
    ): View {
        // Only the recovery step has an authenticated user: every earlier step
        // renders before signup completes, so resolving the user up front
        // threw "No authenticated user is bound to the current guard".
        $showingCodes = $this->step === 'recovery_codes';

        $view = $views->make('mobile::livewire.mobile-import-bootstrap', [
            'codes' => $showingCodes ? $this->recoveryCodesFromSession($session) : [],
            // The browser saves the file itself and navigates by link, so the
            // one-shot recovery screen needs no Livewire round-trip at all —
            // on device those returned 419 and took the codes with them.
            'downloadFilename' => $showingCodes
                ? $formatter->filenameFor($currentUser->user()->username)
                : '',
            'pairingUrl' => $urls->route('mobile.pair', ['mode' => 'import']),
            // The Android webview drops a blob download without a word, so on
            // a phone the file goes out through the OS share sheet instead —
            // and the screen only claims to have saved it when that answers.
            'nativeExport' => $showingCodes && $exportBridge->isAvailable(),
            'exportUrl' => $urls->route('mobile.recovery-codes.export'),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('mobile::import.page_title').' · Beatrax']);

        return $view;
    }

    // Made idempotent-safe via a bare table read (not a new cross-module
    // class) so a retry after a partial failure never double-inserts a
    // device_registry row or needlessly re-rotates an already-minted
    // app-lock data key. Returns false on any failure, never throws.
    private function provisionDeviceLocally(
        MobileProvisioningCredentials $credentials,
        Session $session,
        MobileLockGateway $lockGateway,
        PairingGateway $pairingGateway,
        DatabaseManager $db,
        MobileImportIntentGate $importIntent,
    ): bool {
        $userId = $credentials->userId;
        $importIntent->markImporting($userId);

        try {
            $lockAlreadyEnabled = (bool) ($db->connection()
                ->table('user_app_lock_configs')
                ->where('user_id', $userId)
                ->value('lock_enabled') ?? false);

            if (! $lockAlreadyEnabled) {
                $lockGateway->enableAppLock($userId, $credentials->pin, $credentials->accountPassword, $session);
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
}
