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

        // The plaintext PIN/password must never linger in the component's
        // public wire snapshot once used - a public Livewire property
        // survives re-renders inside the serialized wire:snapshot payload
        // sent to the browser on every subsequent request.
        $pin = $this->pin;
        $accountPassword = $this->password;
        $this->pin = '';
        $this->confirmPin = '';
        $this->password = '';
        $this->passwordConfirmation = '';

        // Stash the credentials server-side (session, never rendered to
        // the client) so a genuine mid-flow failure after signup already
        // committed can still be retried with the same real credentials -
        // never the empty properties cleared immediately above.
        $session->put(self::PENDING_CREDENTIALS_SESSION_KEY, ['pin' => $pin, 'password' => $accountPassword]);

        if (! $this->provisionDeviceLocally($userId, $pin, $accountPassword, $session, $lockGateway, $pairingGateway, $db, $importIntent)) {
            $this->step = 'provisioning_failed';

            return;
        }

        $session->forget(self::PENDING_CREDENTIALS_SESSION_KEY);
        $this->step = 'recovery_codes';
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
            $this->flashMessage = 'Your session expired before setup finished. Please re-enter your PIN and password.';
            $this->step = 'collect_pin';

            return;
        }

        if (! $this->provisionDeviceLocally($userId, $pending['pin'], $pending['password'], $session, $lockGateway, $pairingGateway, $db, $importIntent)) {
            $this->flashMessage = 'Still could not finish setting up this device. Please try again.';

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
        $view->extends('layouts.app', ['title' => 'Import from another device · beatrax']);

        return $view;
    }

    // Made idempotent-safe via a bare table read (not a new cross-module
    // class) so a retry after a partial failure never double-inserts a
    // device_registry row or needlessly re-rotates an already-minted
    // app-lock data key. Returns false on any failure, never throws.
    private function provisionDeviceLocally(
        int $userId,
        string $pin,
        string $accountPassword,
        Session $session,
        MobileLockGateway $lockGateway,
        PairingGateway $pairingGateway,
        DatabaseManager $db,
        MobileImportIntentGate $importIntent,
    ): bool {
        $importIntent->markImporting($userId);

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
