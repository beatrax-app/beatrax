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
    <x-onboarding::wiz-eyebrow step="connect-card" glyph="💳">Your credit card (ICS)</x-onboarding::wiz-eyebrow>
    <h1 id="wiz-connect-card-h1" class="wiz-h1">
        Grab your monthly statement PDFs
    </h1>
    <p class="wiz-lede">
        Drop all your monthly ICS PDF statements — we'll combine them into one preview.
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" label="Log in" sub="mijn.icscards.nl" state="done" />
        <x-onboarding::mini-step glyph="📑" label="Open statements" sub="Afschriften" subLang="nl" state="done" />
        <x-onboarding::mini-step glyph="📅" label="Pick months" sub="One PDF per month" state="now" />
        <x-onboarding::mini-step glyph="⬇️" label="Download" sub="PDF" state="upcoming" />
    </div>

    <div class="format-chips" aria-label="ICS exports as PDF only">
        <span class="format-chips-label">Got it as:</span>
        <x-onboarding::format-chip label="PDF" badge="only format" />
    </div>

    <label class="drop-zone">
        <span class="drop-zone-glyph" aria-hidden="true">📥</span>
        <span class="drop-zone-lead">Drop your ICS PDFs here</span>
        <span class="drop-zone-sublink">or browse for files</span>
        <input
            type="file"
            class="drop-zone-input"
            wire:model="statements"
            multiple
            accept=".pdf"
        />
    </label>

    @if (count($statements) > 0)
        <div class="per-file-chip-list" aria-label="Queued PDF statements">
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
