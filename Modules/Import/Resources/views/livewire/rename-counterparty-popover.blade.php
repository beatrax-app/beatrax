{{--
    Rename counterparty popover.

    Mounted once at the bottom of the upload-wizard / preview-wizard
    blade. The italic .desc-fallback spans in the preview row dispatch
    `rename-counterparty:open` with `raw` + `rowIndex` payload; the
    component opens the Flux modal, prefills the inputs, runs the
    PatternGeneralizer once on mount, and on save writes the
    merchant_aliases row + optional categorization_rules row, then
    dispatches `rename-counterparty:saved` for the parent wizard to
    update the affected row in place.
--}}

@use('Modules\Core\Public\Support\Lang')
<div>
    <flux:modal name="rename-counterparty" dismissible>
        <div class="space-y-6">
            <flux:heading size="lg">{{ Lang::get('import::rename.heading') }}</flux:heading>

            <form wire:submit="save" class="space-y-5">
                <div class="space-y-1">
                    <label for="rename-popover-raw" class="block text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('import::rename.raw_label') }}</label>
                    <input
                        type="text"
                        id="rename-popover-raw"
                        value="{{ $raw }}"
                        readonly
                        class="block w-full rounded-md border border-slate-200 bg-slate-100 px-3 py-2 text-sm font-mono text-slate-700 focus:outline-none dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300"
                    />
                </div>

                <div class="space-y-1">
                    <label for="rename-popover-friendly" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('import::rename.friendly_label') }}</label>
                    <input
                        type="text"
                        id="rename-popover-friendly"
                        wire:model.live="friendly"
                        placeholder="{{ Lang::get('import::rename.friendly_placeholder') }}"
                        autofocus
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                    />
                    @error('friendly')
                        <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label class="inline-flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input
                            type="checkbox"
                            wire:model.live="remember"
                            class="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-700"
                        />
                        <span>
                            <span class="block">{{ Lang::get('import::rename.remember', ['raw' => $raw]) }}</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('import::rename.remember_help') }}</span>
                        </span>
                    </label>

                    @if ($remember && $generalized !== '')
                        <p class="popover-preview-line">
                            {{ Lang::get('import::rename.generalize_prefix') }} <code>{{ $generalized }}*</code>.
                        </p>
                    @endif
                </div>

                <div class="space-y-1">
                    <label for="rename-popover-generalized" class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('import::rename.generalized_label') }}</label>
                    <input
                        type="text"
                        id="rename-popover-generalized"
                        wire:model="generalized"
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-mono text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="cancel"
                        class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:bg-slate-800"
                    >{{ Lang::get('import::rename.cancel') }}</button>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        @disabled(trim($friendly) === '')
                        class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400 dark:disabled:bg-slate-600"
                    >
                        <span wire:loading.remove wire:target="save">{{ Lang::get('import::rename.save') }}</span>
                        <span wire:loading wire:target="save">{{ Lang::get('import::rename.saving') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
