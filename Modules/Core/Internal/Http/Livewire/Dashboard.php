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
 * The `/` landing page. Renders the "this period at a glance" dashboard:
 * three KPI tiles (In / Out / Net), the top-spending categories list with
 * thin progress bars, and the recent transactions table.
 *
 * Period navigation: `previousPeriod()` / `nextPeriod()` step the
 * window by one calendar period; `today()` returns to the current
 * period. The Blade exposes left / right arrow buttons and an Alpine.js
 * keyboard listener (←, →, t) so users can sweep across months without
 * leaving the keyboard.
 *
 * First-run handling is decided at the route layer — the `/` handler
 * checks `isFirstRun` via the same query service and redirects to
 * `/imports/new` before this component mounts. That keeps the redirect
 * a single HTTP hop (no Livewire round-trip) and matches the project's
 * overall preference for handling lifecycle decisions at the controller
 * level.
 *
 * Service collaborators arrive as parameters on each action method (the
 * strict-rules ruleset bans property-based constructor injection on
 * Livewire Component subclasses).
 *
 * `$periodStartStr` is a client-controlled string. It is always resolved
 * through `resolvePeriod()` which validates the YYYY-MM-DD shape and
 * silently falls back to the current period on any non-matching input,
 * so a malformed value from the wire payload never reaches
 * `CarbonImmutable::parse` and 500s the page.
 */
final class Dashboard extends Component
{
    private const PERIOD_DATE_FORMAT = 'Y-m-d';

    /**
     * Anchor date (Y-m-d) that pins the displayed period. Null = current.
     */
    public ?string $periodStartStr = null;

    /**
     * Whether the dashboard surfaces the persistent failed-job toast.
     *
     * Set by `refreshFailedChainResolution()` — populated on initial
     * mount via `render()` and then refreshed via `wire:poll.5s`. The
     * source of truth is the `chain_resolution_runs` audit table
     * filtered by exact `user_id` match (issue #1 + #8 fix — replaces
     * an earlier draft's substring `payload LIKE '%userId:N%'` query
     * against `failed_jobs`, which leaks across users with id
     * prefixes like 1 vs 11).
     */
    public bool $failedChainResolutionExists = false;

    /**
     * Mirror flag for the persistent reauth toast dismissal.
     *
     * The source of truth is the session key
     * `reauth_toast_dismissed_at` (written by dismissReauthToast() via
     * the injected Session contract). The Livewire property exists so
     * the Blade can branch on a single property reference rather than
     * re-reading the session on every render; the property is
     * synchronised from the session at render time, so the dismissal
     * survives across page loads in the same session.
     */
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

    /**
     * `wire:poll.5s` target on the dashboard Blade. Reads the
     * `chain_resolution_runs` audit table filtered by exact
     * `user_id` match. Never reads `failed_jobs.payload` with a
     * substring `LIKE` (issue #1 + #8 lock — prevents the user_id=1
     * vs user_id=11 cross-user false-positive). Latest "failed" row
     * for this user surfaces the persistent toast (D-103); when the
     * row is cleared from the audit table (e.g. user retried via
     * `/horizon/failed`) the toast hides on the next poll.
     */
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

    /**
     * Mark the persistent reauth toast as dismissed for the rest of
     * this session.
     *
     * Writes a session-scoped timestamp (`reauth_toast_dismissed_at`)
     * so a refresh in the same browser tab keeps the toast hidden.
     * The toast auto-disappears once every inbox returns to a non-
     * needs_reauth state ($reauthInboxCount == 0 inside the Blade
     * guard), so this dismiss is "for the rest of this session" — a
     * fresh login resets the session and the toast re-surfaces if
     * any inbox is still needs_reauth.
     *
     * DI-only: takes Session + Clock as method parameters (Livewire
     * banishes constructor DI on Component subclasses; the strict-
     * rules ruleset).
     */
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

        // Position data (D-30): net worth + budgets + upcoming + shortfalls,
        // read through the single Modules\Position Public seam so the
        // dashboard and the position digest can never disagree about what
        // "your position" means. `$summary` is always computed: the top-
        // spending and recent-transactions panels remain settled-EUR-only
        // regardless of mode. The per-currency split applies only to the
        // KPI tiles at the top of the page — `PositionSummaryDto::
        // $tilesByCurrency` already mirrors that same
        // `default_currency_view === 'original'` toggle byte-identically.
        $positionSummary = $position->forUser($user, $period);
        $summary = $positionSummary->summary;
        $tiles = $positionSummary->tilesByCurrency;

        // Email-scan-health tile. Null = hide the tile entirely (no
        // connected inboxes). The dashboard Blade reads the same null-
        // first contract as the Next-ICS-settlement tile above.
        $emailScanHealth = $positionSummary->emailScanHealth;

        // Persistent reauth toast trigger: lit when at least one inbox
        // is in needs_reauth state. The Blade also reads the session
        // dismiss-flag to suppress the toast once the user clicks the
        // × icon; the toast re-surfaces if a fresh needs_reauth state
        // appears after dismissal in a later session.
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
            // The failed-chain-resolution toast is gated on the
            // calling user's is_developer flag. Non-developers see
            // no Horizon-style queue messaging here; their channel
            // is the existing SystemAlertsBanner.
            'isDeveloper' => $user->is_developer === true,
        ]);
    }

    /**
     * Resolves the displayed period. Validates `$periodStartStr` strictly
     * against `Y-m-d`; on any mismatch or parse failure, falls back to
     * the current period and clears the property so the bad value cannot
     * survive the round-trip.
     */
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
