<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\OpenBanking\Internal\Actions\CompleteBankConsent;
use Modules\OpenBanking\Internal\OAuth\InvalidStateException;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use RuntimeException;

final readonly class OpenBankingCallbackController
{
    public function __construct(
        private OpenBankingStateRepository $oauthState,
        private CurrentUser $currentUser,
        private Redirector $redirector,
        private CompleteBankConsent $completeConsent,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $cancellation = $this->cancellationMessage($request);
        if ($cancellation !== null) {
            return $this->backToSettings('open_banking_canceled', $cancellation);
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
            if (! $this->oauthState->consumeState(is_string($stateParamRaw) ? $stateParamRaw : '', $userId)) {
                throw InvalidStateException::stateMismatch();
            }

            $connectionId = ($this->completeConsent)($userId, is_string($codeRaw) ? $codeRaw : '');
        } catch (RuntimeException $e) {
            // Every refusal subclasses RuntimeException and carries a
            // user-facing reason, so one flash handles all of them.
            return $this->backToSettings('open_banking_failed', $e->getMessage());
        }

        return $this->backToSettings('open_banking_connected', $connectionId);
    }

    private function cancellationMessage(Request $request): ?string
    {
        $errorParam = $request->query('error');
        if (! is_string($errorParam) || $errorParam === '') {
            return null;
        }

        $description = $request->query('error_description');

        return is_string($description) && $description !== '' ? $description : $errorParam;
    }

    private function backToSettings(string $key, mixed $value): RedirectResponse
    {
        return $this->redirector
            ->route('settings.open-banking')
            ->with($key, $value);
    }
}
