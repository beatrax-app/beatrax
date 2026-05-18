<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/forecast` page — the dedicated cash-flow forecast surface. A later
 * wave lands the 30/60/90 horizon switch, per-account chart, and
 * scenario CRUD panel. This is a Wave 0 placeholder so the route file
 * can be wired and the test suite can boot the app without missing
 * class fatals.
 *
 * Service collaborators arrive as parameters on action methods and
 * `render()`. Constructor injection is banned on Livewire `Component`
 * subclasses by phpstan-strict-rules.
 */
final class ForecastPage extends Component
{
    public function render(ViewFactory $views): View
    {
        return $views->make('forecasting::livewire.forecast-page');
    }
}
