<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\EmailScan\Public\Services\SecretsWriteFailed;

final class OAuthClientWizardModal extends Component
{
    private const MICROSOFT_CLIENT_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public ?string $provider = null;

    // Null on the first-connect path; set from the re-consent surface, where
    // submit() appends ?inbox_id={id} to bind the dance to the existing row.
    public ?int $reconnectInboxId = null;

    public string $clientId = '';

    public string $clientSecret = '';

    public bool $publishedConfirmed = false;

    public string $errorMessage = '';

    #[On('oauth-client-wizard:open')]
    public function open(string $provider, ?int $inboxId = null): void
    {
        $this->provider = MailProvider::tryFrom($provider) !== null
            ? $provider
            : null;
        $this->reconnectInboxId = $inboxId !== null && $inboxId > 0 ? $inboxId : null;
        $this->clientId = '';
        $this->clientSecret = '';
        $this->publishedConfirmed = false;
        $this->errorMessage = '';

        // Dispatched here, not by the caller: only this component knows its
        // provider-suffixed name once $provider is set above.
        if ($this->provider !== null) {
            $this->dispatch('modal-show', name: 'oauth-client-wizard-'.$this->provider);
        }
    }

    public function submit(OAuthSecretsRepository $secrets, LoopbackRedirectUri $loopback): mixed
    {
        $this->errorMessage = '';

        $provider = $this->provider;
        if (! is_string($provider) || MailProvider::tryFrom($provider) === null) {
            $this->errorMessage = Lang::get('email-scan::wizard.errors.pick_provider');
            $this->clientSecret = '';

            return null;
        }

        $validationError = $this->validateCredentials($provider);
        if ($validationError !== null) {
            // Wiped on a rejected submit too: otherwise the plaintext lingers
            // on the component and re-serialises into every wire:snapshot.
            $this->errorMessage = $validationError;
            $this->clientSecret = '';

            return null;
        }

        return $this->persistAndRedirect($provider, $secrets, $loopback);
    }

    private function validateCredentials(string $provider): ?string
    {
        return $provider === MailProvider::Microsoft->value
            ? $this->validateMicrosoftCredentials()
            : $this->validateGoogleCredentials();
    }

    private function validateMicrosoftCredentials(): ?string
    {
        if ($this->clientId === '' || preg_match(self::MICROSOFT_CLIENT_ID_PATTERN, $this->clientId) !== 1) {
            return Lang::get('email-scan::wizard.errors.microsoft_client_id');
        }

        if ($this->clientSecret === '') {
            return Lang::get('email-scan::wizard.errors.microsoft_secret');
        }

        return null;
    }

    private function validateGoogleCredentials(): ?string
    {
        return match (true) {
            $this->clientId === '' || ! str_ends_with($this->clientId, '.apps.googleusercontent.com') => Lang::get('email-scan::wizard.errors.google_client_id'),
            $this->clientSecret === '' || ! str_starts_with($this->clientSecret, 'GOCSPX-') => Lang::get('email-scan::wizard.errors.google_secret'),
            ! $this->publishedConfirmed => Lang::get('email-scan::wizard.errors.google_published'),
            default => null,
        };
    }

    private function persistAndRedirect(
        string $provider,
        OAuthSecretsRepository $secrets,
        LoopbackRedirectUri $loopback,
    ): mixed {
        $redirectUri = $loopback->forProvider($provider);

        // Copied to a local so the property can be wiped before the external
        // call: a throw must not leave the secret on the component, where it
        // would round-trip inside the wire:snapshot payload.
        $clientId = $this->clientId;
        $clientSecret = $this->clientSecret;
        $this->clientId = '';
        $this->clientSecret = '';

        try {
            $secrets->saveProviderClient($provider, $clientId, $clientSecret, $redirectUri);
        } catch (SecretsWriteFailed) {
            $this->errorMessage = Lang::get('email-scan::wizard.errors.write_failed');

            return null;
        }

        $this->dispatch('modal-close', name: 'oauth-client-wizard-'.$provider);
        $this->dispatch('oauth-client-wizard:saved', provider: $provider);

        return $this->afterSaveRedirect($provider);
    }

    private function afterSaveRedirect(string $provider): mixed
    {
        // Binding to the existing row preserves its inbox_messages, .eml
        // blobs and cursor; a fresh row would start from nothing.
        $reconnectInboxId = $this->reconnectInboxId;
        if ($reconnectInboxId !== null && $reconnectInboxId > 0) {
            $this->dispatch('oauth-client-wizard:reconsented', inboxId: $reconnectInboxId);

            return $this->redirectRoute(
                'oauth.connect',
                ['provider' => $provider, 'inbox_id' => $reconnectInboxId],
            );
        }

        return $this->redirectRoute('oauth.connect', ['provider' => $provider]);
    }

    public function cancel(): void
    {
        $provider = $this->provider ?? MailProvider::Gmail->value;
        $this->dispatch('modal-close', name: 'oauth-client-wizard-'.$provider);
    }

    public function render(ViewFactory $views, LoopbackRedirectUri $loopback): View
    {
        $provider = $this->provider ?? MailProvider::Gmail->value;
        $redirectUri = $loopback->forProvider($provider);

        return $views->make('email-scan::livewire.oauth-client-wizard-modal', [
            'provider' => $provider,
            'redirectUri' => $redirectUri,
        ]);
    }
}
