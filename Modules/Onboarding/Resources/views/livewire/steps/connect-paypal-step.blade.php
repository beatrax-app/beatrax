@use('Modules\Core\Public\Support\Lang')
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
    <x-onboarding::wiz-eyebrow step="connect-paypal" glyph="💸">{{ Lang::get('onboarding::connect_paypal.eyebrow') }}</x-onboarding::wiz-eyebrow>
    <h1 id="wiz-connect-paypal-h1" class="wiz-h1">
        {{ Lang::get('onboarding::connect_paypal.h1') }}
    </h1>
    <p class="wiz-lede">
        {!! Lang::get('onboarding::connect_paypal.lede_html') !!}
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" :label="Lang::get('onboarding::connect_paypal.mini.login_label')" sub="paypal.com" state="done" />
        <x-onboarding::mini-step glyph="📑" :label="Lang::get('onboarding::connect_paypal.mini.custom_label')" sub="Aangepast → Betalingen" subLang="nl" state="done" />
        <x-onboarding::mini-step glyph="📅" :label="Lang::get('onboarding::connect_paypal.mini.range_label')" :sub="Lang::get('onboarding::connect_paypal.mini.range_sub')" state="now" />
        <x-onboarding::mini-step glyph="⬇️" :label="Lang::get('onboarding::connect_paypal.mini.download_label')" sub="Rapport Transactiegegevens" subLang="nl" state="upcoming" />
    </div>

    <div class="format-chips" aria-label="{{ Lang::get('onboarding::connect_paypal.format_group_aria') }}">
        <span class="format-chips-label">{{ Lang::get('onboarding::connect_paypal.got_it_as') }}</span>
        <x-onboarding::format-chip label="PayPal CSV" :badge="Lang::get('onboarding::connect_paypal.badge_only_format')" />
    </div>

    <x-onboarding::drop-zone
        wire-model="activityCsv"
        :lead="Lang::get('onboarding::connect_paypal.drop_lead')"
        :sublink="Lang::get('onboarding::connect_paypal.browse_file')"
        glyph="📥"
        accept=".csv"
    />

    @if ($activityCsv !== null)
        <p class="wiz-file-ready">
            {{ $activityCsv->getClientOriginalName() }} {{ Lang::get('onboarding::connect_paypal.file_ready') }}
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
            {{ Lang::get('onboarding::connect_paypal.skip') }}
        </button>
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="submit"
            wire:loading.attr="disabled"
        >
            {{ Lang::get('onboarding::connect_paypal.continue') }}
        </button>
    </x-onboarding::wiz-actions>
</section>
