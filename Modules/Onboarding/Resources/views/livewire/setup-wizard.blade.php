@use('Modules\Core\Public\Support\Lang')
{{--
    Parent view for the first-run setup wizard. Renders the wizard
    chrome (top brand row + progress dots + resume-later affordance),
    the body (step component switched by $currentStepKey), and the
    footer privacy pill + help link. The active step is mounted as a
    nested Livewire component so each step owns its own state without
    contaminating the parent.

    The wizard registers nine step keys: two static bookend steps
    (welcome + done), five connector/import steps (connect-bank,
    connect-paypal, connect-card, connect-email, first-import), and the
    optional budgets + tax-country steps. Each step is mounted as a Livewire component via the $currentStepKey
    switch below; a user landing on a step whose component is not yet
    registered sees a coherent "step pending" placeholder rather than
    a mount exception.

    The progress strip is rendered server-side from $progress (built
    by WizardProgressQuery::list) so its order matches WizardStep
    Registry's canonical sequence. Tampering with the `currentStepKey`
    public property is bounded by SetupWizard::goToStep — every prior
    step must be done|skipped before a step is reachable.
--}}
<div class="wiz-page">
    <header class="wiz-top" aria-label="{{ Lang::get('onboarding::wizard.header_aria') }}">
        <div class="wiz-brand">
            <img
                src="{{ Vite::asset('resources/brand/logo.svg') }}"
                alt="Beatrax"
                width="22"
                height="22"
                class="wiz-brand-mark logo-svg"
            />
            <span class="wiz-brand-name">Beatrax</span>
        </div>

        <nav class="wiz-dots" aria-label="{{ Lang::get('onboarding::wizard.progress_aria') }}">
            @php
                $stepKeys = array_keys($progress);
                $totalSteps = count($stepKeys);
                $currentIndex = array_search($currentStepKey, $stepKeys, strict: true);
                $currentStepNumber = $currentIndex === false ? 1 : ($currentIndex + 1);
            @endphp
            @foreach ($stepKeys as $index => $stepKey)
                @php
                    $status = $progress[$stepKey]['status'] ?? \Modules\Onboarding\Public\Enums\WizardStepStatus::Pending->value;
                    $isCurrent = $stepKey === $currentStepKey;
                    $dotClass = match (true) {
                        $isCurrent => 'wiz-dot now',
                        $status === \Modules\Onboarding\Public\Enums\WizardStepStatus::Done->value, $status === \Modules\Onboarding\Public\Enums\WizardStepStatus::Skipped->value => 'wiz-dot done',
                        default => 'wiz-dot',
                    };
                @endphp
                {{-- The dots carry no aria-label: each one would repeat what the
                     .wiz-dots-label text beside them already says, so labelling every
                     dot made a screen reader read the step count once per dot. --}}
                <span
                    class="{{ $dotClass }}"
                    @if ($isCurrent) aria-current="step" @endif
                ></span>
            @endforeach
            <span class="wiz-dots-label">
                {{ Lang::get('onboarding::wizard.step_progress', ['current' => $currentStepNumber, 'total' => $totalSteps]) }}
            </span>
        </nav>

        <button
            type="button"
            class="wiz-resume-later"
            wire:click="skipRest"
            aria-label="{{ Lang::get('onboarding::wizard.resume_later_aria') }}"
        >
            {{ Lang::get('onboarding::wizard.resume_later') }} <span aria-hidden="true">→</span>
        </button>
    </header>

    <main class="wiz-body">
        @php
            // UI-SPEC §"Density rules" locked exception: the first-
            // import step owns its own 1120px-wide wiz-card wrapper
            // (rendered inside the step blade) so the preview table
            // gets the room it needs. Every other step uses the
            // default 620px card and the parent renders the wrapper
            // here. The `$selfWrapping` branch avoids double-nesting
            // wiz-card elements for the first-import variant.
            $selfWrapping = $currentStepKey === 'first-import';
        @endphp
        @if ($selfWrapping)
            @if ($isResuming)
                <div class="wiz-resume-banner-floating" aria-live="polite" aria-atomic="true">
                    {{ Lang::get('onboarding::wizard.resume_banner') }}
                </div>
            @endif
            <livewire:onboarding.steps.first-import-step :key="'first-import'" />
        @else
            <article class="wiz-card">
                @if ($isResuming)
                    <div class="wiz-resume-banner" aria-live="polite" aria-atomic="true">
                        {{ Lang::get('onboarding::wizard.resume_banner') }}
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
                        <livewire:onboarding.steps.connect-bank-step :key="'connect-bank'" />
                        @break

                    @case ('connect-paypal')
                        <livewire:onboarding.steps.connect-paypal-step :key="'connect-paypal'" />
                        @break

                    @case ('connect-card')
                        <livewire:onboarding.steps.connect-card-step :key="'connect-card'" />
                        @break

                    @case ('connect-email')
                        <livewire:onboarding.steps.connect-email-step :key="'connect-email'" />
                        @break

                    @case ('budgets')
                        <livewire:onboarding.steps.budgets-step :key="'budgets'" />
                        @break

                    @case ('tax-country')
                        <livewire:onboarding.steps.tax-country-step :key="'tax-country'" />
                        @break

                    @default
                        <div class="wiz-step-pending">
                            <p class="wiz-step-pending-eyebrow">{{ Lang::get('onboarding::wizard.unknown_eyebrow') }}</p>
                            <h1 class="wiz-step-pending-h1">
                                {{ Lang::get('onboarding::wizard.unknown_h1') }}
                            </h1>
                            <p class="wiz-step-pending-lede">
                                {{ Lang::get('onboarding::wizard.unknown_lede') }}
                            </p>
                        </div>
                @endswitch
            </article>
        @endif
    </main>

    <footer class="wiz-footer">
        <span class="privacy-pill">
            <span class="privacy-pill-dot" aria-hidden="true"></span>
            {{ Lang::get('onboarding::wizard.privacy') }}
        </span>
        <a
            class="wiz-help-link"
            href="{{ config('community.github_issues_url') }}"
            wire:click.prevent="openHelp"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="{{ Lang::get('onboarding::wizard.need_help_aria') }}"
        >
            {{ Lang::get('onboarding::wizard.need_help') }}
        </a>
    </footer>
</div>
