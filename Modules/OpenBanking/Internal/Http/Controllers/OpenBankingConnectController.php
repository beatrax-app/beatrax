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
        } catch (OpenBankingCredentialsException $e) {
            // Its message names the secrets file by absolute path, and this
            // flash renders verbatim on the settings screen. The reader gets
            // the line that says what to do; the path goes to the log.
            $this->logger->warning(
                'OpenBankingConnectController: the stored credentials could not be read.',
                SafeExceptionContext::describe($e),
            );

            return $this->failRedirect($e->readerMessage());
        } catch (RuntimeException $e) {
            // Everything reaching here builds its message from Lang, so the
            // reason it carries is already the reader's own words.
            return $this->failRedirect($e->getMessage());
        }

        return $this->redirector->away($consentUrl);
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
