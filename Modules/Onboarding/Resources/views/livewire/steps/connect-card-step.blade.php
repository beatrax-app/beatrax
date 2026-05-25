{{--
    Connect-card step — wizard step 3. Renders the UI-SPEC §"Wizard
    connector step — credit card (ICS Cards)" copy verbatim. ICS only
    offers PDF statements through Mijn ICS (no CSV export) — the
    format chip row collapses to a single muted "PDF [only format]"
    chip and the drop-zone accepts only `.pdf`.

    Submission delegates to the existing `RunsImports` pipeline with
    `ics-pdf` as the format key — same path `IcsPdfAdapter` already
    consumes for /imports.
--}}
<section class="wiz-step wiz-step-connect-card" aria-labelledby="wiz-connect-card-h1">
    <p class="wiz-eyebrow">💳 Step 3 — Your credit card (ICS)</p>
    <h1 id="wiz-connect-card-h1" class="wiz-h1">
        Grab your monthly statement PDF
    </h1>
    <p class="wiz-lede">
        ICS only offers PDF statements through Mijn ICS — no CSV export. Download
        the latest month and drop it below.
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" label="Log in" sub="mijn.icscards.nl" state="done" />
        <x-onboarding::mini-step glyph="📑" label="Open afschriften" sub="Left navigation" state="done" />
        <x-onboarding::mini-step glyph="📅" label="Pick a month" sub="Most recent" state="now" />
        <x-onboarding::mini-step glyph="⬇️" label="Download" sub="PDF" state="upcoming" />
    </div>

    <div class="format-chips" aria-label="ICS exports as PDF only">
        <span class="format-chips-label">Got it as:</span>
        <x-onboarding::format-chip label="PDF" badge="only format" />
    </div>

    <x-onboarding::drop-zone
        wire-model="file"
        lead="Drop your ICS PDF here"
        sublink="or browse for a file"
        glyph="📥"
        accept=".pdf"
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
