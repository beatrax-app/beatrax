<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/dev/horizon` — thin Livewire wrapper that renders an iframe
 * pointing at the package-provided Horizon dashboard.
 *
 * Route conditionally registered behind a two-signal guard:
 *   - `config('app.dev_mode') === true` (env-pinned).
 *   - `class_exists(\Laravel\Horizon\HorizonServiceProvider::class)`
 *     (Horizon is `require-dev`; absent in shipped `--no-dev` bundles).
 *
 * When either signal is false the route never registers; the
 * dev-shell sidebar's `Route::has('dev.horizon')` check then drops
 * the nav item entirely (DOM-absent, not nav-disabled). Non-developers
 * receive a 404 because of the surrounding `ensureDeveloperMode`
 * middleware regardless.
 */
#[Layout('dev::layouts.dev-shell')]
final class HorizonFramePage extends Component
{
    public function render(ViewFactory $views): View
    {
        return $views->make('dev::livewire.horizon-frame-page');
    }
}
