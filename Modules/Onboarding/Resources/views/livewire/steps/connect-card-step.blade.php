@use('Modules\Core\Public\Support\Lang')
{{--
    Connect-card step — wizard step 3. ICS Cards's consumer portal only
    exports monthly PDF statements, so the user typically downloads
    several months at once and drops them all into one queue. The drop
    zone wraps a multi-file `input`; each queued PDF renders as a
    chip below the drop zone with filename, size, and remove button.

    Submission delegates per-file to the existing `RunsImports`
    pipeline with `ics-pdf` as the format key — the same path
    IcsPdfAdapter already consumes for /imports. The successful-submit
    path stashes the resulting ImportRun ids into
    `wizard_progress.data['card_import_run_ids']` for the consolidated
    preview screen to read back.
--}}
<section class="wiz-step wiz-step-connect-card" aria-labelledby="wiz-connect-card-h1">
    <x-onboarding::wiz-eyebrow step="connect-card" glyph="💳">{{ Lang::get('onboarding::connect_card.eyebrow') }}</x-onboarding::wiz-eyebrow>
    <h1 id="wiz-connect-card-h1" class="wiz-h1">
        {{ Lang::get('onboarding::connect_card.h1') }}
    </h1>
    <p class="wiz-lede">
        {{ Lang::get('onboarding::connect_card.lede') }}
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" :label="Lang::get('onboarding::connect_card.mini.login_label')" sub="mijn.icscards.nl" state="done" />
        <x-onboarding::mini-step glyph="📑" :label="Lang::get('onboarding::connect_card.mini.statements_label')" sub="Afschriften" subLang="nl" state="done" />
        <x-onboarding::mini-step glyph="📅" :label="Lang::get('onboarding::connect_card.mini.months_label')" :sub="Lang::get('onboarding::connect_card.mini.months_sub')" state="now" />
        <x-onboarding::mini-step glyph="⬇️" :label="Lang::get('onboarding::connect_card.mini.download_label')" sub="PDF" state="upcoming" />
    </div>

    <div class="format-chips" aria-label="{{ Lang::get('onboarding::connect_card.format_group_aria') }}">
        <span class="format-chips-label">{{ Lang::get('onboarding::connect_card.got_it_as') }}</span>
        <x-onboarding::format-chip label="PDF" :badge="Lang::get('onboarding::connect_card.badge_only_format')" />
    </div>

    <label class="drop-zone">
        <span class="drop-zone-glyph" aria-hidden="true">📥</span>
        <span class="drop-zone-lead">{{ Lang::get('onboarding::connect_card.drop_lead') }}</span>
        <span class="drop-zone-sublink">{{ Lang::get('onboarding::connect_card.browse_files') }}</span>
        <input
            type="file"
            class="drop-zone-input"
            wire:model="statements"
            multiple
            accept=".pdf"
        />
    </label>

    @if (count($statements) > 0)
        <div class="per-file-chip-list" aria-label="{{ Lang::get('onboarding::connect_card.queue_aria') }}">
            @foreach ($statements as $index => $statement)
                <x-onboarding::per-file-chip
                    :filename="$statement->getClientOriginalName()"
                    :sizeBytes="$statement->getSize()"
                    state="ready"
                    :index="(int) $index"
                />
            @endforeach
        </div>
    @endif

    @error('statements')
        <p class="wiz-error" role="alert">{{ $message }}</p>
    @enderror

    @error('statements.*')
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
            {{ Lang::get('onboarding::connect_card.skip') }}
        </button>
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="submit"
            wire:loading.attr="disabled"
        >
            {{ Lang::get('onboarding::connect_card.continue') }}
        </button>
    </x-onboarding::wiz-actions>
</section>
