<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Public\Services\SecretsWriteFailed;

/**
 * Guided onboarding wizard for the Open Banking connector (D-10/Req 12),
 * mirroring `Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal`'s
 * numbered-step chrome and action-method-DI discipline.
 *
 * Six steps:
 *  1. Generate a local RSA keypair (`generateKeypair()`). The private key
 *     is written straight to the chmod-600 secrets file and is NEVER
 *     assigned to a public property — only the public key (safe to
 *     display/copy) lives on the component, so the private PEM can never
 *     round-trip into a `wire:snapshot` payload sent to the browser.
 *  2. Informational — register the application in the Enable Banking
 *     portal using the public key + redirect URI from Step 1.
 *  3. Paste back the `application_id` (`saveApplicationId()`), persisted
 *     alongside the already-saved private key.
 *  4. Choose the bank: ASN Bank / SNS (de Volksbank) radio tiles, or a
 *     free-text "Other institution" fallback (`chooseBank()`) — the
 *     institution id is chosen here, at consent time, never hardcoded.
 *  5. Hand off to the consent/SCA dance (`connect()`), redirecting to
 *     `oauth.open-banking.connect` with the chosen `institution_id`.
 *  6. "Done" / error states render from the flash values the callback
 *     controller sets on `route('settings')` — out of this plan's scope
 *     (Wave 3 mounts this modal + reacts to those flashes).
 *
 * Service collaborators arrive as parameters on action methods + the
 * render() method — constructor injection is banned on Livewire
 * components by the strict-rules plugin (verbatim rule this class
 * mirrors from OAuthClientWizardModal).
 */
final class OpenBankingWizardModal extends Component
{
    private const STEP_KEYPAIR = 1;

    private const STEP_REGISTER = 2;

    private const STEP_APPLICATION_ID = 3;

    private const STEP_BANK = 4;

    private const STEP_CONSENT = 5;

    private const INSTITUTION_ASN_ID = 'ASNBNL21';

    private const INSTITUTION_ASN_NAME = 'ASN Bank';

    private const INSTITUTION_SNS_ID = 'SNSBNL21';

    private const INSTITUTION_SNS_NAME = 'SNS (de Volksbank)';

    public int $step = self::STEP_KEYPAIR;

    /**
     * The generated PUBLIC key only — safe to display, copy, and let
     * round-trip through the Livewire snapshot. The matching private key
     * is written directly to disk inside generateKeypair() and never
     * touches a property on this class.
     */
    public string $publicKeyPem = '';

    public string $applicationId = '';

    /** One of '' | 'asn' | 'sns' | 'other'. */
    public string $bankChoice = '';

    public string $otherInstitutionId = '';

    public string $errorMessage = '';

    /**
     * Opens the wizard. Called with no arguments for the FIRST-time
     * onboarding flow (19-11's `confirmWarning()`) — always resets to
     * Step 1. Called with `$startStep`/`$bankChoice`/`$otherInstitutionId`
     * for the RECONNECT flow (19-11's `reconnect()`, UI-SPEC Surface B5):
     * skips straight to the bank-picker step, reusing the already-
     * registered application.
     *
     * 19-07's Wave 1 review flagged that always resetting to Step 1 would
     * silently overwrite an already-generated RSA private key + wipe
     * `application_id` if this method were ever reached by a reconnect
     * while a full registration already existed — confirmed unreachable
     * at the time because the wizard was not yet mounted on any page.
     * Now that 19-11 mounts it, `$startStep` is honored ONLY when
     * `hasApplication()` is true (a defensive re-check, not just trusting
     * the caller) — a reconnect can never regenerate a keypair, and a
     * missing/incomplete registration always falls back to Step 1
     * regardless of what the caller requested.
     */
    #[On('open-banking-wizard:open')]
    public function open(
        OpenBankingSecretsRepository $secrets,
        ?int $startStep = null,
        string $bankChoice = '',
        string $otherInstitutionId = '',
    ): void {
        $canSkipToRequestedStep = $startStep !== null && $secrets->hasApplication();

        $this->step = $canSkipToRequestedStep ? $startStep : self::STEP_KEYPAIR;
        $this->publicKeyPem = '';
        $this->applicationId = '';
        $this->bankChoice = $canSkipToRequestedStep ? $bankChoice : '';
        $this->otherInstitutionId = $canSkipToRequestedStep ? $otherInstitutionId : '';
        $this->errorMessage = '';

        $this->dispatch('modal-show', name: 'open-banking-wizard');
    }

    /**
     * Step 1 action. Generates a fresh 2048-bit RSA keypair locally,
     * writes the private key to the secrets file immediately (with an
     * empty application_id placeholder — Step 3 fills that in), and
     * reveals only the public key on the component.
     */
    public function generateKeypair(OpenBankingSecretsRepository $secrets): void
    {
        $this->errorMessage = '';

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            $this->errorMessage = 'Could not generate a key pair on this machine — check your OpenSSL configuration.';

            return;
        }

        $exported = openssl_pkey_export($resource, $privateKeyPem);
        if (! $exported || ! is_string($privateKeyPem) || $privateKeyPem === '') {
            $this->errorMessage = 'Could not export the generated key pair.';

            return;
        }

        $details = openssl_pkey_get_details($resource);
        $publicKeyPem = $details === false ? null : ($details['key'] ?? null);
        if (! is_string($publicKeyPem) || $publicKeyPem === '') {
            $this->errorMessage = 'Could not read the generated public key.';

            return;
        }

