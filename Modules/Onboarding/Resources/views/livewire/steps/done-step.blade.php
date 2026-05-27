{{--
    Done step — the wizard's final page. Renders the UI-SPEC §"Wizard
    final step" copy verbatim (eyebrow + H1 + lede + three next-step
    rows + "Open dashboard →" primary CTA). Clicking the CTA fires the
    WizardCompleted event and redirects the user to /.

    The action row uses the `<x-onboarding::wiz-actions>` primitive so
    the right-aligned spacing matches every other wizard step.
--}}
<section class="wiz-step wiz-step-done" aria-labelledby="wiz-done-h1">
    <p class="wiz-eyebrow">Done</p>
    <h1 id="wiz-done-h1" class="wiz-h1">
        beatrax knows your money.
    </h1>
    <p class="wiz-lede">
        Your data is ready. Here's what to do next.
    </p>

    <ul class="wiz-next-steps" role="list">
        <li class="wiz-next-step">
            <span class="wiz-next-step-label">Review your first month</span>
            <span class="wiz-next-step-target">→ opens dashboard</span>
        </li>
        <li class="wiz-next-step">
            <span class="wiz-next-step-label">Confirm auto-suggested categories</span>
            <span class="wiz-next-step-target">→ opens triage</span>
        </li>
        <li class="wiz-next-step">
            <span class="wiz-next-step-label">Recovery codes — print them now</span>
        </li>
    </ul>

    <x-onboarding::wiz-actions>
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="finish"
        >
            Open dashboard →
        </button>
    </x-onboarding::wiz-actions>
</section>
