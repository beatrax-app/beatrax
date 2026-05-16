<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

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

    public function render(
        CurrentUser $currentUser,
        PeriodQuery $periods,
        ThisPeriodAtAGlanceQuery $glance,
        DatabaseManager $db,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $period = $this->resolvePeriod($periods);

        // `$summary` is always computed: the top-spending and recent-
        // transactions panels remain settled-EUR-only regardless of mode.
        // The per-currency split applies only to the KPI tiles at the top
        // of the page.
        $summary = $glance->for($user, $period);

        $tiles = null;
        if ($user->default_currency_view === 'original') {
            $tiles = $glance->forByCurrency($user, $period);
        }

        // D-99 / D-100 — render the "Next ICS settlement" tile when an
        // open card_statement exists; the Blade hides the tile entirely
        // when this is null (no "—" placeholder).
        $nextSettlement = $glance->nextIcsSettlement($user);

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
            'nextSettlement' => $nextSettlement,
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
