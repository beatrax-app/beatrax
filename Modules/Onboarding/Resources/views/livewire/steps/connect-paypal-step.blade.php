{{--
    Connect-paypal step — wizard step 3. PayPal exports one CSV format
    (Activity Download), so the step accepts a single CSV via a
    single-file drop zone. A single format chip names the only choice;
    a four-tile mini-step row mirrors the PayPal portal export path
    (Log in → Activity → Download → CSV).

    Submission delegates to the existing `RunsImports` pipeline with
    `paypal-csv` as the format key — the same path the PaypalCsvAdapter
    consumes for /imports. On the first successful preview the synthetic
    `PAYPAL` `accounts` row is auto-created via the shared
    `EnsurePaypalAccountAction`; the resulting ImportRun id is stashed
    into `wizard_progress.data['paypal_import_run_id']` (single int)
    for the consolidated preview screen to read back.

    Balance Reconciliation Reports are explicitly rejected upstream
    (`UnsupportedPaypalCsvShapeException`); the friendly toast surfaces
    inline below the drop zone alongside any other parse-time failure.
--}}
<section class="wiz-step wiz-step-connect-paypal" aria-labelledby="wiz-connect-paypal-h1">
    <p class="wiz-eyebrow">💸 Step 3 — Your PayPal account</p>
    <h1 id="wiz-connect-paypal-h1" class="wiz-h1">
        Connect your PayPal account
    </h1>
    <p class="wiz-lede">
        Drop your PayPal Activity Download CSV. Balance Reconciliation Reports won't work — we need Activity.
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" label="Log in" sub="paypal.com" state="done" />
        <x-onboarding::mini-step glyph="📑" label="Open activity" sub="Reports → Activity" state="done" />
        <x-onboarding::mini-step glyph="📅" label="Pick a range" sub="Last 12 months" state="now" />
        <x-onboarding::mini-step glyph="⬇️" label="Download" sub="CSV (.csv)" state="upcoming" />
    </div>

    <div class="format-chips" aria-label="PayPal exports as CSV only">
        <span class="format-chips-label">Got it as:</span>
        <x-onboarding::format-chip label="PayPal CSV" badge="only format" />
    </div>

    <x-onboarding::drop-zone
        wire-model="activityCsv"
        lead="Drop your PayPal Activity CSV here"
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
