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
<section class="wiz-step wiz-step-welcome" aria-labelledby="wiz-welcome-h1">
    <p class="wiz-eyebrow">Welcome</p>
    <h1 id="wiz-welcome-h1" class="wiz-h1">
        Let's get diederik to know your money.
    </h1>
    <p class="wiz-lede">
        About 5 minutes. Nothing leaves this computer — everything you connect
        stays in a file on your machine.
    </p>

    <p class="wiz-tagline">Here's what we'll set up:</p>

    <ul class="vd-rows" role="list">
        <x-onboarding::vd-glyph
            glyph="🏦"
            title="Your bank (ASN)"
            description="Drop a statement file. We'll show you exactly where to find it."
        />
        <x-onboarding::vd-glyph
            glyph="💳"
            title="Your credit card (ICS)"
            description="Drop the monthly PDF statement from Mijn ICS."
        />
        <x-onboarding::vd-glyph
            glyph="✉️"
            title="Receipts from email"
            description="Connect Gmail or Outlook to capture purchase confirmations automatically."
            :optional="true"
        />
    </ul>

    <x-onboarding::wiz-actions>
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="continue"
        >
            Continue →
        </button>
    </x-onboarding::wiz-actions>
</section>
