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
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Public\Services\SecretsWriteFailed;

/**
 * @link ../../../../../.docs/features/open-banking/architecture.md
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

    // The generated public key only, safe to display/copy/round-trip
    // through the Livewire snapshot — the matching private key is written
    // directly to disk inside generateKeypair() and never touches a
    // property on this class.
    public string $publicKeyPem = '';

    public string $applicationId = '';

    public string $bankChoice = '';

    public string $otherInstitutionId = '';

    public string $errorMessage = '';

    /**
     * @link ../../../../../.docs/features/open-banking/architecture.md
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

    // Generates a fresh 2048-bit RSA keypair locally, writes the private
    // key to the secrets file immediately (empty application_id placeholder
    // — Step 3 fills that in), and reveals only the public key.
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
            // $privateKeyPem lives only as a local variable — it is never
            // assigned to a public property, so it cannot appear in the
            // wire:snapshot payload. Clearing it is defence-in-depth.
            $privateKeyPem = null;
        }

        $this->publicKeyPem = $publicKeyPem;
        $this->step = self::STEP_REGISTER;
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
        $this->step = self::STEP_APPLICATION_ID;
    }

    // application_id is not secret, so it is not wiped from the component
    // after saving.
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
            $this->errorMessage = Lang::get('openbanking::messages.wizard.errors.save_application_id_failed');

            return;
        }

        $this->applicationId = $applicationId;
        $this->step = self::STEP_BANK;
    }

    public function chooseBank(string $bank): void
    {
        $this->bankChoice = in_array($bank, ['asn', 'sns', 'other'], strict: true) ? $bank : '';
    }

    public function continueToConsent(): void
    {
        $this->errorMessage = '';

        if ($this->resolveInstitutionId() === null) {
            $this->errorMessage = Lang::get('openbanking::messages.wizard.errors.choose_bank');

            return;
        }

        $this->step = self::STEP_CONSENT;
    }

    // Hands off to the consent/SCA dance, carrying the user-chosen
    // institution id — never hardcoded.
    public function connect(): mixed
    {
        $institutionId = $this->resolveInstitutionId();
        if ($institutionId === null) {
            $this->errorMessage = Lang::get('openbanking::messages.wizard.errors.choose_bank');
            $this->step = self::STEP_BANK;

            return null;
        }

        $this->dispatch('modal-close', name: 'open-banking-wizard');

        return $this->redirectRoute('oauth.open-banking.connect', ['institution_id' => $institutionId]);
    }

    /**
     * @link ../../../../../.docs/features/open-banking/architecture.md
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
            // Exposed so the Step 5 same-tab link's no-JS/middle-click href
            // fallback can carry institution_id.
            'consentInstitutionId' => $this->resolveInstitutionId(),
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
            'other' => $this->otherInstitutionIdOrNull() ?? Lang::get('openbanking::messages.wizard.your_bank'),
            default => Lang::get('openbanking::messages.wizard.your_bank'),
        };
    }
}
