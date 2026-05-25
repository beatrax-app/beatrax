<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Onboarding\Internal\Services\ResumeStepResolver;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;
use Modules\Onboarding\Public\Services\WizardProgressQuery;
use Psr\Log\LoggerInterface;

/**
 * Parent Livewire component for the first-run setup wizard. Owns the
 * `currentStepKey` state, the rendered progress dots, and the dispatch
 * chain that advances the user through the six canonical wizard steps
 * (welcome, connect-bank, connect-card, connect-email, first-import,
 * done).
 *
 * On mount the component:
 *   - Re-runs WizardProgressInitializer (idempotent safety net so the
 *     wizard renders even if the UserInstalled listener missed).
 *   - If `?force=1` is set, resets every wizard_progress row for the
 *     current user to `status=pending` + `completed_at=null` so the
 *     wizard restarts cleanly from step 1 (this is the "Settings → re-
 *     run setup" affordance).
 *   - Resolves the resume step via ResumeStepResolver. The resolver's
 *     three-branch contract: any `in_progress` row wins; else the first
 *     `pending` row in registry order; else the empty-string sentinel
 *     meaning "all done/skipped — bounce to /".
 *   - Marks `isResuming` true when the user is not on the first step
 *     and `force=1` is absent so the rendered view can show the
 *     "Welcome back" banner.
 *
 * Step advancement is driven by Livewire dispatch from the active step
 * component:
 *   - `wizard.step.completed` → mark the current row `done` + advance.
 *   - `wizard.step.skipped`  → mark the current row `skipped` + advance
 *     (no-op on non-skippable steps via WizardStepRegistry::isSkippable).
 *
 * The user can also bail out of the entire wizard via `skipRest`, which
 * marks every non-done row `skipped` + redirects to `/`.
 *
 * Per the DI-only rule: every collaborator is method-DI'd on the
 * action methods (Livewire forbids constructor DI). No `redirect()` /
 * `now()` / `request()` / `auth()` helpers are called; the `Redirector`,
 * `Clock`, `Request`, and `CurrentUser` collaborators arrive through
 * method DI.
 */
#[Layout('onboarding::layouts.app-wizard')]
final class SetupWizard extends Component
{
    /**
     * The active wizard step's key — one of the six WizardStepRegistry
     * step keys, or the empty string when the wizard is being unmounted
     * via the "all done → bounce to /" sentinel branch.
     */
    public string $currentStepKey = 'welcome';

    /**
     * Per-user progress snapshot keyed by step_key in registry order.
     * Rendered as the wizard's six progress dots; rebuilt on every
     * mount so client-side property tampering is overwritten on the
     * next page render.
     *
     * @var array<string, array{status: string, completed_at: ?string}>
     */
    public array $progress = [];

    /**
     * True when the user is re-entering the wizard mid-flow (i.e. the
     * resume step is anything other than `welcome` AND the request did
     * not pass `?force=1`). Drives the "Welcome back — let's pick up
     * where you left off" banner.
     */
    public bool $isResuming = false;

    /**
     * True when every step is `done` or `skipped`. The wizard renders
     * the done step in this case rather than redirecting, so a user
     * who finishes via `skipRest` and then hits the wizard URL again
     * sees a coherent "you're done" page (the next mount call handles
     * the actual redirect when this state persists).
     */
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

        // Idempotent safety net — if the UserInstalled listener missed
        // (e.g. a user landed in this view through a manual URL hit
        // before the seeder ran), seed the six rows now.
        $initializer->initialize($user->id);

        if ($request->boolean('force')) {
            // Log a `force=1` hit when no row was in-progress — i.e.
            // the user is resetting a wizard they had not yet started.
            // The reset itself stays a no-op-equivalent (all rows are
            // pending already) but the log line surfaces tampered or
            // bookmarked URLs that have no functional effect.
            $hadInProgress = (bool) $db->connection()
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
            // All steps done|skipped — exit the wizard for real.
            // Livewire 3 honours `$this->redirect(...)` from mount();
            // a plain `RedirectResponse` return is ignored and the
            // component continues to render the wizard page (which
            // would land the user on a stale "welcome" view despite
            // every step being complete). The same pattern is used by
            // Modules/EmailScan/.../InboxesPage::reconnect().
            return $this->redirect('/');
        }

        $this->isResuming = $resumeKey !== 'welcome' && ! $request->boolean('force');
        $this->currentStepKey = $resumeKey;
        $this->allComplete = false;

        return null;
    }

    /**
     * Direct-jump to a specific step. Guards against client-side
     * property tampering (`<input wire:model="currentStepKey">`-style
     * attacks): the requested step must be in the registry AND every
     * step ordered before it must be `done` or `skipped`. A user can
     * always walk back to an earlier completed step; they cannot leap
     * ahead.
     */
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

    /**
     * Mark the current step `done` + advance to the next registry step.
     * Bound to the `wizard.step.completed` Livewire event so any active
     * step component can announce completion without a parent-method
     * call chain.
     */
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

    /**
     * Mark the current step `skipped` + advance to the next registry
     * step. Bound to the `wizard.step.skipped` event. Non-skippable
     * steps (welcome, first-import, done) silently no-op; the skip
     * button is hidden in the view for those steps, but the server-side
     * guard is the load-bearing check.
     */
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

    /**
     * Mark every non-done row `skipped` + redirect to `/`. The user's
     * "Skip the rest" affordance — they exit the wizard but the
     * wizard_progress rows still reflect what they did vs. what they
     * dropped, so a future `/setup-wizard` hit lands on the done step
     * rather than restarting.
     */
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

        // Livewire 3 — use `$this->redirect(...)` so the wire:click
        // protocol's response actually triggers a navigation. A plain
        // RedirectResponse return is dropped by Livewire's action
        // dispatcher.
        return $this->redirect('/');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.setup-wizard');
    }

    /**
     * Shared helper for next/skip — marks the current row with the
     * given terminal status, then walks the registry to find the next
     * pending step. When the registry is exhausted (every step is
     * done/skipped), advance leaves `currentStepKey` on the `done` step
     * (the wizard's terminal page) and flips `allComplete` true.
     */
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
