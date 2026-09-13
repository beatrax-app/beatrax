<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Actions\StartBankConsent;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Internal\Support\ReaderRefusal;
use Psr\Log\LoggerInterface;
use RuntimeException;

final readonly class OpenBankingConnectController
{
    public function __construct(
        private OpenBankingStateRepository $oauthState,
        private CurrentUser $currentUser,
        private Redirector $redirector,
        private LoopbackRedirectUri $loopback,
        private StartBankConsent $startConsent,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $institutionIdRaw = $request->query('institution_id');
        $institutionId = is_string($institutionIdRaw) ? trim($institutionIdRaw) : '';

        try {
            $consentUrl = ($this->startConsent)(
                $institutionId,
                fn (): string => $this->callbackUri($institutionId),
            );
        } catch (RuntimeException $e) {
            return $this->failRedirect($this->readerReason($e));
        }

        return $this->redirector->away($consentUrl);
    }

    // This flash renders verbatim on the settings screen, so ReaderRefusal owns
    // which refusals may speak for themselves. The rest are recorded here and
    // answered on screen in the reader's own words.
    private function readerReason(RuntimeException $e): string
    {
        $refusal = ReaderRefusal::for($e);

        if ($refusal->hidesDetail) {
            $this->logger->warning(
                'OpenBankingConnectController: a refusal with no detail a reader could act on.',
                SafeExceptionContext::describe($e),
            );
        }

        return $refusal->message;
    }

    private function failRedirect(string $message): RedirectResponse
    {
        return $this->redirector
            ->route('settings.open-banking')
            ->with('open_banking_failed', $message);
    }

    // The state is issued here rather than inside the action: it exists only
    // to be read back by the callback request. Handed over as a closure, so a
    // refused attempt mints none — one would overwrite the state another tab
    // is still waiting on at the bank.
    private function callbackUri(string $institutionId): string
    {
        return $this->loopback->forProvider('open-banking', scheme: 'https')
            .'?state='.rawurlencode($this->oauthState->issueState($this->currentUser->id(), $institutionId));
    }
}
