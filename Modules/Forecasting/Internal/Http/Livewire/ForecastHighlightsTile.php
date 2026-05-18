<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;

/**
 * Dashboard "Forecast highlights" tile. REPLACES the Phase 5 inline
 * "Next ICS settlement" tile as a strict superset (the next-settlement
 * line is preserved alongside the new lowest-projected-balance +
 * active-shortfall-count lines).
 *
 * The tile is text-only (UI-SPEC D-1026 — no sparkline). Drills to
 * `/forecast` on click. Hidden when the user has neither a
 * lowest-projected balance nor a next ICS settlement (calm-day
 * collapse — same precedent as the Phase 9 dashboard drift badge).
 *
 * Constructor injection is banned on Livewire `Component` subclasses
 * by phpstan-strict-rules; collaborators arrive on `render()` as
 * parameter DI.
 */
final class ForecastHighlightsTile extends Component
{
    public function render(
        CurrentUser $currentUser,
        ForecastHighlightsQuery $highlightsQuery,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $dto = $highlightsQuery->forUser($user);

        return $views->make('forecasting::livewire.forecast-highlights-tile', [
            'dto' => $dto,
        ]);
    }
}
