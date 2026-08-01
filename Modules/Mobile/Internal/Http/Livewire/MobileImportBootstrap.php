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
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Identity\MobileProvisioningCredentials;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Modules\Sync\Public\Services\PairingGateway;
use Throwable;

/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class MobileImportBootstrap extends Component
{
    private const RECOVERY_CODES_SESSION_KEY = 'auth.signup.recovery_codes_plain';

    // Session key the originally submitted PIN/password are stashed
    // under for the lifetime of the provisioning_failed retry window -
    // server-side only, never sent to the client. Forgotten the moment
    // provisionDeviceLocally() actually succeeds.
    private const PENDING_CREDENTIALS_SESSION_KEY = 'mobile.import.pending_credentials';

    private const MINIMUM_PASSWORD_LENGTH = 12;

    public string $step = 'collect_pin';

    public string $username = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $pin = '';

    public string $confirmPin = '';

    public string $flashMessage = '';

    // Collects the first-user credentials + PIN, provisions the account
    // + app-lock + sync identity (no epoch mint), and advances to the
    // recovery-codes ceremony.
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
            $userId = $signup->__invoke($this->username, $this->password)['user']->id;
        } catch (ValidationException $e) {
            $this->flashMessage = self::firstErrorMessage($e);
            $this->password = '';
            $this->passwordConfirmation = '';

            return;
        }

        // The plaintext PIN/password must never linger in the component's
        // public wire snapshot once used - a public Livewire property
        // survives re-renders inside the serialized wire:snapshot payload
        // sent to the browser on every subsequent request.
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
            strlen($this->pin) < 4 => Lang::get('mobile::import.errors.pin_length'),
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

    public function render(ViewFactory $views, Session $session): View
    {
        $view = $views->make('mobile::livewire.mobile-import-bootstrap', [
            'codes' => $this->step === 'recovery_codes' ? $this->recoveryCodesFromSession($session) : [],
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('mobile::import.page_title').' · beatrax']);

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

        return Lang::get('mobile::import.errors.account_failed');
    }
}
