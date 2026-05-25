<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Onboarding\Public\Events\WizardCompleted;

/**
 * The wizard's final step — the static "Done" page that confirms setup
 * is complete and points the user at the dashboard. Renders the
 * UI-SPEC §"Wizard final step" copy (eyebrow + H1 + lede + three next-
 * step rows) and exposes a single `finish` action that dispatches the
 * `WizardCompleted` event and redirects the user to the dashboard.
 *
 * Skipping the wizard (parent SetupWizard's `skipRest` action) also
 * dispatches `WizardCompleted`; this step is the happy-path exit. Both
 * paths land the user on `/` so downstream listeners can hook into a
 * single canonical "wizard is over" signal.
 */
final class DoneStep extends Component
{
    /**
     * Dispatches the `WizardCompleted` event so any listener (e.g. local
     * telemetry, post-wizard navigation hooks) sees the completion
     * signal, then returns a redirect response pointing at the dashboard
     * root.
     *
     * The wizard's last step row (`done` step_key) is marked `done` +
     * `completed_at` by the SetupWizard parent's `next` handler before
     * the user arrives at this view — finishing here only fires the
     * post-wizard event and exits the wizard.
     */
    public function finish(
        Dispatcher $events,
        CurrentUser $currentUser,
        Redirector $redirector,
    ): RedirectResponse {
        $events->dispatch(new WizardCompleted($currentUser->id()));

        return $redirector->to('/');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.done-step');
    }
}
