{{--
    Wizard progress strip — renders the six per-step dots and the
    inline "Step N of M" label.

    Props:
      :progress — array<string, array{status: string, completed_at: ?string}>
                  keyed by step_key in WizardStepRegistry order.
      :current  — the active step_key.

    Per UI-SPEC §"Wizard chrome": 8px circle for upcoming, 8px emerald
    circle for done|skipped, 24×8px rounded rectangle in `--color-text`
    for the active step. Dots are decorative; the inline label carries
    the textual progress signal for screen-reader users.

    Layout/positioning of this strip inside the wizard header is the
    parent's responsibility — this component renders only the dots +
    label cluster so it can be slotted into either the wiz-top header
    or any future progress preview.
--}}
@props([
    'progress' => [],
    'current' => '',
])
@php
    /** @var array<string, array{status: string, completed_at: ?string}> $progress */
    $stepKeys = array_keys($progress);
    $totalSteps = count($stepKeys);
    $currentIndex = array_search($current, $stepKeys, strict: true);
    $currentStepNumber = $currentIndex === false ? 1 : ($currentIndex + 1);
@endphp

<nav {{ $attributes->class(['wiz-dots']) }} aria-label="Setup progress">
    @foreach ($stepKeys as $index => $stepKey)
        @php
            $status = $progress[$stepKey]['status'] ?? \Modules\Onboarding\Public\Enums\WizardStepStatus::Pending->value;
            $isCurrent = $stepKey === $current;
            $dotClass = match (true) {
                $isCurrent => 'dot now',
                $status === \Modules\Onboarding\Public\Enums\WizardStepStatus::Done->value, $status === \Modules\Onboarding\Public\Enums\WizardStepStatus::Skipped->value => 'dot done',
                default => 'dot',
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
