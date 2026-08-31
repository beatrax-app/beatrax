<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Enums\BankChoice;
use Modules\OpenBanking\Internal\Enums\CuratedInstitution;
use Modules\OpenBanking\Internal\Enums\WizardStep;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Services\SecretsWriteFailed;

final class OpenBankingWizardModal extends Component
{
    public int $step = WizardStep::Keypair->value;

    // The public half only: the private key goes straight to disk inside
    // generateKeypair() and never reaches a property, hence never the snapshot.
    public string $publicKeyPem = '';

    public string $applicationId = '';

    public string $bankChoice = '';

    public string $otherInstitutionId = '';

    public string $errorMessage = '';

    #[On('open-banking-wizard:open')]
    public function open(
        OpenBankingSecretsRepository $secrets,
        ?int $startStep = null,
        string $bankChoice = '',
        string $otherInstitutionId = '',
    ): void {
        // A step number outside the enum is not honoured at all: the modal
        // renders one branch per step, so an unknown one draws a dialog with no
        // controls and no way out of it.
        $requestedStep = WizardStep::requested($startStep);
        $canSkipToRequestedStep = $requestedStep !== null && $secrets->hasApplication();

        $this->step = ($canSkipToRequestedStep ? $requestedStep : WizardStep::Keypair)->value;
        $this->publicKeyPem = '';
        $this->applicationId = '';
        $this->bankChoice = $canSkipToRequestedStep ? $bankChoice : '';
        $this->otherInstitutionId = $canSkipToRequestedStep ? $otherInstitutionId : '';
        $this->errorMessage = '';

        $this->dispatch('modal-show', name: 'open-banking-wizard');
    }

    // The private key is written to the secrets file immediately, with an empty
    // application_id placeholder that step 3 fills in.
    public function generateKeypair(OpenBankingSecretsRepository $secrets): void
    {
        $this->errorMessage = '';

        $keypair = $this->freshRsaKeypair();
        if (is_string($keypair)) {
            $this->errorMessage = $keypair;

            return;
        }

        [$privateKeyPem, $publicKeyPem] = $keypair;

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
            $this->errorMessage = Lang::get('openbanking::messages.wizard.errors.save_keypair_failed');

            return;
        } finally {
            // $privateKeyPem is a local only, so it cannot reach wire:snapshot;
            // clearing it is defence-in-depth.
            $privateKeyPem = null;
        }

        $this->publicKeyPem = $publicKeyPem;
        $this->step = WizardStep::Register->value;
    }

    /**
     * @return array{0: string, 1: string}|string the private + public PEM pair,
     *                                            or a user-facing error message when a generation step fails
     */
    private function freshRsaKeypair(): array|string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            return Lang::get('openbanking::messages.wizard.errors.generate_failed');
        }

        $exported = openssl_pkey_export($resource, $privateKeyPem);
        if (! $exported || ! is_string($privateKeyPem) || $privateKeyPem === '') {
            return Lang::get('openbanking::messages.wizard.errors.export_failed');
        }

        $details = openssl_pkey_get_details($resource);
        $publicKeyPem = $details === false ? null : ($details['key'] ?? null);

        return is_string($publicKeyPem) && $publicKeyPem !== ''
            ? [$privateKeyPem, $publicKeyPem]
            : Lang::get('openbanking::messages.wizard.errors.read_public_failed');
    }

    public function continueToApplicationId(): void
    {
        if ($this->publicKeyPem === '') {
            $this->errorMessage = Lang::get('openbanking::messages.wizard.errors.generate_first');

            return;
        }

        $this->errorMessage = '';
        $this->step = WizardStep::ApplicationId->value;
    }

    public function saveApplicationId(OpenBankingSecretsRepository $secrets): void
    {
        $this->errorMessage = '';

        $applicationId = trim($this->applicationId);
        if ($applicationId === '') {
            $this->errorMessage = Lang::get('openbanking::messages.wizard.errors.paste_application_id');

            return;
        }

        $existing = $secrets->load();
        if ($existing === null) {
            $this->errorMessage = Lang::get('openbanking::messages.wizard.errors.generate_first');
            $this->step = WizardStep::Keypair->value;

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
            $this->errorMessage = Lang::get('openbanking::messages.wizard.errors.save_application_id_failed');

            return;
        }

        $this->applicationId = $applicationId;
        $this->step = WizardStep::Bank->value;
    }

    public function chooseBank(string $bank): void
    {
        $this->bankChoice = BankChoice::tryFrom($bank)->value ?? '';
    }

    public function continueToConsent(): void
    {
        $this->errorMessage = '';

        if ($this->resolveInstitutionId() === null) {
            $this->errorMessage = Lang::get('openbanking::messages.wizard.errors.choose_bank');

            return;
        }

        $this->step = WizardStep::Consent->value;
    }

    public function connect(): mixed
    {
        $institutionId = $this->resolveInstitutionId();
        if ($institutionId === null) {
            $this->errorMessage = Lang::get('openbanking::messages.wizard.errors.choose_bank');
            $this->step = WizardStep::Bank->value;

            return null;
        }

        $this->dispatch('modal-close', name: 'open-banking-wizard');

        return $this->redirectRoute('oauth.open-banking.connect', ['institution_id' => $institutionId]);
    }

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
            // Exposed so the Step 5 same-tab link's no-JS/middle-click href
            // fallback can carry institution_id.
            'consentInstitutionId' => $this->resolveInstitutionId(),
        ]);
    }

    private function resolveInstitutionId(): ?string
    {
        $choice = BankChoice::tryFrom($this->bankChoice);
        if ($choice === null) {
            return null;
        }

        return $choice->institution()->value ?? $this->otherInstitutionIdOrNull();
    }

    private function otherInstitutionIdOrNull(): ?string
    {
        $trimmed = trim($this->otherInstitutionId);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function bankDisplayName(): string
    {
        $choice = BankChoice::tryFrom($this->bankChoice);
        $curated = $choice?->institution();
        if ($curated instanceof CuratedInstitution) {
            return $curated->displayName();
        }

        $typed = $choice === BankChoice::Other ? $this->otherInstitutionIdOrNull() : null;

        return $typed ?? Lang::get('openbanking::messages.wizard.your_bank');
    }
}