        try {
            $secrets->save(new OpenBankingCredentials(
                applicationId: '',
                privateKeyPem: $privateKeyPem,
                sessionId: null,
                consentExpiresAt: null,
                bankScaHost: null,
                institutionId: null,
            ));
        } catch (SecretsWriteFailed) {
            $this->errorMessage = 'Could not save your key pair to disk — check your secrets-directory permissions and try again.';

            return;
        } finally {
            // $privateKeyPem lives only as a local variable on this
            // request — it is NEVER assigned to a public property, so it
            // cannot appear in the wire:snapshot payload the browser
            // receives. Clearing the local is defence-in-depth on top of
            // that structural guarantee.
            $privateKeyPem = null;
        }

        $this->publicKeyPem = $publicKeyPem;
        $this->step = self::STEP_REGISTER;
    }

    /** Step 2 -> Step 3. Purely informational step, no external call. */
    public function continueToApplicationId(): void
    {
        if ($this->publicKeyPem === '') {
            $this->errorMessage = 'Generate a key pair before continuing.';

            return;
        }

        $this->errorMessage = '';
        $this->step = self::STEP_APPLICATION_ID;
    }

    /**
     * Step 3 action. Persists the pasted application_id alongside the
     * private key generateKeypair() already wrote — application_id is
     * not secret (an identifier the user pastes, comparable to
     * OAuthClientWizardModal's clientId), so it is not wiped from the
     * component afterwards.
     */
    public function saveApplicationId(OpenBankingSecretsRepository $secrets): void
    {
        $this->errorMessage = '';

        $applicationId = trim($this->applicationId);
        if ($applicationId === '') {
            $this->errorMessage = 'Paste the application ID from the Enable Banking portal before continuing.';

            return;
        }

        $existing = $secrets->load();
        if ($existing === null) {
            $this->errorMessage = 'Generate a key pair before continuing.';
            $this->step = self::STEP_KEYPAIR;

            return;
        }

        try {
            $secrets->save(new OpenBankingCredentials(
                applicationId: $applicationId,
                privateKeyPem: $existing->privateKeyPem,
                sessionId: $existing->sessionId,
                consentExpiresAt: $existing->consentExpiresAt,
                bankScaHost: $existing->bankScaHost,
                institutionId: $existing->institutionId,
            ));
        } catch (SecretsWriteFailed) {
            $this->errorMessage = 'Could not save your application ID to disk — check your secrets-directory permissions and try again.';

            return;
        }

        $this->applicationId = $applicationId;
        $this->step = self::STEP_BANK;
    }

    /** Step 4 tile-pick action. */
    public function chooseBank(string $bank): void
    {
        $this->bankChoice = in_array($bank, ['asn', 'sns', 'other'], strict: true) ? $bank : '';
    }

    /** Step 4 -> Step 5. */
    public function continueToConsent(): void
    {
        $this->errorMessage = '';

        if ($this->resolveInstitutionId() === null) {
            $this->errorMessage = 'Choose a bank before continuing.';

            return;
        }

        $this->step = self::STEP_CONSENT;
    }

    /**
     * Step 5 action. Hands off to the consent/SCA dance built in 19-05
     * (`oauth.open-banking.connect`), carrying the user-chosen
     * institution id (Req 12 — never hardcoded).
     */
    public function connect(): mixed
    {
        $institutionId = $this->resolveInstitutionId();
        if ($institutionId === null) {
            $this->errorMessage = 'Choose a bank before continuing.';
            $this->step = self::STEP_BANK;

            return null;
        }

        $this->dispatch('modal-close', name: 'open-banking-wizard');

        return $this->redirectRoute('oauth.open-banking.connect', ['institution_id' => $institutionId]);
    }

    /**
     * Cancel at any step. Discards a partially-generated keypair (Step 1
     * wrote a private-key-only entry with no application_id yet) so
     * aborting the wizard never leaves an orphaned secrets-file entry
     * (T-19-06-04). A fully-registered application — `hasApplication()`
     * true, e.g. a reconnect flow that skipped straight to Step 4 reusing
     * the existing registration — is left untouched.
     *
     * D-16 Wave 3 review-and-fix gate (19-14) hardening: also forgets the
     * `open_banking_acknowledged` session flag `OpenBankingSettingsPage::
     * confirmWarning()` sets before dispatching this wizard's open event.
     * Without this, cancelling mid-wizard (checkbox already confirmed,
     * consent dance never completed) would leave that flag standing —
     * `enableOpenBanking()`'s own TTL is the second, bounded-time backstop
     * for the same gap, but an explicit cancel should close it
     * immediately rather than only after the TTL lapses.
     */
    public function cancel(OpenBankingSecretsRepository $secrets, Session $session): void
    {
        if (! $secrets->hasApplication()) {
            $secrets->clear();
        }

        $session->forget('open_banking_acknowledged');

        $this->dispatch('modal-close', name: 'open-banking-wizard');
    }

    public function render(ViewFactory $views, LoopbackRedirectUri $loopback): View
    {
        return $views->make('openbanking::livewire.open-banking-wizard-modal', [
            'redirectUri' => $loopback->forProvider('open-banking', scheme: 'https'),
            'bankName' => $this->bankDisplayName(),
        ]);
    }

    private function resolveInstitutionId(): ?string
    {
        return match ($this->bankChoice) {
            'asn' => self::INSTITUTION_ASN_ID,
            'sns' => self::INSTITUTION_SNS_ID,
            'other' => $this->otherInstitutionIdOrNull(),
            default => null,
        };
    }

    private function otherInstitutionIdOrNull(): ?string
    {
        $trimmed = trim($this->otherInstitutionId);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function bankDisplayName(): string
    {
        return match ($this->bankChoice) {
            'asn' => self::INSTITUTION_ASN_NAME,
            'sns' => self::INSTITUTION_SNS_NAME,
            'other' => $this->otherInstitutionIdOrNull() ?? 'your bank',
            default => 'your bank',
        };
    }
}
