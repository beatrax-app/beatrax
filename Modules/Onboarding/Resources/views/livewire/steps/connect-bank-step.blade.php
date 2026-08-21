@use('Modules\Core\Public\Support\Lang')
{{--
    Connect-bank step — wizard step 2. Renders the format-first
    connector shape: three format chips (CAMT.053 recommended, MT940,
    CSV) as a radio group above the drop zone, with helper copy and
    drop-zone copy that re-skin per chip selection. CSV reveals a
    follow-on chip row {ASN, ING} and gates the drop zone until the
    bank is picked.

    Submission delegates to the existing `RunsImports` pipeline — the
    same path the standalone `/imports` UploadWizard uses. The CSV
    branch carries the bank-format hint through the contract; the
    successful-submit path stashes the resulting ImportRun id into
    `wizard_progress.data['bank_import_run_id']` for the consolidated
    preview screen to read back.
--}}
@use('Modules\Ingestion\Public\Enums\SourceFormat')
@php
    $isCsv = in_array($selectedFormat, [SourceFormat::AsnCsv->value, SourceFormat::IngCsv->value], strict: true);
    $isGated = $isCsv && $selectedBankFormatHint === null;

    $miniStepFourSub = match ($selectedFormat) {
        SourceFormat::Mt940->value => 'MT940 (.sta / .940)',
        SourceFormat::AsnCsv->value, SourceFormat::IngCsv->value => 'CSV (.csv)',
        default => 'CAMT.053 (.xml)',
    };

    $dropZoneLead = match (true) {
        $selectedFormat === SourceFormat::Camt053->value => Lang::get('onboarding::connect_bank.drop_lead_camt053'),
        $selectedFormat === SourceFormat::Mt940->value => Lang::get('onboarding::connect_bank.drop_lead_mt940'),
        $isCsv && $selectedBankFormatHint === SourceFormat::AsnCsv->value => Lang::get('onboarding::connect_bank.drop_lead_asn'),
        $isCsv && $selectedBankFormatHint === SourceFormat::IngCsv->value => Lang::get('onboarding::connect_bank.drop_lead_ing'),
        $isCsv => Lang::get('onboarding::connect_bank.drop_lead_pick_bank'),
        default => Lang::get('onboarding::connect_bank.drop_lead_default'),
    };

    // Untranslated on purpose: the same literals the format chips and the
    // mini-steps already show. Null while the CSV bank is still unpicked,
    // because there is no one format to name yet.
    $dropZoneFileLabel = match (true) {
        $selectedFormat === SourceFormat::Camt053->value => 'CAMT.053',
        $selectedFormat === SourceFormat::Mt940->value => 'MT940',
        $isCsv && $selectedBankFormatHint === SourceFormat::AsnCsv->value => 'ASN CSV',
        $isCsv && $selectedBankFormatHint === SourceFormat::IngCsv->value => 'ING CSV',
        default => null,
    };

    $dropZoneAccept = match ($selectedFormat) {
        SourceFormat::Mt940->value => '.sta,.940,.txt',
        SourceFormat::AsnCsv->value, SourceFormat::IngCsv->value => '.csv',
        default => '.xml',
    };

    $bankListLine = match ($selectedFormat) {
        SourceFormat::Mt940->value => Lang::get('onboarding::connect_bank.banks_mt940'),
        SourceFormat::AsnCsv->value, SourceFormat::IngCsv->value => Lang::get('onboarding::connect_bank.banks_csv'),
        default => Lang::get('onboarding::connect_bank.banks_default'),
    };

    $eyebrowSuffix = match (true) {
        $selectedFormat === SourceFormat::Camt053->value => '· CAMT.053',
        $selectedFormat === SourceFormat::Mt940->value => '· MT940',
        $isCsv && $selectedBankFormatHint === SourceFormat::AsnCsv->value => '· CSV — ASN',
        $isCsv && $selectedBankFormatHint === SourceFormat::IngCsv->value => '· CSV — ING',
        $isCsv => '· CSV',
        default => '',
    };
