<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\OpenBanking\Internal\Actions\CompleteBankConsent;
use Modules\OpenBanking\Internal\OAuth\InvalidStateException;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Internal\Support\ReaderRefusal;
use Psr\Log\LoggerInterface;
use RuntimeException;

final readonly class OpenBankingCallbackController
{
    // Both query parameters are a stranger's to write, so the log takes a
    // bounded copy rather than whatever length a link carried.
    private const int PROVIDER_REASON_CAP = 200;

    public function __construct(
        private OpenBankingStateRepository $oauthState,
        private CurrentUser $currentUser,
        private Redirector $redirector,
        private CompleteBankConsent $completeConsent,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        if ($this->wasCanceledAtTheBank($request)) {
            return $this->backToSettings(
                'open_banking_canceled',
                Lang::get('openbanking::messages.errors.consent_not_completed'),
            );
        }

        // Resolve the current user before consuming the state so the consume
        // call can verify the state's stored user_id matches.
        $userId = $this->currentUser->id();
        $stateParamRaw = $request->query('state');
        $codeRaw = $request->query('code');

        try {
            // Inside the try, not before it: a state that does not match is an
            // ORDINARY way for this URL to be reached — a link opened twice, a
            // back button, a redirect that sat in a tab overnight — and it left
            // the reader on a 500 page in the middle of connecting their bank.
            $institutionId = $this->oauthState->consumeState(is_string($stateParamRaw) ? $stateParamRaw : '', $userId);
            if ($institutionId === null) {
                throw InvalidStateException::stateMismatch();
            }

            $connectionId = ($this->completeConsent)($userId, $institutionId, is_string($codeRaw) ? $codeRaw : '');
        } catch (RuntimeException $e) {
            return $this->backToSettings('open_banking_failed', $this->readerReason($e));
        }

        return $this->backToSettings('open_banking_connected', $connectionId);
    }

    // This flash renders verbatim on the settings screen, so ReaderRefusal owns
    // which refusals may speak for themselves. The rest are recorded here and
    // answered on screen in the reader's own words.
    private function readerReason(RuntimeException $e): string
    {
        $refusal = ReaderRefusal::for($e);

        if ($refusal->hidesDetail) {
            $this->logger->warning(
                'OpenBankingCallbackController: a refusal with no detail a reader could act on.',
                SafeExceptionContext::describe($e),
            );
        }

        return $refusal->message;
    }

    // The bank names its refusal in the query string of a GET a reader can be
    // handed a link to, so neither parameter may be drawn: the settings page
    // rendered whatever the URL carried inside its own danger alert, which put
    // a stranger's sentence on screen wearing the app's chrome.
    private function wasCanceledAtTheBank(Request $request): bool
    {
        $errorParam = $request->query('error');
        if (! is_string($errorParam) || $errorParam === '') {
            return false;
        }

        $description = $request->query('error_description');

        $this->logger->info('OpenBankingCallbackController: the bank did not complete the consent.', [
            'error' => mb_substr($errorParam, 0, self::PROVIDER_REASON_CAP),
            'error_description' => mb_substr(is_string($description) ? $description : '', 0, self::PROVIDER_REASON_CAP),
        ]);

        return true;
    }

    private function backToSettings(string $key, mixed $value): RedirectResponse
    {
        return $this->redirector
            ->route('settings.open-banking')
            ->with($key, $value);
    }
}
