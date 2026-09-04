<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\EmailScan\Internal\Actions\ConnectInboxFromGrant;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Internal\SafeMessage;
use Modules\EmailScan\Public\Enums\MailProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        $mailProvider = MailProvider::tryFrom($provider);
        if ($mailProvider === null) {
            throw new NotFoundHttpException('Unknown provider.');
        }

        try {
            return $this->complete($request, $mailProvider, $provider);
        } catch (RuntimeException $e) {
            // A refusal that already names its own status keeps it: the
            // not-found this flow raises for an inbox the reader does not own
            // is the answer, not a failure to answer.
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            // Everything else here means the grant arrived and this device
            // could not finish with it. The sentence naming which part gave up
            // is a developer's, so it goes to the log and the reader is told
            // what happened to their mailbox instead.
            $this->logger->warning(
                'OAuthCallbackController: the callback did not complete.',
                SafeExceptionContext::describe($e),
            );

            return $this->failRedirect(Lang::get('email-scan::inboxes.oauth_not_saved'));
        }
    }

    private function complete(Request $request, MailProvider $mailProvider, string $provider): RedirectResponse
    {
        $canceled = $this->canceledRedirect($request);
        if ($canceled !== null) {
            return $canceled;
        }

        $userId = $this->currentUser->user()->id;
        $existingInboxId = $this->consumeState($request, $provider, $userId);
        if ($existingInboxId === null) {
            // A state matching no issued one is an ORDINARY way to reach this
            // URL — a link opened twice, a back button, a tab that sat
            // overnight — and it left the reader on a server-fault page in the
            // middle of connecting a mailbox.
            return $this->failRedirect(Lang::get('email-scan::inboxes.oauth_state_mismatch'));
        }

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

    // Consumed before the token exchange so the repository can verify the
    // stored user_id: an inbox must only attach to the user who initiated the
    // dance.
    private function consumeState(Request $request, string $provider, int $userId): ?int
    {
        $stateParamRaw = $request->query('state');

        return $this->oauthState->consumeState(
            $provider,
            is_string($stateParamRaw) ? $stateParamRaw : '',
            $userId,
        );
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
