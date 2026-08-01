{{--
    Connector-step eyebrow — the "💳 Step 4 — Your credit card (ICS)" line
    above each step's h1.

    The step number is resolved from WizardStepRegistry rather than written
    into each step blade, because hardcoded numbers silently drift the
    moment a step is inserted: connect-card and connect-paypal both read
    "Step 3" while the header counted nine, and every step after the bank
    was off by one.

    Props:
      :step   — the registry step key ('connect-card'), NOT a number.
      :glyph  — leading emoji, decorative only.
      slot    — the step's own label ("Your credit card (ICS)").
--}}
@use('Modules\Core\Public\Support\Lang')
@props([
    'step',
    'glyph' => '',
])
@php
    $wizardSteps = app(\Modules\Onboarding\Internal\Services\WizardStepRegistry::class)->steps();
    $stepIndex = array_search($step, $wizardSteps, strict: true);
    $stepNumber = $stepIndex === false ? null : $stepIndex + 1;
@endphp

<p {{ $attributes->class(['wiz-eyebrow']) }}>
    @if ($glyph !== '')<span aria-hidden="true">{{ $glyph }}</span> @endif
    @if ($stepNumber !== null){{ Lang::get('onboarding::components.eyebrow_step', ['number' => $stepNumber]) }}@endif{{ $slot }}
</p>
