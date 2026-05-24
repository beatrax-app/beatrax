<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dev Console overview page (Phase 16-03 D-04).
 *
 * Renders through the `dev::layouts.dev-shell` layout (220px sidebar
 * hard-swap variant of the main app shell). This plan ships the
 * placeholder content only: a single page heading "Overview" + a
 * calm card pointing to the upcoming Wave 5 panels. The Wave 5 plans
 * (16-04 through 16-07) replace the card body with the real tile
 * grid + theme-locked dark `.console-pane` (UI-SPEC § /dev overview
 * surfaces) as each panel lands; the dev-shell layout stays.
 *
 * Pattern B (PATTERNS.md): no constructor on a Livewire Component
 * (phpstan-strict-rules ban); collaborators arrive as method-DI on
 * `render()`. The page is stateless — `mount()` is omitted because
 * there is nothing to seed yet.
 */
#[Layout('dev::layouts.dev-shell')]
final class DevOverviewPage extends Component
{
    public function render(ViewFactory $views): View
    {
        return $views->make('dev::livewire.dev-overview-page');
    }
}
