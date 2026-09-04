<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Navigation\Destination;
use Modules\EmailScan\Internal\Actions\ConnectInboxFromGrant;
use Modules\EmailScan\Internal\OAuth\InvalidStateException;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Internal\SafeMessage;
use Modules\EmailScan\Public\Enums\MailProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The state and the PKCE verifier are consumed here rather than inside the
// action: both are one-shot request glue, and the state has to be read before
// the exchange, so the user it was issued to is the one it completes for.
final readonly class OAuthCallbackController
{
    public function __construct(
        private OAuthStateRepository $oauthState,
        private CurrentUser $currentUser,
        private Redirector $redirector,
        private ConnectInboxFromGrant $connect,
    ) {}

    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        $mailProvider = MailProvider::tryFrom($provider);
        if ($mailProvider === null) {
            throw new NotFoundHttpException('Unknown provider.');
        }

        $canceled = $this->canceledRedirect($request);
        if ($canceled !== null) {
            return $canceled;
        }

        $userId = $this->currentUser->user()->id;
        $existingInboxId = $this->consumeStateOrFail($request, $provider, $userId);

        $codeRaw = $request->query('code');
        $result = ($this->connect)(
            $mailProvider,
            $userId,
            $existingInboxId,
            is_string($codeRaw) ? $codeRaw : '',
            $this->oauthState->consumePkceVerifier($provider) ?? '',
        );

        return $result->failure !== null
            ? $this->failRedirect($result->failure)
            : $this->connectedRedirect($existingInboxId, $result->inboxId);
    }

    private function canceledRedirect(Request $request): ?RedirectResponse
    {
        $errorParam = $request->query('error');
        if (! is_string($errorParam) || $errorParam === '') {
            return null;
        }

        $description = $request->query('error_description');
        $message = is_string($description) && $description !== '' ? $description : $errorParam;

        // The text renders escaped, so the cap is about length rather than
        // XSS: this string is provider-supplied and attacker-influenced.
        return $this->redirector
            ->route(Destination::Email->routeName())
            ->with('oauth_canceled', SafeMessage::cap($message));
    }

    // Consumed before the token exchange so consumeState() can verify the
    // stored user_id: an inbox must only attach to the user who initiated the
    // dance.
    private function consumeStateOrFail(Request $request, string $provider, int $userId): int
    {
        $stateParamRaw = $request->query('state');
        $stateParam = is_string($stateParamRaw) ? $stateParamRaw : '';
        $existingInboxId = $this->oauthState->consumeState($provider, $stateParam, $userId);
        if ($existingInboxId === null) {
            throw new InvalidStateException('OAuth state mismatch.');
        }

        return $existingInboxId;
    }

    private function connectedRedirect(int $existingInboxId, int $inboxId): RedirectResponse
    {
        $redirect = $this->redirector
            ->route(Destination::Email->routeName())
            ->with('open_backfill_modal', $inboxId);

        if ($existingInboxId > 0) {
            // Defence in depth: a future refactor that moves alert resolution
            // off this callback still sees the signal land at /inboxes.
            return $redirect->with('oauth_reconnect_acknowledged', $inboxId);
        }

        return $redirect;
    }

    private function failRedirect(string $message): RedirectResponse
    {
        return $this->redirector
            ->route(Destination::Email->routeName())
            ->with('oauth_failed', $message);
    }
}
