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
use Modules\Core\Public\Services\UserDataPathService;
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

    // Written the moment the codes reach the screen, so a later mount can tell
    // a display still owed from a return trip to one already made.
    private const RECOVERY_CODES_SHOWN_SESSION_KEY = 'mobile.import.recovery_codes_shown';

    // Server-side only, for the lifetime of the provisioning_failed retry
    // window, and forgotten the moment provisioning succeeds.
    private const PENDING_CREDENTIALS_SESSION_KEY = 'mobile.import.pending_credentials';

    private const MINIMUM_PASSWORD_LENGTH = 12;

    private const MINIMUM_PIN_LENGTH = 6;

    // The five boxes on the form. SignupAction also rejects under `signup` when
    // the device gained an owner mid-submit, and that has no box to sit under,
    // so it stays on the form-level line.
    private const array FIELD_KEYS = ['username', 'password', 'passwordConfirmation', 'pin', 'confirmPin'];

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
        if ($this->recoveryCodesFromSession($session) !== [] && ! $this->recoveryCodesAlreadyShown($session)) {
            $this->step = 'recovery_codes';

            return;
        }

        // A mount that arrives after the display was made is a way back in —
        // cancelling out of pairing, above all — and that screen has promised
        // its codes are shown once. They are hashed at rest and the session
        // copy is encrypted, so repeating them breaks a promise, not a secret.
        $this->forgetRecoveryCodes($session);

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
        $this->resetErrorBag();
        $this->flashMessage = '';

        // Every broken rule at once, and none of the boxes emptied: a rejected
        // submit used to clear both password boxes, so a mistyped PIN cost a
        // 12-character passphrase retyped on a phone keyboard.
        if ($this->reportBrokenFieldRules()) {
            return;
        }

        try {
            // This screen JOINS a desktop's account: its starter rules arrive
            // over sync, and a local set would collide id-for-id with them.
            $userId = $signup->__invoke($this->username, $this->password, seedsStarterData: false)['user']->id;
        } catch (ValidationException $e) {
            $this->reportRejection($e);

            return;
        }

        // Handed to provisioning and then dropped from the properties: past
        // this point they are no longer what the reader is typing, and a public
        // property rides the serialized wire:snapshot to the browser on every
        // later render.
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

    // Field-scoped, so each message renders under the box it is about and that
    // control carries aria-invalid. One shared line reported the password rule
    // beneath the PIN's own "6-10 digits" hint, where the two read as a
    // contradiction — and said nothing at all about an empty username.
    private function reportBrokenFieldRules(): bool
    {
        $broken = [];

        if ($this->username === '') {
            $broken['username'] = Lang::get('mobile::import.errors.username_required');
        }

        if (strlen($this->password) < self::MINIMUM_PASSWORD_LENGTH) {
            $broken['password'] = Lang::get('mobile::import.errors.password_length');
        }

        if ($this->password !== $this->passwordConfirmation) {
            $broken['passwordConfirmation'] = Lang::get('mobile::import.errors.passwords_mismatch');
        }

        if (strlen($this->pin) < self::MINIMUM_PIN_LENGTH) {
            $broken['pin'] = Lang::get('mobile::import.errors.pin_length');
        }

        if ($this->pin !== $this->confirmPin) {
            $broken['confirmPin'] = Lang::get('mobile::import.errors.pins_mismatch');
        }

        foreach ($broken as $field => $message) {
            $this->addError($field, $message);
        }

        return $broken !== [];
    }

    private function reportRejection(ValidationException $exception): void
    {
        $placed = false;
        $errors = $exception->validator->errors()->messages();

        foreach (self::FIELD_KEYS as $field) {
            foreach ($errors[$field] ?? [] as $message) {
                $this->addError($field, $message);
                $placed = true;
            }
        }

        if (! $placed) {
            $this->flashMessage = ValidationMessages::first($exception, 'mobile::import.errors.account_failed');
        }
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
        $this->forgetRecoveryCodes($session);

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

        // Recorded here because the render IS the display: the way off this
        // step is a plain link, so no later server call reports that the codes
        // were seen, and mount() would otherwise re-enter the step on the way
        // back and show them a second time.
        if ($showingCodes) {
            $session->put(self::RECOVERY_CODES_SHOWN_SESSION_KEY, true);
        }

        $view = $views->make('mobile::livewire.mobile-import-bootstrap', [
            'codes' => $showingCodes ? $this->recoveryCodesFromSession($session) : [],
            // The browser saves the file itself and navigates by link, so the
            // one-shot recovery screen needs no Livewire round-trip at all —
            // on device those returned 419 and took the codes with them.
            'downloadFilename' => $showingCodes
                ? $formatter->filenameFor($currentUser->user()->username)
                : '',
            'pairingUrl' => $urls->route('mobile.pair', ['mode' => 'import']),
            // The Android webview drops a blob download without a word, so
            // there the endpoint keeps a copy and the screen says so. A shell
            // that saves the download hands the file to the reader instead, so
            // it keeps the blob and is never sent to the endpoint.
            'nativeExport' => $showingCodes
                && $exportBridge->isAvailable()
                && UserDataPathService::platform()?->savesWebViewDownloads() !== true,
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

    private function recoveryCodesAlreadyShown(Session $session): bool
    {
        return $session->get(self::RECOVERY_CODES_SHOWN_SESSION_KEY) === true;
    }

    private function forgetRecoveryCodes(Session $session): void
    {
        $session->forget([self::RECOVERY_CODES_SESSION_KEY, self::RECOVERY_CODES_SHOWN_SESSION_KEY]);
    }
}
