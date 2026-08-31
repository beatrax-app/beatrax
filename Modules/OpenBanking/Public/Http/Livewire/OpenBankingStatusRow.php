<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\OpenBanking\Internal\Enums\ConsentStatus;
use Modules\OpenBanking\Internal\Services\OpenBankingConnectionQuery;

final class OpenBankingStatusRow extends Component
{
    public string $statusText = '';

    public bool $expired = false;

    public function mount(CurrentUser $currentUser, OpenBankingConnectionQuery $query): void
    {
        $view = $query->current($currentUser->user()->id);

        if ($view === null || ! $view->enabled) {
            $this->statusText = Lang::get('openbanking::messages.status_row.not_connected');
            $this->expired = false;

            return;
        }

        // Both endings read as red and offer the same fix, but they are not the
        // same sentence: a consent the bank withdrew never expired, and naming
        // the wrong cause sends the reader to check a date that is still fine.
        if ($view->consentStatus->needsReconnect()) {
            $this->statusText = Lang::get($view->consentStatus === ConsentStatus::Revoked
                ? 'openbanking::messages.status_row.revoked'
                : 'openbanking::messages.status_row.expired');
            $this->expired = true;

            return;
        }

        $lastSynced = $view->lastSuccessfulSyncAt?->diffForHumans() ?? Lang::get('openbanking::messages.status_row.never');
        $this->statusText = Lang::get('openbanking::messages.status_row.connected', [
            'bank' => $view->bankDisplayName,
            'when' => $lastSynced,
        ]);
        $this->expired = false;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('openbanking::livewire.open-banking-status-row');
    }
}
