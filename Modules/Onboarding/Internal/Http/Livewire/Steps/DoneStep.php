<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Onboarding\Public\Events\WizardCompleted;

/**
 * @link ../../../../../../.docs/features/onboarding/architecture.md
 */
final class DoneStep extends Component
{
    public function finish(
        Dispatcher $events,
        CurrentUser $currentUser,
    ): mixed {
        $events->dispatch(new WizardCompleted($currentUser->id()));

        // $this->redirect(...) so the wire:click navigation actually
        // fires; a plain Redirector::to(...) response is dropped by
        // Livewire's action dispatcher.
        return $this->redirect('/');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.done-step');
    }
}
