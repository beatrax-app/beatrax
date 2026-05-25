{{--
    Parent view for the first-run setup wizard. Renders the wizard
    chrome (top brand row + progress dots + resume-later affordance),
    the body (step component switched by $currentStepKey), and the
    footer privacy pill + help link. The active step is mounted as a
    nested Livewire component so each step owns its own state without
    contaminating the parent.

    Plan 03a lands the two static bookend steps (welcome + done).
    The four connector steps (connect-bank, connect-card, connect-
    email, first-import) are slotted by Plan 03b — until then a
    coherent "step pending" placeholder renders for those keys so a
    user who lands on a connector step (e.g. a resumed session)
    sees a calm explanation rather than a Livewire mount exception.

    The progress strip is rendered server-side from $progress (built
    by WizardProgressQuery::list) so its order matches WizardStep
    Registry's canonical sequence. Tampering with the `currentStepKey`
    public property is bounded by SetupWizard::goToStep — every prior
    step must be done|skipped before a step is reachable.
--}}
<div class="wiz-page">
    <header class="wiz-top" aria-label="Setup wizard header">
        <div class="wiz-brand">
            <span class="wiz-brand-mark" aria-hidden="true">d</span>
            <span class="wiz-brand-name">diederik</span>
        </div>

        <nav class="wiz-dots" aria-label="Setup progress">
            @php
                $stepKeys = array_keys($progress);
                $totalSteps = count($stepKeys);
                $currentIndex = array_search($currentStepKey, $stepKeys, strict: true);
                $currentStepNumber = $currentIndex === false ? 1 : ($currentIndex + 1);
            @endphp
            @foreach ($stepKeys as $index => $stepKey)
                @php
                    $status = $progress[$stepKey]['status'] ?? 'pending';
                    $isCurrent = $stepKey === $currentStepKey;
                    $dotClass = match (true) {
                        $isCurrent => 'wiz-dot now',
                        $status === 'done', $status === 'skipped' => 'wiz-dot done',
                        default => 'wiz-dot',
                    };
                @endphp
                <span
                    class="{{ $dotClass }}"
                    aria-label="Step {{ $index + 1 }} of {{ $totalSteps }}"
                    @if ($isCurrent) aria-current="step" @endif
                ></span>
            @endforeach
            <span class="wiz-dots-label">
                Step {{ $currentStepNumber }} of {{ $totalSteps }}
            </span>
        </nav>

        <button
            type="button"
            class="wiz-resume-later"
            wire:click="skipRest"
            aria-label="Resume the setup wizard later — saves your progress"
        >
            Resume later →
        </button>
    </header>

    <main class="wiz-body">
        <article class="wiz-card">
            @if ($isResuming)
                <div class="wiz-resume-banner" role="status">
                    Welcome back — let's pick up where you left off.
                </div>
            @endif

            @switch ($currentStepKey)
                @case ('welcome')
                    <livewire:onboarding.steps.welcome-step :key="'welcome'" />
                    @break

                @case ('done')
                    <livewire:onboarding.steps.done-step :key="'done'" />
                    @break

                @case ('connect-bank')
                @case ('connect-card')
                @case ('connect-email')
                @case ('first-import')
                    {{--
                        Connector steps are landed by Plan 03b. Until they
                        ship, render a coherent placeholder so a user
                        resumed on a connector step (or a tester hitting
                        the wizard with manual progress writes) sees a
                        calm explanation rather than a Livewire mount
                        exception. The placeholder copy stays specific
                        to the active step so the user understands which
                        connector is pending.
                    --}}
                    <div class="wiz-step-pending">
                        <p class="wiz-step-pending-eyebrow">Step pending</p>
                        <h1 class="wiz-step-pending-h1">
                            The <code>{{ $currentStepKey }}</code> step is being prepared.
                        </h1>
                        <p class="wiz-step-pending-lede">
                            This connector step lands in a follow-up plan; the wizard's
                            structural backbone is in place but the per-connector UI is
                            not yet wired. Use Resume later → to exit the wizard cleanly.
                        </p>
                    </div>
                    @break

                @default
                    <div class="wiz-step-pending">
                        <p class="wiz-step-pending-eyebrow">Unknown step</p>
                        <h1 class="wiz-step-pending-h1">
                            No step is currently active.
                        </h1>
                        <p class="wiz-step-pending-lede">
                            The wizard could not resolve the active step. Use Resume
                            later → to exit and the next mount will recover.
                        </p>
                    </div>
            @endswitch
        </article>
    </main>

    <footer class="wiz-footer">
        <span class="privacy-pill" aria-label="Your data stays on this computer">
            <span class="privacy-pill-dot" aria-hidden="true"></span>
            Your data stays on this computer
        </span>
        <a class="wiz-help-link" href="#" aria-label="Open help">
            Need help?
        </a>
    </footer>
</div>
