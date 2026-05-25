<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Parent Livewire component that hosts the first-run wizard's surface.
 *
 * This file lands the class definition so the `/setup` route binding +
 * the post-signup redirect can resolve. The full state-machine
 * behaviour (resume detection, force-reset, step advancement) is wired
 * by the wizard backbone build that owns the rendered surface.
 */
final class SetupWizard extends Component
{
    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.setup-wizard-placeholder');
    }
}
