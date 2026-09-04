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
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
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

        try {
            $consentUrl = ($this->startConsent)(
                is_string($institutionIdRaw) ? trim($institutionIdRaw) : '',
                fn (): string => $this->callbackUri(),
            );
        } catch (RuntimeException $e) {
            return $this->failRedirect($this->readerReason($e));
        }

        return $this->redirector->away($consentUrl);
    }

    // Most refusals in this flow build their message from Lang, so what they
    // carry is already the reader's own words. The credentials one does not:
    // it names the secrets file by absolute path, and this flash renders
    // verbatim on the settings screen.
    private function readerReason(RuntimeException $e): string
    {
        if ($e instanceof OpenBankingCredentialsException) {
            $this->logger->warning(
                'OpenBankingConnectController: the stored credentials could not be read.',
                SafeExceptionContext::describe($e),
            );

            return $e->readerMessage();
        }

        return $e->getMessage();
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
    private function callbackUri(): string
    {
        return $this->loopback->forProvider('open-banking', scheme: 'https')
            .'?state='.rawurlencode($this->oauthState->issueState($this->currentUser->id()));
    }
}
