@use('Modules\Core\Public\Support\Lang')
{{-- Loud third-party-data warning gate (Surface B2, Req 4, D-12).
     The ONE deliberate visual break from the calm-slate room: 2px rose
     border, tinted body — this must read as a genuine speed bump, not a
     dismissible notice. `role="alertdialog"` (stronger than Flux's default
     `role="dialog"`) since this is an interruptive gate the user must
     resolve, not a passive dialog. --}}
@if ($showWarningModal)
    <flux:modal wire:model="showWarningModal" class="md:max-w-md" data-testid="open-banking-warning-modal">
        <div
            role="alertdialog"
            aria-labelledby="ob-warning-heading"
            class="space-y-4 rounded-xl border-2 border-rose-300 bg-rose-50 p-6 text-center dark:border-rose-800 dark:bg-rose-950"
        >
            <svg class="mx-auto h-8 w-8 text-rose-600 dark:text-rose-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12v-.008ZM9.401 3.003c1.155-2 4.043-2 5.198 0l7.42 12.855c1.153 2-.294 4.5-2.6 4.5H4.581c-2.305 0-3.752-2.5-2.598-4.5L9.4 3.003Z" />
            </svg>

            <h3 id="ob-warning-heading" class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('openbanking::messages.warning.heading') }}</h3>

            <p class="text-left text-sm text-slate-700 dark:text-slate-300">
                {{ Lang::get('openbanking::messages.warning.body') }}
            </p>

            <label class="flex items-start gap-2 text-left">
                <input
                    type="checkbox"
                    wire:model.live="acknowledged"
                    autofocus
                    aria-label="{{ Lang::get('openbanking::messages.warning.acknowledge') }}"
                    class="mt-1 h-4 w-4 rounded border-rose-300 text-slate-900 focus:ring-slate-900 dark:border-rose-700 dark:text-slate-100 dark:focus:ring-slate-100"
                    data-testid="ob-warning-checkbox"
                >
                <span class="text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('openbanking::messages.warning.acknowledge') }}</span>
            </label>

            <div class="flex gap-3">
                <button
                    type="button"
                    wire:click="confirmWarning"
                    @disabled(! $acknowledged)
                    aria-disabled="{{ $acknowledged ? 'false' : 'true' }}"
                    class="flex-1 min-h-[44px] rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white
                           hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                           disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none
                           dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
                    data-testid="ob-warning-confirm"
                >{{ Lang::get('openbanking::messages.warning.confirm') }}</button>
                <button
                    type="button"
                    wire:click="cancelWarning"
                    class="flex-1 min-h-[44px] rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                           hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                           dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
                    data-testid="ob-warning-cancel"
                >{{ Lang::get('openbanking::messages.warning.cancel') }}</button>
            </div>
        </div>
    </flux:modal>
@endif
