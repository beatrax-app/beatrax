<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Internal\Actions\ResolveInboxToReconnect;
use Modules\EmailScan\Internal\OAuth\MailOAuthProviders;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The state and the PKCE verifier are issued here rather than inside the
// action: they exist only to be read back by the callback request, and the
// redirect they authorise is this method's return value.
final readonly class OAuthConnectController
{
    public function __construct(
        private MailOAuthProviders $providers,
        private OAuthSecretsRepository $secrets,
        private OAuthStateRepository $oauthState,
        private CurrentUser $currentUser,
        private Redirector $redirector,
        private LoopbackRedirectUri $loopback,
        private ResolveInboxToReconnect $reconnectTarget,
    ) {}

    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        $mailProvider = MailProvider::tryFrom($provider);
        if ($mailProvider === null) {
            throw new NotFoundHttpException('Unknown provider.');
        }

        if (! $this->secrets->hasProviderClient($provider)) {
            return $this->redirector
                ->route(Destination::Email->routeName())
                ->with('oauth_failed', Lang::get('email-scan::inboxes.oauth_client_missing'));
        }

        $user = $this->currentUser->user();
        $existingInboxId = ($this->reconnectTarget)($mailProvider, $user, $request->query('inbox_id'));

        $state = $this->oauthState->issueState($provider, $user->id, $existingInboxId);
        $authorization = $this->providers->for($mailProvider)
            ->getAuthorizationUrl($state, $this->loopback->forProvider($provider));
        $this->oauthState->storePkceVerifier($provider, $authorization->pkceVerifier);

        return $this->redirector->away($authorization->url);
    }
}
