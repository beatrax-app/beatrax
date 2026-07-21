<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\OpenBanking\Public\Services\OpenBankingConnectionQuery;

/**
 * @link ../../../../../.docs/features/open-banking/architecture.md
 */
final class OpenBankingStatusRow extends Component
{
    public string $statusText = '';

    public bool $expired = false;

    public function mount(CurrentUser $currentUser, OpenBankingConnectionQuery $query): void
    {
        $view = $query->current($currentUser->user()->id);

        if ($view === null || ! $view->enabled) {
            $this->statusText = 'Not connected. Import ICS/ASN statements manually, or connect a bank automatically.';
            $this->expired = false;

            return;
        }

        if ($view->consentStatus === 'expired') {
            $this->statusText = 'Consent expired — reconnect needed.';
            $this->expired = true;

            return;
        }

        $lastSynced = $view->lastSuccessfulSyncAt?->diffForHumans() ?? 'never';
        $this->statusText = "Connected to {$view->bankDisplayName} via Enable Banking. Last synced {$lastSynced}.";
        $this->expired = false;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('openbanking::livewire.open-banking-status-row');
    }
}
