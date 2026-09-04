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
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Auth\Public\Recovery\PendingRecoveryCodes;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\AnnouncesStepChanges;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Http\Livewire\Concerns\ReportsFieldRejections;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\PairingEntryUrl;
use Modules\Mobile\Internal\Identity\DeviceProvisioningOutcome;
use Modules\Mobile\Internal\Identity\ImportBootstrapStep;
use Modules\Mobile\Internal\Identity\MobileProvisioningCredentials;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Modules\Sync\Public\Services\PairingGateway;
use Throwable;

final class MobileImportBootstrap extends Component
{
    use AnnouncesStepChanges;
    use HoldsFlashMessage;
    use ReportsFieldRejections;

    // Written the moment the codes reach the screen, so a later mount can tell
    // a display still owed from a return trip to one already made.
    private const string RECOVERY_CODES_SHOWN_SESSION_KEY = 'mobile.import.recovery_codes_shown';

    // Server-side only, for the lifetime of the provisioning_failed retry
    // window, and forgotten the moment provisioning succeeds.
    private const string PENDING_CREDENTIALS_SESSION_KEY = 'mobile.import.pending_credentials';

    // The provisioner's own floor, restated here because this screen gates
    // ahead of it and the account is committed before the floor is reached: a
    // gate that admits what the floor refuses strands the device it just made.
    private const int MINIMUM_PIN_LENGTH = 6;

    private const int MAXIMUM_PIN_LENGTH = 10;

    // The five credential boxes, read by ReportsFieldRejections rather than
    // by anything here. SignupAction also rejects under `signup` when the
    // device gained an owner mid-submit, and that has no box to sit under, so
    // it stays on the form-level line.
    protected const array FIELD_KEYS = ['username', 'password', 'passwordConfirmation', 'pin', 'confirmPin'];

    // Stays a string on the wire: a public property is rehydrated straight from
    // the client payload with no enum coercion, so typing it would turn a
    // crafted step into a 500. currentStep() is the enum every reader uses.
    public string $step = ImportBootstrapStep::CollectPin->value;

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

        // The stash outlives only an unfinished provisioning, so a reload here
        // is a return to the failure, not a way past it: walking on to the
        // codes handed the reader a device with no lock and no sync identity,
        // whose only remaining screen was "abandon import".
        if ($session->get(self::PENDING_CREDENTIALS_SESSION_KEY) !== null) {
            $this->step = ImportBootstrapStep::ProvisioningFailed->value;

            return;
        }

        // Codes still in the session means signup just happened and the
        // one-time display is still owed.
        if ($this->recoveryCodesFromSession($session) !== [] && ! $this->recoveryCodesAlreadyShown($session)) {
            $this->step = ImportBootstrapStep::RecoveryCodes->value;

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

    // The second way an account is made, and the country is asked of every
    // joiner rather than synced, so one left unset here stays unset. Not
    // choosing is still a real answer: it widens classification to every
    // region rather than pinning the device to a guessed one.
    public string $country = '';

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
            $userId = $signup->__invoke(
                $this->username,
                $this->password,
                seedsStarterData: false,
                countryCode: $this->country,
            )['user']->id;
        } catch (ValidationException $e) {
            $this->reportRejection($e, 'mobile::import.errors.account_failed');

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

        $outcome = $this->provisionDeviceLocally($credentials, $session, $lockGateway, $pairingGateway, $db, $importIntent);

        if ($outcome === DeviceProvisioningOutcome::Succeeded) {
            $session->forget(self::PENDING_CREDENTIALS_SESSION_KEY);
            $this->moveTo(ImportBootstrapStep::RecoveryCodes);

            return;
        }

        // A credential the floor below refuses is refused again on every replay
        // of the same stash, so that screen must name the rule rather than
        // offer a retry that is arithmetic on a fixed answer.
        if ($outcome === DeviceProvisioningOutcome::CredentialsRejected) {
            $this->flashMessage = Lang::get('mobile::import.errors.pin_digits');
        }

        $this->moveTo(ImportBootstrapStep::ProvisioningFailed);
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

        if (strlen($this->password) < PasswordPolicy::MINIMUM_LENGTH) {
            $broken['password'] = Lang::get('mobile::import.errors.password_length');
        }

        if ($this->password !== $this->passwordConfirmation) {
            $broken['passwordConfirmation'] = Lang::get('mobile::import.errors.passwords_mismatch');
        }

        $pinRule = $this->brokenPinRule($this->pin);

        if ($pinRule !== null) {
            $broken['pin'] = $pinRule;
        }

        if ($this->pin !== $this->confirmPin) {
            $broken['confirmPin'] = Lang::get('mobile::import.errors.pins_mismatch');
        }

        foreach ($broken as $field => $message) {
            $this->addError($field, $message);
        }

        return $broken !== [];
    }