@endphp
<section class="wiz-step wiz-step-connect-bank" aria-labelledby="wiz-connect-bank-h1">
    <x-onboarding::wiz-eyebrow step="connect-bank" glyph="🏦">{{ Lang::get('onboarding::connect_bank.eyebrow') }} {{ $eyebrowSuffix }}</x-onboarding::wiz-eyebrow>
    <h1 id="wiz-connect-bank-h1" class="wiz-h1">
        {{ Lang::get('onboarding::connect_bank.h1') }}
    </h1>
    <p class="wiz-lede">
        {{ Lang::get('onboarding::connect_bank.lede') }}
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" :label="Lang::get('onboarding::connect_bank.mini.login_label')" :sub="Lang::get('onboarding::connect_bank.mini.login_sub')" state="done" />
        <x-onboarding::mini-step glyph="📑" :label="Lang::get('onboarding::connect_bank.mini.statements_label')" :sub="Lang::get('onboarding::connect_bank.mini.statements_sub')" state="done" />
        <x-onboarding::mini-step glyph="📅" :label="Lang::get('onboarding::connect_bank.mini.range_label')" :sub="Lang::get('onboarding::connect_bank.mini.range_sub')" state="now" />
        <x-onboarding::mini-step glyph="⬇️" :label="Lang::get('onboarding::connect_bank.mini.download_label')" :sub="$miniStepFourSub" state="upcoming" />
    </div>

    <div class="format-chips" role="radiogroup" aria-label="{{ Lang::get('onboarding::connect_bank.format_group_aria') }}">
        <span class="format-chips-label">{{ Lang::get('onboarding::connect_bank.got_it_as') }}</span>
        <button
            type="button"
            class="format-chip-button"
            role="radio"
            aria-checked="{{ $selectedFormat === SourceFormat::Camt053->value ? 'true' : 'false' }}"
            wire:click="setFormat('{{ SourceFormat::Camt053->value }}')"
        >
            <x-onboarding::format-chip
                label="CAMT.053"
                :badge="Lang::get('onboarding::connect_bank.badge_recommended')"
                :recommended="$selectedFormat === SourceFormat::Camt053->value"
            />
        </button>
        <button
            type="button"
            class="format-chip-button"
            role="radio"
            aria-checked="{{ $selectedFormat === SourceFormat::Mt940->value ? 'true' : 'false' }}"
            wire:click="setFormat('{{ SourceFormat::Mt940->value }}')"
        >
            <x-onboarding::format-chip label="MT940" :recommended="$selectedFormat === SourceFormat::Mt940->value" />
        </button>
        <button
            type="button"
            class="format-chip-button"
            role="radio"
            aria-checked="{{ $isCsv ? 'true' : 'false' }}"
            wire:click="setFormat('{{ SourceFormat::AsnCsv->value }}')"
        >
            <x-onboarding::format-chip label="CSV" :recommended="$isCsv" />
        </button>
    </div>

    <p class="format-bank-list">{{ $bankListLine }}</p>

    @if ($isCsv)
        <x-onboarding::csv-bank-picker-row :selected="$selectedBankFormatHint" />
    @endif

    @if ($isGated)
        <x-onboarding::drop-zone
            wire-model="file"
            :lead="$dropZoneLead"
            :sublink="Lang::get('onboarding::connect_bank.browse_file')"
            glyph="📥"
            :accept="$dropZoneAccept"
            :file-label="$dropZoneFileLabel"
            aria-disabled="true"
            tabindex="-1"
        />
    @else
        <x-onboarding::drop-zone
            wire-model="file"
            :lead="$dropZoneLead"
            :sublink="Lang::get('onboarding::connect_bank.browse_file')"
            glyph="📥"
            :accept="$dropZoneAccept"
            :file-label="$dropZoneFileLabel"
        />
    @endif

    @if ($file !== null && ! $isGated)
        <p class="wiz-file-ready">
            {{ $file->getClientOriginalName() }} {{ Lang::get('onboarding::connect_bank.file_ready') }}
        </p>
    @endif

    @error('file')
        <p class="wiz-error" role="alert">{{ $message }}</p>
    @enderror

    @error('selectedBankFormatHint')
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
            {{ Lang::get('onboarding::connect_bank.skip') }}
        </button>
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="submit"
            wire:loading.attr="disabled"
        >
            {{ Lang::get('onboarding::connect_bank.continue') }}
        </button>
    </x-onboarding::wiz-actions>
</section>
