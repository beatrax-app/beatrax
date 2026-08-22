<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire\Concerns;

use Livewire\Component;

// The seam every component whose steps share one page announces a step change
// through. Advancing re-renders the body and never navigates, so the browser
// hands the next step the offset the previous one was left at; one name here
// and one listener in resources/js/app.js is what puts the page back at 0.
/**
 * @phpstan-require-extends Component
 */
trait AnnouncesStepChanges
{
    public const string STEP_CHANGED_EVENT = 'step-changed';

    protected function announceStepChange(): void
    {
        $this->dispatch(self::STEP_CHANGED_EVENT);
    }
}
