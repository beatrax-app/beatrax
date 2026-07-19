<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\OpenBanking\Public\Services\OpenBankingConnectionQuery;

/**
 * Settings entry row (19-11, UI-SPEC Surface A) — a compact link-out card
 * mounted inside `Modules\Core\Resources\views\livewire\settings-page.blade.php`,
 * following the existing `Aliases` link-out shape (not the fully-embedded
 * `Devices & Sync` shape — this surface's own full UI lives at the
 * dedicated `/settings/open-banking` page, not inline here).
 *
 * A tiny, self-contained Livewire component rather than static Blade
 * copy (unlike `Aliases`) because the one-line status text is genuinely
 * dynamic per user (off / connected+bank+relative-sync / expired) —
 * mirrors every other dynamic settings sub-section
 * (`anomaly.settings-section`, `notifications.settings-section`, etc.)
 * in reaching for its own tiny component rather than threading query
 * results through `Modules\Core\Internal\Http\Livewire\SettingsPage`,
 * which would otherwise need to import an OpenBanking Public service.
 */
final class OpenBankingStatusRow extends Component
{
    public string $statusText = '';

    /** Renders the rose "reconnect needed" tone when true. */
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
