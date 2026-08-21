<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Position\Public\Services\PositionQuery;

final class Dashboard extends Component
{
    // Anchor date (Y-m-d) that pins the displayed period, client-controlled
    // via the wire payload. Null = current period.
    public ?string $periodStartStr = null;

    public bool $failedChainResolutionExists = false;

    // Mirrors session key reauth_toast_dismissed_at; synchronised at render time.
    public bool $reauthToastDismissed = false;

    public function previousPeriod(PeriodQuery $periods): void
    {
        $resolved = $periods->resolveAnchor($this->periodStartStr);
        $this->periodStartStr = $periods->previous($resolved->period)->start->toDateString();
    }

    public function nextPeriod(PeriodQuery $periods): void
    {
        $resolved = $periods->resolveAnchor($this->periodStartStr);
        $this->periodStartStr = $periods->next($resolved->period)->start->toDateString();
    }

    public function today(): void
    {
        $this->periodStartStr = null;
    }

    // wire:poll.5s target. Clearing the row (e.g. retried via /horizon/failed)
    // hides the toast on the next tick.
    public function refreshFailedChainResolution(
        DatabaseManager $db,
        CurrentUser $currentUser,
    ): void {
        $user = $currentUser->user();
        $this->failedChainResolutionExists = $db->connection()
            ->table('chain_resolution_runs')
            ->where('user_id', $user->id)
            ->where('status', 'failed')
            ->exists();
    }

    // Session-scoped, so a refresh keeps the toast hidden but a fresh login
    // resets it and the toast re-surfaces while any inbox is needs_reauth.
    public function dismissReauthToast(
        Session $session,
        Clock $clock,
    ): void {
        $session->put('reauth_toast_dismissed_at', $clock->now()->toDateTimeString());
        $this->reauthToastDismissed = true;
    }

    public function render(
        CurrentUser $currentUser,
        PeriodQuery $periods,
        PositionQuery $position,
        DatabaseManager $db,
        ViewFactory $views,
        Session $session,
    ): View {
        $user = $currentUser->user();
        $resolved = $periods->resolveAnchor($this->periodStartStr);
        $this->periodStartStr = $resolved->isoDate;
        $period = $resolved->period;

        // One Position seam so the dashboard and the position digest cannot
        // disagree. `$summary` stays settled-EUR-only; only the tiles split.
        $positionSummary = $position->forUser($user, $period);
        $summary = $positionSummary->summary;
        $tiles = $positionSummary->tilesByCurrency;

        // Null hides the tile entirely — no connected inboxes.
        $emailScanHealth = $positionSummary->emailScanHealth;

        $reauthInboxCount = $db->connection()
            ->table('inbox_scan_state')
            ->where('user_id', $user->id)
            ->where('status', 'needs_reauth')
            ->count();

        $this->reauthToastDismissed = $session->has('reauth_toast_dismissed_at');

        // Populate on initial mount so the toast surfaces immediately
        // without waiting for the first wire:poll tick.
        $this->failedChainResolutionExists = $db->connection()
            ->table('chain_resolution_runs')
            ->where('user_id', $user->id)
            ->where('status', 'failed')
            ->exists();

        return $views->make('core::livewire.dashboard', [
            'summary' => $summary,
            'tiles' => $tiles,
            'emailScanHealth' => $emailScanHealth,
            'reauthInboxCount' => $reauthInboxCount,
            'reauthToastDismissed' => $this->reauthToastDismissed,
            // Non-developers get queue messaging via SystemAlertsBanner instead.
            'isDeveloper' => $user->is_developer === true,
        ]);
    }
}
