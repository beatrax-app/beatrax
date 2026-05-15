<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
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

    public function render(
        CurrentUser $currentUser,
        PeriodQuery $periods,
        ThisPeriodAtAGlanceQuery $glance,
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

        return $views->make('core::livewire.dashboard', [
            'summary' => $summary,
            'tiles' => $tiles,
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
