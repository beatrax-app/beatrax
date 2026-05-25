{{--
    Connect-bank step — wizard step 2. Renders the UI-SPEC §"Wizard
    connector step — bank (ASN)" copy verbatim (eyebrow, H1, lede,
    four-tile mini-step row, format-chip row, drop-zone, error region,
    actions). The same `RunsImports` pipeline that powers /imports
    consumes the uploaded file; on success the parent SetupWizard
    advances via the `wizard.step.completed` event the submit() method
    dispatches.

    Format chips: CAMT.053 is the recommended chip (emerald-tinted via
    `<x-onboarding::format-chip :recommended="true">`); CSV and MT940
    are equal-weight muted alternates per the sketch.
--}}
<section class="wiz-step wiz-step-connect-bank" aria-labelledby="wiz-connect-bank-h1">
    <p class="wiz-eyebrow">🏦 Step 2 — Your bank (ASN)</p>
    <h1 id="wiz-connect-bank-h1" class="wiz-h1">
        Grab a statement, then drop it below
    </h1>
    <p class="wiz-lede">
        Four small steps. You'll be in your bank's site for about a minute.
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" label="Log in" sub="mijn.asnbank.nl" state="done" />
        <x-onboarding::mini-step glyph="📑" label="Open afschriften" sub="Top-right menu" state="done" />
        <x-onboarding::mini-step glyph="📅" label="Pick a range" sub="Last 90 days" state="now" />
        <x-onboarding::mini-step glyph="⬇️" label="Download" sub="CAMT.053 or CSV" state="upcoming" />
    </div>

    <div class="format-chips" aria-label="Pick the format your statement was downloaded in">
        <span class="format-chips-label">Got it as:</span>
        <button type="button" class="format-chip-button" wire:click="setFormat('asn-camt053')">
            <x-onboarding::format-chip label="CAMT.053" badge="recommended" :recommended="$selectedFormat === 'asn-camt053'" />
        </button>
        <button type="button" class="format-chip-button" wire:click="setFormat('asn-csv')">
            <x-onboarding::format-chip label="CSV" :recommended="$selectedFormat === 'asn-csv'" />
        </button>
        <button type="button" class="format-chip-button" wire:click="setFormat('asn-mt940')">
            <x-onboarding::format-chip label="MT940" :recommended="$selectedFormat === 'asn-mt940'" />
        </button>
    </div>

    <x-onboarding::drop-zone
        wire-model="file"
        lead="Drop your statement file here"
        sublink="or browse for a file"
        glyph="📥"
        accept=".csv,.xml,.sta,.mt940,.940,.txt"
    />

    @if ($file !== null)
        <p class="wiz-file-ready">
            {{ $file->getClientOriginalName() }} · ✓ ready
        </p>
    @endif

    @error('file')
        <p class="wiz-error" role="alert">{{ $message }}</p>
    @enderror

    @if ($uploadError !== null)
        <p class="wiz-error" role="alert">{{ $uploadError }}</p>
    @endif

    <x-onboarding::wiz-actions>
        <button
            type="button"
            class="pill-btn-ghost"
            wire:click="skip"
        >
            Skip this step
        </button>
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="submit"
            wire:loading.attr="disabled"
        >
            Continue →
        </button>
    </x-onboarding::wiz-actions>
</section>
