<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\LoopbackRedirectUri;
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
        private readonly InboxQuery $inboxQuery,
        private readonly LoopbackRedirectUri $loopback,
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
                // Reconnect must target the SAME provider as the
                // existing inbox row. Allowing a Gmail consent dance
                // on a Microsoft inbox (or vice-versa) would write a
                // Gmail refresh token under an inbox id whose schema
                // row still claims provider='microsoft' — the next
                // IncrementalScanJob would dispatch to the Microsoft
                // branch and pass a Gmail refresh token to Azure,
                // permanently breaking the inbox. We respond with the
                // same NotFoundHttpException shape the cross-user 404
                // path uses so a leaked provider mismatch is not
                // enumerable from the response.
                if ($inbox->provider !== $provider) {
                    throw new NotFoundHttpException('Inbox not found.');
                }
                $existingInboxId = $candidate;
            }
        }

        $redirectUri = $this->loopback->forProvider($provider);

        $state = $this->oauthState->issueState($provider, $this->currentUser->user()->id, $existingInboxId);
        $authorizationUrl = $oauth->getAuthorizationUrl($state, $redirectUri);

        return $this->redirector->away($authorizationUrl);
    }
}
