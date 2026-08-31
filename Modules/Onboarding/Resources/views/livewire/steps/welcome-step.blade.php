@use('Modules\Core\Public\Support\Lang')
{{--
    Welcome step — the wizard's first page. Renders the UI-SPEC
    §"Wizard welcome step" copy verbatim (eyebrow + H1 + lede + three
    glyph rows + Continue → CTA). No user input, no validation; the
    only interaction is the Continue button which dispatches
    `wizard.step.completed` up to the SetupWizard parent.

    The three glyph rows are rendered through the
    `<x-onboarding::vd-glyph>` atomic component so the same primitive is
    shared with the connector-step welcome surfaces and with any future
    page that needs a 44×44 tile + title + description row pattern.
--}}
<section class="wiz-step" aria-labelledby="wiz-welcome-h1">
    <p class="wiz-eyebrow">{{ Lang::get('onboarding::welcome.eyebrow') }}</p>
    <h1 id="wiz-welcome-h1" class="wiz-h1">
        {{ Lang::get('onboarding::welcome.h1') }}
    </h1>
    <p class="wiz-lede">
        {{ Lang::get('onboarding::welcome.lede') }}
    </p>

    <p class="wiz-tagline">{{ Lang::get('onboarding::welcome.tagline') }}</p>

    <ul class="vd-rows" role="list">
        <x-onboarding::vd-glyph
            glyph="🏦"
            :title="Lang::get('onboarding::welcome.bank_title')"
            :description="Lang::get('onboarding::welcome.bank_desc')"
        />
        <x-onboarding::vd-glyph
            glyph="💳"
            :title="Lang::get('onboarding::welcome.card_title')"
            :description="Lang::get('onboarding::welcome.card_desc')"
        />
        <x-onboarding::vd-glyph
            glyph="✉️"
            :title="Lang::get('onboarding::welcome.email_title')"
            :description="Lang::get($onPhone ? 'onboarding::welcome.email_desc_phone' : 'onboarding::welcome.email_desc')"
            :optional="true"
        />
    </ul>

    <x-onboarding::wiz-actions>
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="continue"
        >
            {{ Lang::get('onboarding::welcome.continue') }}
        </button>
    </x-onboarding::wiz-actions>
</section>
