<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Livewire;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

/**
 * Flux modal SFC for the bring-your-own OAuth client registration
 * (Google variant in Plan 03; Microsoft variant lands in Plan 04).
 *
 * The user pastes their per-install OAuth client_id + client_secret
 * obtained from Google Cloud Console (or Azure Portal). The wizard
 * validates the format, persists the credentials atomically via
 * OAuthSecretsRepository (chmod-600 + tmp+rename), then auto-redirects
 * into the per-inbox consent flow so the user reaches the provider
 * authorization page without an extra click.
 *
 * Service collaborators arrive as parameters on action methods + the
 * render() method — constructor injection is banned on Livewire
 * components by the strict-rules plugin.
 */
final class OAuthClientWizardModal extends Component
{
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

    public function submit(OAuthSecretsRepository $secrets, ConfigRepository $config): mixed
    {
        $this->errorMessage = '';

        $provider = $this->provider;
        if (! is_string($provider) || ! in_array($provider, ['gmail', 'microsoft'], strict: true)) {
            $this->errorMessage = 'Pick a provider before submitting.';

            return null;
        }

        if ($provider === 'microsoft') {
            $this->errorMessage = 'Microsoft setup is available in the next plan.';

            return null;
        }

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

        $redirectUri = $this->computeLoopbackRedirectUri($provider, $config);
        $secrets->saveProviderClient($provider, $this->clientId, $this->clientSecret, $redirectUri);

        $this->dispatch('modal-hide', name: 'oauth-client-wizard-'.$provider);

        return $this->redirectRoute('oauth.connect', ['provider' => $provider]);
    }

    public function cancel(): void
    {
        $provider = $this->provider ?? 'gmail';
        $this->dispatch('modal-hide', name: 'oauth-client-wizard-'.$provider);
    }

    public function render(ViewFactory $views, ConfigRepository $config): View
    {
        $provider = $this->provider ?? 'gmail';
        $redirectUri = $this->computeLoopbackRedirectUri($provider, $config);

        return $views->make('email-scan::livewire.oauth-client-wizard-modal', [
            'provider' => $provider,
            'redirectUri' => $redirectUri,
        ]);
    }

    private function computeLoopbackRedirectUri(string $provider, ConfigRepository $config): string
    {
        $appUrl = $config->get('app.url');
        $appUrlString = is_string($appUrl) ? $appUrl : '';
        $port = parse_url($appUrlString, PHP_URL_PORT);
        $portInt = is_int($port) && $port > 0 ? $port : 8000;

        return 'http://127.0.0.1:'.$portInt.'/oauth/callback/'.$provider;
    }
}
