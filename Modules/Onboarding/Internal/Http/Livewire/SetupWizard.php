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
use Modules\Onboarding\Internal\Services\ResumeStepResolver;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;
use Modules\Onboarding\Public\Services\WizardProgressQuery;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../../.docs/features/onboarding/architecture.md
 */
#[Layout('onboarding::layouts.app-wizard')]
final class SetupWizard extends Component
{
    public string $currentStepKey = 'welcome';

    // Rebuilt on every mount so client-side property tampering is
    // overwritten on the next page render.
    /** @var array<string, array{status: string, completed_at: ?string}> */
    public array $progress = [];

    public bool $isResuming = false;

    // True once every step is done/skipped; the wizard renders the done
    // step rather than redirecting, so a later hit on the URL still
    // shows a coherent page instead of restarting.
    public bool $allComplete = false;

    public function mount(
        ResumeStepResolver $resolver,
        WizardProgressQuery $query,
        WizardProgressInitializer $initializer,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Request $request,
        Clock $clock,
        LoggerInterface $logger,
    ): mixed {
        $user = $currentUser->user();

        // Idempotent safety net if the UserInstalled listener missed
        // (e.g. a manual URL hit before the seeder ran).
        $initializer->initialize($user->id);

        if ($request->boolean('force')) {
            // The log line surfaces tampered/bookmarked URLs when no row
            // had actually progressed yet; the reset itself is a no-op
            // in that case since every row is already pending.
            $hadInProgress = $db->connection()
                ->table('wizard_progress')
                ->where('user_id', $user->id)
                ->whereIn('status', ['in_progress', 'done', 'skipped'])
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
                    'status' => 'pending',
                    'completed_at' => null,
                    'updated_at' => $clock->now()->toDateTimeString(),
                ]);
        }

        $this->progress = $query->list($user->id);

        $resumeKey = $resolver->resolve($user->id);

        if ($resumeKey === '') {
            // Livewire honours $this->redirect(...) from mount(); a plain
            // RedirectResponse return is ignored and the component would
            // render a stale "welcome" view instead.
            return $this->redirect('/');
        }

        $this->isResuming = $resumeKey !== 'welcome' && ! $request->boolean('force');
        $this->currentStepKey = $resumeKey;
        $this->allComplete = false;

        return null;
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
            $priorStatus = $progress[$priorStep]['status'] ?? 'pending';
            if ($priorStatus !== 'done' && $priorStatus !== 'skipped') {
                return;
            }
        }

        $this->currentStepKey = $stepKey;
        $this->progress = $progress;
    }

    #[On('wizard.step.completed')]
    public function next(
        DatabaseManager $db,
        CurrentUser $currentUser,
        WizardStepRegistry $registry,
        WizardProgressQuery $query,
        Clock $clock,
    ): void {
        $this->advance($db, $currentUser, $registry, $query, $clock, 'done');
    }

    // The skip button is hidden in the view for non-skippable steps, but
    // this server-side guard is the load-bearing check.
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

        $this->advance($db, $currentUser, $registry, $query, $clock, 'skipped');
    }

    public function skipRest(
        DatabaseManager $db,
        CurrentUser $currentUser,
        Clock $clock,
    ): mixed {
        $db->connection()
            ->table('wizard_progress')
            ->where('user_id', $currentUser->id())
            ->where('status', '!=', 'done')
            ->update([
                'status' => 'skipped',
                'completed_at' => $clock->now()->toDateTimeString(),
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);

        // $this->redirect(...) so the wire:click protocol's response
        // actually triggers a navigation; a plain RedirectResponse return
        // is dropped by Livewire's action dispatcher.
        return $this->redirect('/');
    }

    // Wired to wire:click.prevent so Electron does not navigate the
    // wizard window away from /setup-wizard; the URL still passes through
    // OpenExternalUrlAction's https + host allow-list before it reaches
    // NativePHP's shell contract.
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

    // Shared helper for next/skip. When the registry is exhausted, leaves
    // currentStepKey on the terminal done step and flips allComplete true.
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

        // The banner only shows on the first mount of a resumed
        // session; once the user advances the wizard, the banner
        // drops away.
        $this->isResuming = false;
        $this->progress = $query->list($userId);
    }
}
