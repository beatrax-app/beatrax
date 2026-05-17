<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\EmailScan\Internal\LoopbackRedirectUri;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\EmailScan\Public\Services\SecretsWriteFailed;

/**
 * Flux modal SFC for the bring-your-own OAuth client registration.
 *
 * A single component renders both the Gmail and Microsoft 365
 * variants; the open() event sets the $provider property to either
 * 'gmail' or 'microsoft' and submit() branches on that value to apply
 * the provider-specific validation rules.
 *
 * Google variant: client_id ends in `.apps.googleusercontent.com`,
 * client_secret starts with `GOCSPX-`, and the publishedConfirmed
 * checkbox is required (Google Testing-mode refresh tokens expire
 * after 7 days for the gmail.readonly scope).
 *
 * Microsoft variant: client_id is a UUID v4, client_secret is
 * non-empty. There is no publishedConfirmed equivalent — Azure does
 * not require a separate publication step for personal accounts.
 *
 * On valid submission the credentials are persisted atomically via
 * OAuthSecretsRepository (chmod-600 + tmp+rename) and the wizard
 * auto-redirects into the per-inbox consent flow so the user reaches
 * the provider authorization page without an extra click.
 *
 * Service collaborators arrive as parameters on action methods + the
 * render() method — constructor injection is banned on Livewire
 * components by the strict-rules plugin.
 */
final class OAuthClientWizardModal extends Component
{
    /**
     * Azure assigns the application (client) ID as a UUID v4. The
     * format is documented at
     * https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app
     * and matches the canonical RFC 4122 v4 shape (8-4-4-4-12 hex
     * digits with a fixed `4` in the third group and one of 8/9/a/b
     * in the high nibble of the fourth group).
     */
    private const MICROSOFT_CLIENT_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public ?string $provider = null;

    public string $clientId = '';

    public string $clientSecret = '';

    public bool $publishedConfirmed = false;

    public string $errorMessage = '';

    #[On('oauth-client-wizard:open')]
    public function open(string $provider): void
    {
        $this->provider = in_array($provider, ['gmail', 'microsoft'], strict: true)
            ? $provider
            : null;
        $this->clientId = '';
        $this->clientSecret = '';
        $this->publishedConfirmed = false;
        $this->errorMessage = '';
    }

    public function submit(OAuthSecretsRepository $secrets, LoopbackRedirectUri $loopback): mixed
    {
        $this->errorMessage = '';

        $provider = $this->provider;
        if (! is_string($provider) || ! in_array($provider, ['gmail', 'microsoft'], strict: true)) {
            $this->errorMessage = 'Pick a provider before submitting.';

            return null;
        }

        if ($provider === 'microsoft') {
            if ($this->clientId === '' || preg_match(self::MICROSOFT_CLIENT_ID_PATTERN, $this->clientId) !== 1) {
                $this->errorMessage = 'Enter the application (client) ID — a UUID like 12345678-1234-1234-1234-123456789abc.';

                return null;
            }

            if ($this->clientSecret === '') {
                $this->errorMessage = 'Enter the client secret value Azure showed you when you created the secret.';

                return null;
            }
        } else {
            // Google variant validation.
            if ($this->clientId === '' || ! str_ends_with($this->clientId, '.apps.googleusercontent.com')) {
                $this->errorMessage = 'Enter a Google OAuth client ID ending in .apps.googleusercontent.com.';

                return null;
            }

            if ($this->clientSecret === '' || ! str_starts_with($this->clientSecret, 'GOCSPX-')) {
                $this->errorMessage = 'Enter a Google OAuth client secret starting with GOCSPX-.';

                return null;
            }

            if (! $this->publishedConfirmed) {
                $this->errorMessage = "Confirm that you've pushed your OAuth consent screen to 'In production'.";

                return null;
            }
        }

        $redirectUri = $loopback->forProvider($provider);

        // Capture the secret into a local so the property can be
        // wiped BEFORE the external call. A thrown exception during
        // saveProviderClient therefore cannot leave the secret on the
        // component instance (which would round-trip back to the
        // browser inside the wire:snapshot payload on the next
        // render).
        $clientId = $this->clientId;
        $clientSecret = $this->clientSecret;
        $this->clientId = '';
        $this->clientSecret = '';

        try {
            $secrets->saveProviderClient($provider, $clientId, $clientSecret, $redirectUri);
        } catch (SecretsWriteFailed) {
            // Surface the disk-write failure as an inline error so the
            // user understands what to fix instead of seeing Livewire's
            // generic "Server error" toast. Re-rendering preserves the
            // partial wizard state — the user still has to re-paste
            // the secret (the wipe above is the intentional security
            // posture) but at least they know why and can act on it.
            $this->errorMessage = 'Could not save your OAuth client to disk — check storage/app/secrets/ permissions and try again.';

            return null;
        }

        $this->dispatch('modal-hide', name: 'oauth-client-wizard-'.$provider);

        return $this->redirectRoute('oauth.connect', ['provider' => $provider]);
    }

    public function cancel(): void
    {
        $provider = $this->provider ?? 'gmail';
        $this->dispatch('modal-hide', name: 'oauth-client-wizard-'.$provider);
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
