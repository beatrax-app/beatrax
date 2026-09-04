<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Actions\StartBankConsent;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use RuntimeException;

final readonly class OpenBankingConnectController
{
    public function __construct(
        private OpenBankingStateRepository $oauthState,
        private CurrentUser $currentUser,
        private Redirector $redirector,
        private LoopbackRedirectUri $loopback,
        private StartBankConsent $startConsent,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $institutionIdRaw = $request->query('institution_id');

        try {
            $consentUrl = ($this->startConsent)(
                is_string($institutionIdRaw) ? trim($institutionIdRaw) : '',
                fn (): string => $this->callbackUri(),
            );
        } catch (RuntimeException $e) {
            // Every refusal subclasses RuntimeException and carries a
            // user-facing reason, so one flash handles all of them.
            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_failed', $e->getMessage());
        }

        return $this->redirector->away($consentUrl);
    }

    // The state is issued here rather than inside the action: it exists only
    // to be read back by the callback request. Handed over as a closure, so a
    // refused attempt mints none — one would overwrite the state another tab
    // is still waiting on at the bank.
    private function callbackUri(): string
    {
        return $this->loopback->forProvider('open-banking', scheme: 'https')
            .'?state='.rawurlencode($this->oauthState->issueState($this->currentUser->id()));
    }
}
