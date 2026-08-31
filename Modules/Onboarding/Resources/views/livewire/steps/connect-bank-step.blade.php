@use('Modules\Core\Public\Support\Lang')
{{--
    Connect-bank step — wizard step 2. Renders the format-first
    connector shape: three format chips (CAMT.053 recommended, MT940,
    CSV) as a radio group above the drop zone, with helper copy and
    drop-zone copy that re-skin per chip selection. CSV reveals a
    follow-on chip row of the CSV layouts the step offers and gates the
    drop zone until one is picked.

    The layouts arrive as {format, label} pairs from the step, which
    resolves them from the CSV preset registry. A bank's name is data
    that reaches the screen through a label; this template names none.

    Submission delegates to the existing `RunsImports` pipeline — the
    same path the standalone `/imports` UploadWizard uses. Picking a
    layout sets the source format the import runs as, so the two chip
    rows resolve to one identifier; the successful-submit path stashes
    the resulting ImportRun id into
    `wizard_progress.data['bank_import_run_id']` for the consolidated
    preview screen to read back.
--}}
@use('Modules\Ingestion\Public\Enums\SourceFormat')
@php
    /** @var list<array{format: string, label: string}> $csvLayouts */

    $csvFormats = array_column($csvLayouts, 'format');
    $isCsv = in_array($selectedFormat, $csvFormats, strict: true);
    $isGated = $isCsv && ! $csvLayoutPicked;

    // The CSV chip has to land somewhere, and landing on a layout is not the
    // reader picking it, so the chip row below stays unanswered until they do.
    $csvLandingFormat = $csvFormats[0] ?? '';

    $pickedCsvFormat = $isCsv && $csvLayoutPicked ? $selectedFormat : null;
    $pickedCsvLabel = null;
    foreach ($csvLayouts as $layout) {
        if ($layout['format'] === $pickedCsvFormat) {
            $pickedCsvLabel = $layout['label'];
        }
    }

    $miniStepFourSub = match (true) {
        $selectedFormat === SourceFormat::Mt940->value => 'MT940 (.sta / .940)',
        $isCsv => 'CSV (.csv)',
        default => 'CAMT.053 (.xml)',
    };

    $dropZoneLead = match (true) {
        $selectedFormat === SourceFormat::Camt053->value => Lang::get('onboarding::connect_bank.drop_lead_camt053'),
        $selectedFormat === SourceFormat::Mt940->value => Lang::get('onboarding::connect_bank.drop_lead_mt940'),
        $pickedCsvLabel !== null => Lang::get('onboarding::connect_bank.drop_lead_csv_layout', ['layout' => $pickedCsvLabel]),
        $isCsv => Lang::get('onboarding::connect_bank.drop_lead_pick_bank'),
        default => Lang::get('onboarding::connect_bank.drop_lead_default'),
    };

    // Untranslated on purpose: the same file-type literals the format chips
    // and the mini-steps already show.
    $dropZoneFileLabel = match (true) {
        $selectedFormat === SourceFormat::Camt053->value => 'CAMT.053',
        $selectedFormat === SourceFormat::Mt940->value => 'MT940',
        $isCsv => 'CSV',
        default => null,
    };

    $dropZoneAccept = match (true) {
        $selectedFormat === SourceFormat::Mt940->value => '.sta,.940,.txt',
        $isCsv => '.csv',
        default => '.xml',
    };

    $formatHelpLine = match (true) {
        $selectedFormat === SourceFormat::Mt940->value => Lang::get('onboarding::connect_bank.format_help_mt940'),
        $isCsv => Lang::get('onboarding::connect_bank.format_help_csv'),
        default => Lang::get('onboarding::connect_bank.format_help_camt053'),
    };

    $eyebrowSuffix = match (true) {
        $selectedFormat === SourceFormat::Camt053->value => '· CAMT.053',
        $selectedFormat === SourceFormat::Mt940->value => '· MT940',
        $pickedCsvLabel !== null => '· CSV — '.$pickedCsvLabel,
        $isCsv => '· CSV',
        default => '',
    };
@endphp
<section class="wiz-step" aria-labelledby="wiz-connect-bank-h1">
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
            wire:click="setFormat('{{ $csvLandingFormat }}')"
        >
            <x-onboarding::format-chip label="CSV" :recommended="$isCsv" />
        </button>
    </div>

    <p class="format-bank-list">{{ $formatHelpLine }}</p>

    @if ($isCsv)
        <x-onboarding::csv-layout-picker-row :layouts="$csvLayouts" :selected="$pickedCsvFormat" />
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

    @error('csvLayoutPicked')
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
