<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\Services\InboxQuery;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Invokable controller routed at GET /oauth/connect/{provider} —
 * kicks off the per-inbox OAuth consent dance for the gmail or
 * microsoft provider.
 *
 * Computes the loopback redirect URI server-side from the injected
 * Config repository so a query-string-supplied redirect_uri cannot
 * smuggle a different value into the consent URL. Selects the right
 * provider wrapper via match($provider), issues a per-flow random
 * state via OAuthStateRepository, stashes the optional existing-inbox
 * id (for the reconnect path), and redirects to the provider's
 * authorization URL.
 *
 * The reconnect path resolves the existing inbox via the Public
 * InboxQuery service — which scopes to the current user — rather
 * than a raw DB read; the cross-user 404 invariant is enforced
 * inside the query.
 */
final class OAuthConnectController
{
    public function __construct(
        private readonly GoogleOAuthProvider $googleOAuth,
        private readonly MicrosoftOAuthProvider $microsoftOAuth,
        private readonly OAuthSecretsRepository $secrets,
        private readonly OAuthStateRepository $oauthState,
        private readonly CurrentUser $currentUser,
        private readonly Redirector $redirector,
        private readonly ConfigRepository $config,
        private readonly InboxQuery $inboxQuery,
    ) {}

    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        $oauth = match ($provider) {
            'gmail' => $this->googleOAuth,
            'microsoft' => $this->microsoftOAuth,
            default => throw new NotFoundHttpException('Unknown provider.'),
        };

        if (! $this->secrets->hasProviderClient($provider)) {
            return $this->redirector
                ->route('inboxes.index')
                ->with('oauth_failed', 'Connect your email — finish the OAuth-client wizard first.');
        }

        $existingInboxId = null;
        $reconnectIdRaw = $request->query('inbox_id');
        if (is_string($reconnectIdRaw) && $reconnectIdRaw !== '' && ctype_digit($reconnectIdRaw)) {
            $candidate = (int) $reconnectIdRaw;
            if ($candidate > 0) {
                $inbox = $this->inboxQuery->findForUser($candidate, $this->currentUser->user());
                if ($inbox === null) {
                    throw new NotFoundHttpException('Inbox not found.');
                }
                $existingInboxId = $candidate;
            }
        }

        $redirectUri = $this->computeLoopbackRedirectUri($provider);

        $state = $this->oauthState->issueState($provider, $this->currentUser->user()->id, $existingInboxId);
        $authorizationUrl = $oauth->getAuthorizationUrl($state, $redirectUri);

        return $this->redirector->away($authorizationUrl);
    }

    private function computeLoopbackRedirectUri(string $provider): string
    {
        $appUrl = $this->config->get('app.url');
        $appUrlString = is_string($appUrl) ? $appUrl : '';
        $port = parse_url($appUrlString, PHP_URL_PORT);
        $portInt = is_int($port) && $port > 0 ? $port : 8000;

        return 'http://127.0.0.1:'.$portInt.'/oauth/callback/'.$provider;
    }
}