    // Too-short is told apart from the rest so a reader who typed four digits
    // is not handed a rule about the alphabet. Everything else — eleven digits,
    // a letter, a space — is one answer, because the keypad types none of them.
    private function brokenPinRule(string $pin): ?string
    {
        if (mb_strlen($pin) < self::MINIMUM_PIN_LENGTH) {
            return Lang::get('mobile::import.errors.pin_length');
        }

        return preg_match('/^[0-9]{'.self::MINIMUM_PIN_LENGTH.','.self::MAXIMUM_PIN_LENGTH.'}$/', $pin) === 1
            ? null
            : Lang::get('mobile::import.errors.pin_digits');
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
        // The same guard mount() opens with, because this screen is reachable
        // outside the auth group and a `calls` payload names a method directly:
        // nothing between the route and here has asked for a reader yet.
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $userId = $currentUser->user()->id;

        /** @var array{pin: string, password: string}|null $pending */
        $pending = $session->get(self::PENDING_CREDENTIALS_SESSION_KEY);

        if ($pending === null || $pending['pin'] === '' || $pending['password'] === '') {
            // The session copy is genuinely gone - never provision with an
            // empty PIN/password; the only safe recovery is a full
            // re-entry through collect_pin so the user re-types real
            // credentials.
            $this->flashMessage = Lang::get('mobile::import.errors.session_expired');
            $this->moveTo(ImportBootstrapStep::CollectPin);

            return;
        }

        $credentials = new MobileProvisioningCredentials($userId, $pending['pin'], $pending['password']);

        $outcome = $this->provisionDeviceLocally($credentials, $session, $lockGateway, $pairingGateway, $db, $importIntent);

        if ($outcome !== DeviceProvisioningOutcome::Succeeded) {
            $this->flashMessage = $outcome === DeviceProvisioningOutcome::CredentialsRejected
                ? Lang::get('mobile::import.errors.pin_digits')
                : Lang::get('mobile::import.errors.retry_failed');

            return;
        }

        $session->forget(self::PENDING_CREDENTIALS_SESSION_KEY);
        $this->pin = '';
        $this->confirmPin = '';
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->flashMessage = '';
        $this->moveTo(ImportBootstrapStep::RecoveryCodes);
    }

    // The one way a step changes without the page being reloaded, so the
    // announcement cannot be left off a later branch. mount() sets the
    // property directly and deliberately: that IS a page load, which starts
    // at the top on its own and carries its own restored offset.
    private function moveTo(ImportBootstrapStep $step): void
    {
        $this->step = $step->value;
        $this->announceStepChange();
    }

    private function currentStep(): ImportBootstrapStep
    {
        return ImportBootstrapStep::tryFrom($this->step) ?? ImportBootstrapStep::CollectPin;
    }

    public function render(
        ViewFactory $views,
        Session $session,
        UrlGenerator $urls,
        CurrentUser $currentUser,
        RecoveryCodeFormatter $formatter,
        ShareSheetExport $exportBridge,
        UserCountry $countries,
    ): View {
        // Only the recovery step has an authenticated user: every earlier step
        // renders before signup completes, so resolving the user up front
        // threw "No authenticated user is bound to the current guard".
        $showingCodes = $this->currentStep() === ImportBootstrapStep::RecoveryCodes;

        // Recorded here because the render IS the display: the way off this
        // step is a plain link, so no later server call reports that the codes
        // were seen, and mount() would otherwise re-enter the step on the way
        // back and show them a second time.
        if ($showingCodes) {
            $session->put(self::RECOVERY_CODES_SHOWN_SESSION_KEY, true);
            PendingRecoveryCodes::renew($session);
        }

        $view = $views->make('mobile::livewire.mobile-import-bootstrap', [
            // Under its own name, not $step: view data cannot shadow a public
            // property, and the view needs the resolved enum rather than the
            // raw string the client last put on the wire.
            'bootstrapStep' => $this->currentStep(),
            'countryOptions' => $countries->options(),
            'codes' => $showingCodes ? $this->recoveryCodesFromSession($session) : [],
            // The browser saves the file itself and navigates by link, so the
            // one-shot recovery screen needs no Livewire round-trip at all —
            // on device those returned 419 and took the codes with them.
            'downloadFilename' => $showingCodes
                ? $formatter->filenameFor($currentUser->user()->username)
                : '',
            'pairingUrl' => PairingEntryUrl::importingFrom($urls),
            // The Android webview drops a blob download without a word, so
            // there the endpoint keeps a copy and the screen says so. A shell
            // that saves the download hands the file to the reader instead, so
            // it keeps the blob and is never sent to the endpoint.
            'nativeExport' => $showingCodes && $exportBridge->replacesWebViewDownload(),
            'exportUrl' => $urls->route('mobile.recovery-codes.export'),
        ]);

        $view->extends('layouts.app', ['title' => Lang::get('mobile::import.page_title').Brand::TITLE_SUFFIX]);

        return $view;
    }

    // Made idempotent-safe via a bare table read (not a new cross-module
    // class) so a retry after a partial failure never double-inserts a
    // device_registry row or needlessly re-rotates an already-minted
    // app-lock data key. Never throws: the answer is in the return.
    private function provisionDeviceLocally(
        MobileProvisioningCredentials $credentials,
        Session $session,
        MobileLockGateway $lockGateway,
        PairingGateway $pairingGateway,
        DatabaseManager $db,
        MobileImportIntentGate $importIntent,
    ): DeviceProvisioningOutcome {
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

            return DeviceProvisioningOutcome::Succeeded;
        } catch (ValidationException) {
            return DeviceProvisioningOutcome::CredentialsRejected;
        } catch (Throwable) {
            return DeviceProvisioningOutcome::Failed;
        }
    }

    /**
     * @return list<string>
     */
    private function recoveryCodesFromSession(Session $session): array
    {
        return PendingRecoveryCodes::read($session);
    }

    private function recoveryCodesAlreadyShown(Session $session): bool
    {
        return $session->get(self::RECOVERY_CODES_SHOWN_SESSION_KEY) === true;
    }

    private function forgetRecoveryCodes(Session $session): void
    {
        PendingRecoveryCodes::forget($session);
        $session->forget(self::RECOVERY_CODES_SHOWN_SESSION_KEY);
    }
}
