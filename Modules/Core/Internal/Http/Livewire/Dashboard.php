<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

/**
 * The `/` landing page. Renders the "this period at a glance" dashboard
 * (D-17): three KPI tiles (In / Out / Net), the top-spending categories
 * list with thin progress bars, and the recent transactions table.
 *
 * Period navigation: `previousPeriod()` / `nextPeriod()` step the
 * window by one calendar period; `today()` returns to the current
 * period. The Blade exposes left / right arrow buttons and an Alpine.js
 * keyboard listener (←, →, t) so users can sweep across months without
 * leaving the keyboard.
 *
 * First-run handling (D-18) is decided at the route layer — the
 * `/` handler checks `isFirstRun` via the same query service and
 * redirects to `/imports/new` before this component mounts. That keeps
 * the redirect a single HTTP hop (no Livewire round-trip) and matches
 * the project's overall preference for handling lifecycle decisions at
 * the controller level.
 *
 * DI: per Plan 05's established Livewire convention, services arrive as
 * parameters on each action method (the strict-rules ban property-based
 * constructor injection on Component subclasses).
 */
final class Dashboard extends Component
{
    /**
     * Anchor date (Y-m-d) that pins the displayed period. Null = current.
     */
    public ?string $periodStartStr = null;

    public function previousPeriod(PeriodQuery $periods): void
    {
        $current = $this->periodStartStr === null
            ? $periods->current()
            : $periods->containing(CarbonImmutable::parse($this->periodStartStr));

        $this->periodStartStr = $periods->previous($current)->start->toDateString();
    }

    public function nextPeriod(PeriodQuery $periods): void
    {
        $current = $this->periodStartStr === null
            ? $periods->current()
            : $periods->containing(CarbonImmutable::parse($this->periodStartStr));

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

        $period = $this->periodStartStr === null
            ? $periods->current()
            : $periods->containing(CarbonImmutable::parse($this->periodStartStr));

        $summary = $glance->for($user, $period);

        return $views->make('core::livewire.dashboard', [
            'summary' => $summary,
        ]);
    }
}
