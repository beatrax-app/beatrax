<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\AnnouncesStepChanges;
use Modules\Onboarding\Internal\Enums\WizardStepStatus;
use Modules\Onboarding\Internal\Services\ResumeStepResolver;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Internal\Services\WizardProgressQuery;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;
use Psr\Log\LoggerInterface;

#[Layout('onboarding::layouts.app-wizard')]
final class SetupWizard extends Component
{
    // A step change is a re-render of one page, not a navigation, so nothing
    // resets the scroll: step 3 opened 424px down, past its own heading, with
    // the wizard chrome dragged under the status bar. The trait names the
    // event and the bundle carries the one listener that acts on it.
    use AnnouncesStepChanges;

    public string $currentStepKey = 'welcome';

    // Rebuilt on every mount, so tampering with it client-side survives
    // only until the next page render.
    /** @var array<string, array{status: string, completed_at: ?string}> */
    public array $progress = [];

    public bool $isResuming = false;

    // Renders the done step rather than redirecting, so a later hit on the
    // URL shows a coherent page instead of restarting the wizard.
    public bool $allComplete = false;

    public function mount(
        ResumeStepResolver $resolver,
        WizardProgressQuery $query,
        WizardProgressInitializer $initializer,
        WizardStepRegistry $registry,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Request $request,
        Clock $clock,
        LoggerInterface $logger,
    ): void {
        $user = $currentUser->user();

        // Safety net for a manual URL hit that raced the UserInstalled
        // listener; initialize() is idempotent.
        $initializer->initialize($user->id);

        if ($request->boolean('force')) {
            // The log line surfaces a bookmarked ?force=1 URL: with nothing
            // yet in progress the reset itself is a no-op.
            $hadInProgress = $db->connection()
                ->table('wizard_progress')
                ->where('user_id', $user->id)
                ->whereIn('status', [WizardStepStatus::InProgress->value, WizardStepStatus::Done->value, WizardStepStatus::Skipped->value])
                ->exists();
            if (! $hadInProgress) {
                $logger->info('SetupWizard: ?force=1 hit while no wizard step had progressed.', [
                    'user_id' => $user->id,
                ]);
            }

            $db->connection()
                ->table('wizard_progress')
                ->where('user_id', $user->id)
                ->update([
                    'status' => WizardStepStatus::Pending->value,
                    'completed_at' => null,
                    'updated_at' => $clock->now()->toDateTimeString(),
                ]);
        }

        $this->progress = $query->list($user->id);

        $resumeKey = $resolver->resolve($user->id);

        if ($resumeKey === '') {
            // Nothing pending, nothing in progress: the state advance()
            // reaches after the last step. Rendering it, rather than
            // redirecting, is load-bearing on the mobile runtime.
            // @link ../../../../../.docs/conventions/invariants-from-shipped-failures.md#a-livewire-redirect-from-mount
            $this->currentStepKey = $registry->lastStep();
            $this->allComplete = true;
            $this->isResuming = false;

            return;
        }

        $this->isResuming = $resumeKey !== 'welcome' && ! $request->boolean('force');
        $this->currentStepKey = $resumeKey;
        $this->allComplete = false;
    }

    public function goToStep(string $stepKey, WizardStepRegistry $registry, CurrentUser $currentUser, WizardProgressQuery $query): void
    {
        $steps = $registry->steps();
        $targetIndex = array_search($stepKey, $steps, strict: true);
        if ($targetIndex === false) {
            return;
        }

        $progress = $query->list($currentUser->id());

        for ($i = 0; $i < $targetIndex; $i++) {
            $priorStep = $steps[$i];
            $priorStatus = $progress[$priorStep]['status'] ?? WizardStepStatus::Pending->value;
            if ($priorStatus !== WizardStepStatus::Done->value && $priorStatus !== WizardStepStatus::Skipped->value) {
                return;
            }
        }

        $this->currentStepKey = $stepKey;
        $this->progress = $progress;
        $this->announceStepChange();
    }

    #[On('wizard.step.completed')]
    public function next(
        DatabaseManager $db,
        CurrentUser $currentUser,
        WizardStepRegistry $registry,
        WizardProgressQuery $query,
        Clock $clock,
    ): void {
        $this->advance($db, $currentUser, $registry, $query, $clock, WizardStepStatus::Done->value);
    }

    // The view hides skip on non-skippable steps; this guard is the
    // load-bearing one.
    #[On('wizard.step.skipped')]
    public function skip(
        DatabaseManager $db,
        CurrentUser $currentUser,
        WizardStepRegistry $registry,
        WizardProgressQuery $query,
        Clock $clock,
    ): void {
        if (! $registry->isSkippable($this->currentStepKey)) {
            return;
        }

        $this->advance($db, $currentUser, $registry, $query, $clock, WizardStepStatus::Skipped->value);
    }

    // "Resume later", whose aria-label promises it saves your progress. It
    // writes nothing: the row it used to mark skipped is the one the reader
    // is coming back to, and on a phone that abandoned 229 parsed
    // transactions waiting to be committed.
    /**
     * @link ../../../../../.docs/features/onboarding/architecture.md#leaving-the-wizard
     */
    public function leaveForNow(): mixed
    {
        // Livewire drops a RedirectResponse returned from an action, so
        // navigation has to go through $this->redirect() or it silently no-ops.
        return $this->redirect('/');
    }

    // Wired to wire:click.prevent so Electron does not navigate the wizard window
    // away from /setup-wizard. The URL still clears OpenExternalUrlAction's
    // https + host allow-list before NativePHP's shell contract sees it.
    public function openHelp(
        OpenExternalUrlAction $opener,
        ConfigRepository $config,
    ): void {
        $url = $config->get('community.github_issues_url');
        if (! is_string($url) || $url === '') {
            return;
        }
        $opener($url);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.setup-wizard');
    }

    private function advance(
        DatabaseManager $db,
        CurrentUser $currentUser,
        WizardStepRegistry $registry,
        WizardProgressQuery $query,
        Clock $clock,
        string $terminalStatus,
    ): void {
        $userId = $currentUser->id();
        $now = $clock->now()->toDateTimeString();

        $db->connection()
            ->table('wizard_progress')
            ->where('user_id', $userId)
            ->where('step_key', $this->currentStepKey)
            ->update([
                'status' => $terminalStatus,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        $steps = $registry->steps();
        $currentIndex = array_search($this->currentStepKey, $steps, strict: true);
        $nextIndex = $currentIndex === false ? null : $currentIndex + 1;

        if ($nextIndex !== null && isset($steps[$nextIndex])) {
            $this->currentStepKey = $steps[$nextIndex];
        } else {
            $this->allComplete = true;
        }

        $this->isResuming = false;
        $this->progress = $query->list($userId);
        $this->announceStepChange();
    }
}
