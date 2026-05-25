{{--
    Welcome step — the wizard's first page. Renders the UI-SPEC
    §"Wizard welcome step" copy verbatim (eyebrow + H1 + lede + three
    glyph rows + Continue → CTA). No user input, no validation; the
    only interaction is the Continue button which dispatches
    `wizard.step.completed` up to the SetupWizard parent.

    Plan 03b replaces the inline glyph-row markup with the bespoke
    `<x-onboarding::vd-glyph>` component; for Plan 03a the raw Tailwind
    markup keeps the welcome step self-rendering even before the Blade
    component library lands.
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
        <li class="vd-row">
            <span class="vd-glyph" aria-hidden="true">🏦</span>
            <div class="vd-row-text">
                <span class="vd-row-title">Your bank (ASN)</span>
                <span class="vd-row-desc">Drop a statement file. We'll show you exactly where to find it.</span>
            </div>
        </li>
        <li class="vd-row">
            <span class="vd-glyph" aria-hidden="true">💳</span>
            <div class="vd-row-text">
                <span class="vd-row-title">Your credit card (ICS)</span>
                <span class="vd-row-desc">Drop the monthly PDF statement from Mijn ICS.</span>
            </div>
        </li>
        <li class="vd-row">
            <span class="vd-glyph" aria-hidden="true">✉️</span>
            <div class="vd-row-text">
                <span class="vd-row-title">
                    Receipts from email
                    <span class="vd-row-optional">— optional</span>
                </span>
                <span class="vd-row-desc">Connect Gmail or Outlook to capture purchase confirmations automatically.</span>
            </div>
        </li>
    </ul>

    <div class="wiz-actions">
        <button
            type="button"
            class="pill-btn pill-btn-primary"
            wire:click="continue"
        >
            Continue →
        </button>
    </div>
</section>
