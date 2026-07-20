<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;

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
