{{--
    Connect-paypal step — wizard step 3. PayPal's per-event export is
    "Rapport Transactiegegevens" (Transaction Details Report), so the
    step accepts a single CSV via a single-file drop zone. A single
    format chip names the only choice; a four-tile mini-step row
    mirrors the PayPal portal export path (Log in → custom statements
    → Betalingen tab → Rapport Transactiegegevens).

    Submission delegates to the existing `RunsImports` pipeline with
    `paypal-csv` as the format key — the same path the PaypalCsvAdapter
    consumes for /imports. On the first successful preview the synthetic
    `PAYPAL` `accounts` row is auto-created via the shared
    `EnsurePaypalAccountAction`; the resulting ImportRun id is stashed
    into `wizard_progress.data['paypal_import_run_id']` (single int)
    for the consolidated preview screen to read back.

    Saldorapport (Balance Reconciliation Report) CSVs are explicitly
    rejected upstream (`UnsupportedPaypalCsvShapeException`); the
    friendly toast surfaces inline below the drop zone alongside any
    other parse-time failure.
--}}
<section class="wiz-step wiz-step-connect-paypal" aria-labelledby="wiz-connect-paypal-h1">
    <x-onboarding::wiz-eyebrow step="connect-paypal" glyph="💸">Your PayPal account</x-onboarding::wiz-eyebrow>
    <h1 id="wiz-connect-paypal-h1" class="wiz-h1">
        Connect your PayPal account
    </h1>
    <p class="wiz-lede">
        Drop your PayPal transaction details export — listed as
        <em lang="nl">Rapport Transactiegegevens</em> in a Dutch PayPal account. The
        balance report (<span lang="nl">Saldorapport</span>) won't work — we need
        per-event data.
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" label="Log in" sub="paypal.com" state="done" />
        <x-onboarding::mini-step glyph="📑" label="Custom statements" sub="Aangepast → Betalingen" subLang="nl" state="done" />
        <x-onboarding::mini-step glyph="📅" label="Pick a range" sub="Last 12 months" state="now" />
        <x-onboarding::mini-step glyph="⬇️" label="Download as CSV" sub="Rapport Transactiegegevens" subLang="nl" state="upcoming" />
    </div>

    <div class="format-chips" aria-label="PayPal exports as CSV only">
        <span class="format-chips-label">Got it as:</span>
        <x-onboarding::format-chip label="PayPal CSV" badge="only format" />
    </div>

    <x-onboarding::drop-zone
        wire-model="activityCsv"
        lead="Drop your transaction details CSV here"
        sublink="or browse for a file"
        glyph="📥"
        accept=".csv"
    />

    @if ($activityCsv !== null)
        <p class="wiz-file-ready">
            {{ $activityCsv->getClientOriginalName() }} · ✓ ready
        </p>
    @endif

    @error('activityCsv')
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
