{{--
    "Where is my data?" — the user-facing privacy page at
    /help/data-locations. It makes the local-only promise tangible by
    surfacing every resolved path the app reads + writes, each with a
    copy action, and by offering the one-click export.

    Variables in scope:
      - $locations : array<string, string> — location key => resolved
                     absolute path, from UserDataLocations::all(). The
                     key indexes both core::help.location.* (the label)
                     and core::help.copy_aria.* (the button's name).
      - $walFile   : string — the WAL journal's filename.
      - $shmFile   : string — the shared-memory journal's filename.

    The list is rendered from the inventory rather than spelled out
    here: the deletion procedure below has to name every path, and a
    location that reached one list but not the other is the defect
    that made "every trace of your data" false.

    Every interpolation uses Blade default escaping. CSS classes
    `.help-locations`, `.path-row`, `.path-mono`, `.copy-path-btn` live
    in resources/css/app.css's @layer components block.

    The copy-to-clipboard buttons are a per-row Alpine island so no
    round-trip is needed; `window.beatraxCopy()` is best-effort and
    falls back to execCommand where the clipboard API is gated.
--}}
@use('Modules\Core\Public\Support\Lang')
<div class="help-locations" data-testid="help-data-locations">
    <header class="space-y-2">
        <x-core::page-heading class="text-[var(--color-text)]">{{ Lang::get('core::help.page_title') }}</x-core::page-heading>
        <p class="text-[var(--color-text-muted)]">
            {{ Lang::get('core::help.intro') }}
        </p>
    </header>

    {{-- ─── Section 1: Your data lives here ─────────────────────── --}}
    <section class="space-y-3" aria-labelledby="help-locations-paths-heading">
        <h2 id="help-locations-paths-heading" class="text-lg font-semibold text-[var(--color-text)]">{{ Lang::get('core::help.lives_here') }}</h2>

        @foreach ($locations as $key => $path)
            <div class="path-row" x-data="{ copied: false }" data-testid="path-row-{{ $key }}">
                <span class="font-semibold pr-1">{{ Lang::get('core::help.location.'.$key) }}</span>
                <span class="path-mono">{{ $path }}</span>
                <button
                    type="button"
                    aria-label="{{ Lang::get('core::help.copy_aria.'.$key) }}"
                    class="copy-path-btn"
                    x-on:click="window.beatraxCopy('{{ $path }}').then((ok) => { copied = ok; setTimeout(() => copied = false, 1500); })"
                    x-text="copied ? {{ Js::from(Lang::get('core::help.copied')) }} : {{ Js::from(Lang::get('core::help.copy')) }}"
                >{{ Lang::get('core::help.copy') }}</button>
            </div>
        @endforeach
    </section>

    {{-- ─── Section 2: Artefacts are not inside the backup ───────── --}}
    <section class="space-y-3" aria-labelledby="help-locations-artefacts-heading">
        <h2 id="help-locations-artefacts-heading" class="text-lg font-semibold text-[var(--color-text)]">{{ Lang::get('core::help.artefacts_heading') }}</h2>
        <p class="text-[var(--color-text-muted)]">
            {{ Lang::get('core::help.artefacts_body') }}
        </p>
    </section>

    {{-- ─── Section 3: Export everything ─────────────────────────── --}}
    <section class="space-y-3" aria-labelledby="help-locations-export-heading">
        <h2 id="help-locations-export-heading" class="text-lg font-semibold text-[var(--color-text)]">{{ Lang::get('core::help.export_heading') }}</h2>
        <p class="text-[var(--color-text-muted)]">
            {{ Lang::get('core::help.export_body') }}
        </p>

        @livewire('core.export-everything-download')
    </section>

    {{-- ─── Section 4: Deleting your data ────────────────────────── --}}
    <section class="space-y-3" aria-labelledby="help-locations-delete-heading">
        <h2 id="help-locations-delete-heading" class="text-lg font-semibold text-[var(--color-text)]">{{ Lang::get('core::help.delete_heading') }}</h2>
        <p class="text-[var(--color-text-muted)]">{{ Lang::get('core::help.delete_intro') }}</p>
        <p class="text-[var(--color-text-muted)]">{{ Lang::get('core::help.delete_uninstall') }}</p>

        <p class="text-[var(--color-text-muted)]">{{ Lang::get('core::help.delete_list_intro') }}</p>
        <ul class="list-disc pl-5 space-y-1 text-[var(--color-text-muted)]" data-testid="delete-path-list">
            @foreach ($locations as $path)
                <li><span class="path-mono">{{ $path }}</span></li>
            @endforeach
        </ul>

        <p class="text-[var(--color-text-muted)]">
            {{ Lang::get('core::help.delete_journal_note', ['wal' => $walFile, 'shm' => $shmFile]) }}
        </p>

        {{-- App-static copy carrying an apostrophe that must reach the DOM
             unescaped (see HelpDataLocationsTest); no dynamic values here. --}}
        <p class="text-[var(--color-text-muted)]">{!! Lang::get('core::help.no_telemetry') !!}</p>
    </section>
</div>
