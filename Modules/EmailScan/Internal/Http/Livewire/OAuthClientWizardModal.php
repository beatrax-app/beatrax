<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\EmailScan\Public\Services\SecretsWriteFailed;

/**
 * @link ../../../../../.docs/features/email-scan/architecture.md
 */
final class OAuthClientWizardModal extends Component
{
    // Azure assigns the application (client) ID as a UUID v4, matching
    // the canonical RFC 4122 v4 shape (8-4-4-4-12 hex digits, fixed 4
    // in the third group, 8/9/a/b in the fourth group's high nibble).
    private const MICROSOFT_CLIENT_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public ?string $provider = null;

    // Optional inbox id threaded through from the InboxesPage
    // re-consent surface; when set, submit()'s redirect appends
    // ?inbox_id={id} so the consent dance binds to the existing row
    // instead of creating a fresh one. Null on the first-connect path.
    public ?int $reconnectInboxId = null;

    public string $clientId = '';

    public string $clientSecret = '';

    public bool $publishedConfirmed = false;

    public string $errorMessage = '';

    #[On('oauth-client-wizard:open')]
    public function open(string $provider, ?int $inboxId = null): void
    {
        $this->provider = in_array($provider, ['gmail', 'microsoft'], strict: true)
            ? $provider
            : null;
        $this->reconnectInboxId = $inboxId !== null && $inboxId > 0 ? $inboxId : null;
        $this->clientId = '';
        $this->clientSecret = '';
        $this->publishedConfirmed = false;
        $this->errorMessage = '';

        // Dispatches modal-show from here (not from the caller) so it
        // targets the now-correct oauth-client-wizard-{provider} name
        // — this component is the only surface that knows its own
        // provider-suffixed name once $provider is set above.
        if ($this->provider !== null) {
            $this->dispatch('modal-show', name: 'oauth-client-wizard-'.$this->provider);
        }
    }

    public function submit(OAuthSecretsRepository $secrets, LoopbackRedirectUri $loopback): mixed
    {
        $this->errorMessage = '';

        $provider = $this->provider;
        if (! is_string($provider) || ! in_array($provider, ['gmail', 'microsoft'], strict: true)) {
            $this->errorMessage = 'Pick a provider before submitting.';

            return null;
        }

        $validationError = $this->validateCredentials($provider);
        if ($validationError !== null) {
            $this->errorMessage = $validationError;

            return null;
        }

        return $this->persistAndRedirect($provider, $secrets, $loopback);
    }

    private function validateCredentials(string $provider): ?string
    {
        return $provider === 'microsoft'
            ? $this->validateMicrosoftCredentials()
            : $this->validateGoogleCredentials();
    }

    private function validateMicrosoftCredentials(): ?string
    {
        if ($this->clientId === '' || preg_match(self::MICROSOFT_CLIENT_ID_PATTERN, $this->clientId) !== 1) {
            return 'Enter the application (client) ID — a UUID like 12345678-1234-1234-1234-123456789abc.';
        }

        if ($this->clientSecret === '') {
            return 'Enter the client secret value Azure showed you when you created the secret.';
        }

        return null;
    }

    private function validateGoogleCredentials(): ?string
    {
        if ($this->clientId === '' || ! str_ends_with($this->clientId, '.apps.googleusercontent.com')) {
            return 'Enter a Google OAuth client ID ending in .apps.googleusercontent.com.';
        }

        if ($this->clientSecret === '' || ! str_starts_with($this->clientSecret, 'GOCSPX-')) {
            return 'Enter a Google OAuth client secret starting with GOCSPX-.';
        }

        if (! $this->publishedConfirmed) {
            return "Confirm that you've pushed your OAuth consent screen to 'In production'.";
        }

        return null;
    }

    private function persistAndRedirect(
        string $provider,
        OAuthSecretsRepository $secrets,
        LoopbackRedirectUri $loopback,
    ): mixed {
        $redirectUri = $loopback->forProvider($provider);

        // Captures the secret into a local so the property can be
        // wiped before the external call — a thrown exception then
        // cannot leave the secret on the component instance, which
        // would otherwise round-trip inside the wire:snapshot payload.
        $clientId = $this->clientId;
        $clientSecret = $this->clientSecret;
        $this->clientId = '';
        $this->clientSecret = '';

        try {
            $secrets->saveProviderClient($provider, $clientId, $clientSecret, $redirectUri);
        } catch (SecretsWriteFailed) {
            $this->errorMessage = 'Could not save your OAuth client to disk — check your secrets-directory permissions and try again.';

            return null;
        }

        $this->dispatch('modal-close', name: 'oauth-client-wizard-'.$provider);
        $this->dispatch('oauth-client-wizard:saved', provider: $provider);

        return $this->afterSaveRedirect($provider);
    }

    private function afterSaveRedirect(string $provider): mixed
    {
        // Re-consent flow: threads the inbox id back through the
        // connect route so the consent dance binds to the existing row
        // (preserving inbox_messages/.eml blobs/cursor) instead of
        // creating a fresh one.
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
        $provider = $this->provider ?? 'gmail';
        $this->dispatch('modal-close', name: 'oauth-client-wizard-'.$provider);
    }

    public function render(ViewFactory $views, LoopbackRedirectUri $loopback): View
    {
        $provider = $this->provider ?? 'gmail';
        $redirectUri = $loopback->forProvider($provider);

        return $views->make('email-scan::livewire.oauth-client-wizard-modal', [
            'provider' => $provider,
            'redirectUri' => $redirectUri,
        ]);
    }
}
