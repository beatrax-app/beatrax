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
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\EmailScan\Public\Services\InboxQuery;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../.docs/features/email-scan/architecture.md
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
            MailProvider::Gmail->value => $this->googleOAuth,
            MailProvider::Microsoft->value => $this->microsoftOAuth,
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
                // Reconnect must target the same provider as the
                // existing inbox row — a cross-provider dance would
                // permanently break the inbox's next scan.
                if ($inbox->provider !== $provider) {
                    throw new NotFoundHttpException('Inbox not found.');
                }
                $existingInboxId = $candidate;
            }
        }

        $redirectUri = $this->loopback->forProvider($provider);

        $state = $this->oauthState->issueState($provider, $this->currentUser->user()->id, $existingInboxId);
        $authorization = $oauth->getAuthorizationUrl($state, $redirectUri);
        $this->oauthState->storePkceVerifier($provider, $authorization->pkceVerifier);

        return $this->redirector->away($authorization->url);
    }
}
