@use('Modules\Core\Public\Support\Lang')
{{--
    Done step — the wizard's final page. Renders the UI-SPEC §"Wizard
    final step" copy verbatim (eyebrow + H1 + lede + three next-step
    rows + "Open dashboard →" primary CTA). Clicking the CTA fires the
    WizardCompleted event and redirects the user to /.

    The action row uses the `<x-onboarding::wiz-actions>` primitive so
    the right-aligned spacing matches every other wizard step.
--}}
<section class="wiz-step wiz-step-done" aria-labelledby="wiz-done-h1">
    <p class="wiz-eyebrow">{{ Lang::get('onboarding::done.eyebrow') }}</p>
    <h1 id="wiz-done-h1" class="wiz-h1">
        {{ Lang::get('onboarding::done.h1') }}
    </h1>
    <p class="wiz-lede">
        {{ Lang::get('onboarding::done.lede') }}
    </p>

    <ul class="wiz-next-steps" role="list">
        <li class="wiz-next-step">
            <span class="wiz-next-step-label">{{ Lang::get('onboarding::done.review_label') }}</span>
            <span class="wiz-next-step-target">{{ Lang::get('onboarding::done.review_target') }}</span>
        </li>
        <li class="wiz-next-step">
            <span class="wiz-next-step-label">{{ Lang::get('onboarding::done.confirm_label') }}</span>
            <span class="wiz-next-step-target">{{ Lang::get('onboarding::done.confirm_target') }}</span>
        </li>
        <li class="wiz-next-step">
            <span class="wiz-next-step-label">{{ Lang::get('onboarding::done.recovery_label') }}</span>
        </li>
    </ul>

    <x-onboarding::wiz-actions>
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="finish"
        >
            {{ Lang::get('onboarding::done.open_dashboard') }}
        </button>
    </x-onboarding::wiz-actions>
</section>
