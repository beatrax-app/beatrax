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
        $selectedFormat === SourceFormat::Camt053->value => 'Drop your CAMT.053 file here',
        $selectedFormat === SourceFormat::Mt940->value => 'Drop your MT940 file here',
        $isCsv && $selectedBankFormatHint === SourceFormat::AsnCsv->value => 'Drop your ASN CSV here',
        $isCsv && $selectedBankFormatHint === SourceFormat::IngCsv->value => 'Drop your ING CSV here',
        $isCsv => 'Pick which bank exported your CSV — we need to know to read it correctly.',
        default => 'Drop your statement file here',
    };

    $dropZoneAccept = match ($selectedFormat) {
        SourceFormat::Mt940->value => '.sta,.940,.txt',
        SourceFormat::AsnCsv->value, SourceFormat::IngCsv->value => '.csv',
        default => '.xml',
    };

    $bankListLine = match ($selectedFormat) {
        SourceFormat::Mt940->value => 'Supported: ASN, ING, Rabobank, Triodos, SNS, Bunq',
        SourceFormat::AsnCsv->value, SourceFormat::IngCsv->value => 'Supported: ASN, ING — more formats coming as users contribute samples.',
        default => 'Supported: ASN, ING',
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
    <x-onboarding::wiz-eyebrow step="connect-bank" glyph="🏦">Your bank {{ $eyebrowSuffix }}</x-onboarding::wiz-eyebrow>
    <h1 id="wiz-connect-bank-h1" class="wiz-h1">
        Grab a statement, then drop it below
    </h1>
    <p class="wiz-lede">
        Pick the format your bank gave you, then drop the file. We auto-detect CAMT.053 and MT940.
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" label="Log in" sub="Your bank's website" state="done" />
        <x-onboarding::mini-step glyph="📑" label="Open statements" sub="In your bank's menu" state="done" />
        <x-onboarding::mini-step glyph="📅" label="Pick a range" sub="Last 90 days" state="now" />
        <x-onboarding::mini-step glyph="⬇️" label="Download" :sub="$miniStepFourSub" state="upcoming" />
    </div>

    <div class="format-chips" role="radiogroup" aria-label="Bank statement format">
        <span class="format-chips-label">Got it as:</span>
        <button
            type="button"
            class="format-chip-button"
            role="radio"
            aria-checked="{{ $selectedFormat === SourceFormat::Camt053->value ? 'true' : 'false' }}"
            wire:click="setFormat('{{ SourceFormat::Camt053->value }}')"
        >
            <x-onboarding::format-chip
                label="CAMT.053"
                badge="recommended"
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
            sublink="or browse for a file"
            glyph="📥"
            :accept="$dropZoneAccept"
            aria-disabled="true"
            tabindex="-1"
        />
    @else
        <x-onboarding::drop-zone
            wire-model="file"
            :lead="$dropZoneLead"
            sublink="or browse for a file"
            glyph="📥"
            :accept="$dropZoneAccept"
        />
    @endif

    @if ($file !== null && ! $isGated)
        <p class="wiz-file-ready">
            {{ $file->getClientOriginalName() }} · ✓ ready
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
