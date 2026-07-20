<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Position\Public\Services\PositionQuery;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class Dashboard extends Component
{
    private const PERIOD_DATE_FORMAT = 'Y-m-d';

    // Anchor date (Y-m-d) that pins the displayed period, client-controlled
    // via the wire payload. Null = current period.
    public ?string $periodStartStr = null;

    // Source of truth is chain_resolution_runs filtered by exact user_id
    // match (see architecture.md) — set by refreshFailedChainResolution(),
    // populated on initial mount then refreshed via wire:poll.5s.
    public bool $failedChainResolutionExists = false;

    // Mirrors the session key reauth_toast_dismissed_at so the Blade can
    // branch on a single property rather than re-reading the session on
    // every render; synchronised from the session at render time.
    public bool $reauthToastDismissed = false;

    public function previousPeriod(PeriodQuery $periods): void
    {
        $current = $this->resolvePeriod($periods);
        $this->periodStartStr = $periods->previous($current)->start->toDateString();
    }

    public function nextPeriod(PeriodQuery $periods): void
    {
        $current = $this->resolvePeriod($periods);
        $this->periodStartStr = $periods->next($current)->start->toDateString();
    }

    public function today(): void
    {
        $this->periodStartStr = null;
    }

    // wire:poll.5s target. Latest "failed" row for this user surfaces the
    // persistent toast; when the row is cleared (e.g. retried via
    // /horizon/failed) the toast hides on the next poll.
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

    // Writes a session-scoped timestamp so a refresh in the same tab keeps
    // the toast hidden; a fresh login resets the session and the toast
    // re-surfaces if any inbox is still needs_reauth.
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
        $period = $this->resolvePeriod($periods);

        // Position data: net worth + budgets + upcoming + shortfalls, read
        // through the single Modules\Position Public seam so the dashboard
        // and the position digest can never disagree about "your position".
        // `$summary` stays settled-EUR-only; only the KPI tiles split per-currency.
        $positionSummary = $position->forUser($user, $period);
        $summary = $positionSummary->summary;
        $tiles = $positionSummary->tilesByCurrency;

        // Email-scan-health tile. Null = hide the tile entirely (no
        // connected inboxes). The dashboard Blade reads the same null-
        // first contract as the Next-ICS-settlement tile above.
        $emailScanHealth = $positionSummary->emailScanHealth;

        // Persistent reauth toast trigger: lit when at least one inbox is in
        // needs_reauth state. The Blade also reads the session dismiss-flag
        // to suppress the toast once clicked; it re-surfaces if a fresh
        // needs_reauth state appears after dismissal in a later session.
        $reauthInboxCount = $db->connection()
            ->table('inbox_scan_state')
            ->where('user_id', $user->id)
            ->where('status', 'needs_reauth')
            ->count();

        // Mirror the session dismiss flag into the property so the
        // Blade can branch on a single property reference. The session
        // is the source of truth — a fresh login resets it and the
        // toast resurfaces if any inbox is still needs_reauth.
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
            // Non-developers see no Horizon-style queue messaging here —
            // their channel is the existing SystemAlertsBanner.
            'isDeveloper' => $user->is_developer === true,
        ]);
    }

    // On any mismatch or parse failure, falls back to the current period and
    // clears the property so the bad value cannot survive the round-trip.
    private function resolvePeriod(PeriodQuery $periods): Period
    {
        if ($this->periodStartStr === null) {
            return $periods->current();
        }

        $parsed = CarbonImmutable::createFromFormat(self::PERIOD_DATE_FORMAT, $this->periodStartStr);
        if ($parsed === null) {
            $this->periodStartStr = null;

            return $periods->current();
        }

        // Round-trip the formatted date to refuse strings that parse but
        // do not stringify back to the original (e.g. "2026-02-30" which
        // Carbon happily accepts as "2026-03-02").
        if ($parsed->format(self::PERIOD_DATE_FORMAT) !== $this->periodStartStr) {
            $this->periodStartStr = null;

            return $periods->current();
        }

        return $periods->containing($parsed);
    }
}
