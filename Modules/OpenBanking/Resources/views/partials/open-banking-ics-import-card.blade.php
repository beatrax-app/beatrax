@use('Modules\Core\Public\Support\Lang')
{{-- Surface B7: guided ICS file-import affordance (UI-SPEC Surface B7).
     Visually and functionally SEPARATE from the live OB cards
     above — this path stores NO credentials and routes a dropped statement
     directly through the EXISTING ICS SourceAdapter (`ics-pdf`) via
     RunsImports::runFromUpload, skipping the generic import wizard's
     source-picker entirely. Always visible (State Display Matrix: every
     OB state renders this card). Carries the `#ics-import` anchor the
     Notifications "statement ready" nudge deep-links to (Surface C) — the
     browser's native fragment scroll handles the scroll-on-load, same as
     the existing `#app-lock` / `#anomaly-detection` anchors elsewhere in
     Settings; no extra JS is needed since this is a full page load, not a
     Livewire SPA navigation.

     ICS Cards's consumer portal only ever exports monthly PDF statements —
     there is no CAMT.053/CSV ICS adapter in this codebase (confirmed
     against `Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter`) — so
     the mini-step row and format chip below say "PDF statement", matching
     what the existing adapter actually accepts (a correction to the
     UI-SPEC's generic "CAMT.053 or CSV" placeholder copy, which described
     the ASN bank-statement shape, not ICS). --}}
<section id="ics-import" class="space-y-3" data-testid="open-banking-ics-import-card">
    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
        {{ Lang::get('openbanking::messages.ics.section_label') }}
    </p>

    <x-core::card>
        <x-core::section-heading :title="Lang::get('openbanking::messages.ics.heading')" />

        <ol class="mt-4 grid grid-cols-3 gap-3">
            <li class="rounded-md bg-slate-50 p-3 text-center dark:bg-slate-900">
                <span class="mb-2 block text-2xl leading-none" aria-hidden="true">&#128272;</span>
                <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ Lang::get('openbanking::messages.ics.step_login') }}</span>
                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">Mijn ICS</span>
            </li>
            <li class="rounded-md bg-slate-50 p-3 text-center dark:bg-slate-900">
                <span class="mb-2 block text-2xl leading-none" aria-hidden="true">&#128220;</span>
                <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ Lang::get('openbanking::messages.ics.step_download') }}</span>
                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.ics.pdf_statement') }}</span>
            </li>
            <li class="rounded-md bg-slate-50 p-3 text-center dark:bg-slate-900">
                <span class="mb-2 block text-2xl leading-none" aria-hidden="true">&#128229;</span>
                <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ Lang::get('openbanking::messages.ics.step_drop') }}</span>
            </li>
        </ol>

        <div class="mt-4">
            <span
                class="inline-flex items-center rounded-md border border-slate-200 px-2.5 py-1 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400"
                data-testid="ob-ics-format-chip"
            >{{ Lang::get('openbanking::messages.ics.pdf_statement') }}</span>
        </div>

        @if ($icsImportError !== null)
            <x-core::alert
                tone="danger"
                class="mt-3"
                role="alert"
                data-testid="ob-ics-import-error"
            >{{ $icsImportError }}</x-core::alert>
        @endif

        @error('icsStatement')
            <x-core::alert tone="danger" class="mt-3" role="alert">
                {{ $message }}
            </x-core::alert>
        @enderror

        <div class="mt-4">
            <label
                for="ob-ics-statement-input"
                class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 px-5 py-7 text-center text-sm text-slate-500 transition hover:border-slate-400 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400 dark:hover:border-slate-600 dark:hover:bg-slate-800"
                data-testid="ob-ics-drop-zone"
            >
                <span class="text-2xl leading-none" aria-hidden="true">&#128229;</span>
                @if ($icsStatement !== null)
                    <span class="font-medium text-slate-700 dark:text-slate-200" data-testid="ob-ics-selected-filename">{{ $icsStatement->getClientOriginalName() }}</span>
                @else
                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ Lang::get('openbanking::messages.ics.drop_zone_label') }}</span>
                @endif
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.ics.drop_zone_hint') }}</span>
                <input
                    id="ob-ics-statement-input"
                    type="file"
                    accept="application/pdf"
                    wire:model="icsStatement"
                    class="sr-only"
                    aria-label="{{ Lang::get('openbanking::messages.ics.browse_aria') }}"
                    data-testid="ob-ics-file-input"
                >
            </label>
        </div>

        @if ($icsStatement !== null)
            <div class="mt-4">
                <x-core::neutral-button
                    class="min-h-[44px] disabled:cursor-not-allowed disabled:opacity-50"
                    wire:click="importIcsStatement"
                    wire:loading.attr="disabled"
                    wire:target="importIcsStatement,icsStatement"
                    data-testid="ob-ics-import-button"
                >
                    <x-core::spinner size="sm" wire:loading wire:target="importIcsStatement" class="mr-2" />
                    {{ Lang::get('openbanking::messages.ics.import_button') }}
                </x-core::neutral-button>
            </div>
        @endif
    </x-core::card>
</section>
