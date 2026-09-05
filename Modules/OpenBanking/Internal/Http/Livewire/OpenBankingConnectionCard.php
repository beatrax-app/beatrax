<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\OpenBanking\Internal\Dto\OpenBankingSyncOutcome;
use Modules\OpenBanking\Internal\Enums\BankChoice;
use Modules\OpenBanking\Internal\Enums\ConsentStatus;
use Modules\OpenBanking\Internal\Enums\CuratedInstitution;
use Modules\OpenBanking\Internal\Enums\WizardStep;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;
use Modules\OpenBanking\Internal\Http\Livewire\Concerns\FormatsConnectionTimestamps;
use Modules\OpenBanking\Internal\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Internal\Services\OpenBankingSyncRunner;

// One connected bank: its consent, its freshness, and its own Sync now. The
// settings page renders one of these per connection rather than one panel over
// whichever connection the store happened to hold last.
final class OpenBankingConnectionCard extends Component
{
    use FormatsConnectionTimestamps;

    #[Locked]
    public int $connectionId = 0;

    #[Locked]
    public bool $enabled = false;

    #[Locked]
    public string $institutionId = '';

    public string $bankDisplayName = '';

    #[Locked]
    public string $consentStatus = ConsentStatus::Off->value;

    // The four neighbours of the consent status, written only by
    // refreshState() from the connection row and read by the transparency
    // panel through a date parser. Locked for the same reason it is.
    #[Locked]
    public ?string $consentExpiresAtIso = null;

    #[Locked]
    public ?string $lastSuccessfulSyncAtIso = null;

    #[Locked]
    public ?string $lastAttemptAtIso = null;

    #[Locked]
    public ?string $lastAttemptStatus = null;

    public string $aggregator = 'Enable Banking';

    public string $whatsFetched = '';

    public string $syncFlashMessage = '';

    /** @var ''|'success'|'zero'|'busy'|'error' */
    public string $syncFlashTone = '';

    public ?int $syncReviewImportRunId = null;

    public function mount(int $connectionId, CurrentUser $currentUser, OpenBankingConnectionQuery $query): void
    {
        $this->connectionId = $connectionId;
        $this->refreshState($currentUser, $query);
    }

    public function syncNow(
        OpenBankingSyncRunner $runner,
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
    ): void {
        $this->syncFlashMessage = '';
        $this->syncFlashTone = '';
        $this->syncReviewImportRunId = null;

        if (! $this->enabled || ConsentStatus::from($this->consentStatus)->needsReconnect()) {
            return;
        }

        $outcome = $runner->runPreview($this->connectionId, $currentUser->user());

        if ($outcome->status === null) {
            $this->syncFlashTone = 'busy';
            $this->syncFlashMessage = Lang::get('openbanking::messages.sync.in_progress');

            return;
        }

        $this->refreshState($currentUser, $query);

        if ($outcome->failure !== null) {
            $this->syncFlashTone = 'error';
            $this->syncFlashMessage = $outcome->isConsentFailure()
                ? Lang::get('openbanking::messages.sync.consent_expired')
                : Lang::get('openbanking::messages.sync.unavailable');
        }

        $preview = $outcome->preview;

        if ($outcome->failure !== null || $preview === null) {
            return;
        }

        $this->flashPreviewOutcome($outcome, $preview);
    }

    public function reconnect(): void
    {
        if ($this->connectionId <= 0 || $this->institutionId === '') {
            return;
        }

        [$bankChoice, $otherInstitutionId] = self::wizardChoiceFor($this->institutionId);

        $this->dispatch(
            'open-banking-wizard:open',
            startStep: WizardStep::Bank->value,
            bankChoice: $bankChoice,
            otherInstitutionId: $otherInstitutionId,
        );
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('openbanking::livewire.open-banking-connection-card');
    }

    // Ahead of every count, because a press that filed nothing is not a quiet
    // week and the sentence for a quiet week is what it used to get. The review
    // link is the only place each row says which stage refused it.
    private function flashPreviewOutcome(OpenBankingSyncOutcome $outcome, ImportPreviewResult $preview): void
    {
        $newCount = count(array_filter(
            $preview->rows,
            static fn (PreviewRowDto $row): bool => $row->status === PreviewRowStatus::NewRow,
        ));

        // Truncation is said before the count too, and in the error tone: rows
        // did arrive, but the reader is looking at part of a window and nothing
        // has been recorded as read.
        [$this->syncFlashTone, $this->syncFlashMessage, $linked] = match (true) {
            $outcome->filedNothing() => ['error', Lang::get('openbanking::messages.sync.none_importable'), true],
            $outcome->isTruncated() => ['error', Lang::get('openbanking::messages.sync.truncated'), $newCount > 0],
            $newCount > 0 => ['success', Lang::choice('openbanking::messages.sync.new_found', $newCount), true],
            default => ['zero', Lang::get('openbanking::messages.sync.none'), false],
        };

        $this->syncReviewImportRunId = $linked ? $preview->importRunId : null;
    }

    // An unreadable store draws no card, the same state as a bank that is not
    // connected: the settings page above owns the sentence that explains it,
    // and a card cannot raise a fault the page has already answered.
    private function refreshState(CurrentUser $currentUser, OpenBankingConnectionQuery $query): void
    {
        try {
            $view = $query->forConnection($currentUser->user()->id, $this->connectionId);
        } catch (OpenBankingCredentialsException) {
            $view = null;
        }

        if ($view === null) {
            $this->enabled = false;
            $this->consentStatus = ConsentStatus::Off->value;

            return;
        }

        $this->enabled = $view->enabled;
        $this->institutionId = $view->institutionId;
        $this->bankDisplayName = $view->bankDisplayName;
        $this->consentStatus = ($view->enabled ? $view->consentStatus : ConsentStatus::Off)->value;
        $this->consentExpiresAtIso = $view->consentExpiresAt?->toIso8601String();
        $this->lastSuccessfulSyncAtIso = $view->lastSuccessfulSyncAt?->toIso8601String();
        $this->lastAttemptAtIso = $view->lastAttemptAt?->toIso8601String();
        $this->lastAttemptStatus = $view->lastAttemptStatus;
        $this->aggregator = $view->aggregator;
        $this->whatsFetched = $view->whatsFetched;
    }

    // A curated bank selects its own radio; anything else is the free-text
    // path, which carries the id the reader originally typed.
    /**
     * @return array{0: string, 1: string}
     */
    private static function wizardChoiceFor(string $institutionId): array
    {
        $curated = CuratedInstitution::tryFrom($institutionId);

        return $curated === null
            ? [BankChoice::Other->value, $institutionId]
            : [$curated->choice()->value, ''];
    }
}
