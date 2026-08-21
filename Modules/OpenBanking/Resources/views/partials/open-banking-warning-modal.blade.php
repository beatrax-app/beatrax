@use('Modules\Core\Public\Support\Lang')
{{-- Loud third-party-data warning gate (Surface B2).
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

            {{-- tone="danger" so the box is slate on this rose surface: an emerald
                 tick on a warning modal reads as approval of the thing it warns
                 about. text-left because the modal around it centres its text. --}}
            <x-core::checkbox-field
                tone="danger"
                align="start"
                class="text-left"
                :label="Lang::get('openbanking::messages.warning.acknowledge')"
                wire:model.live="acknowledged"
                autofocus
                aria-label="{{ Lang::get('openbanking::messages.warning.acknowledge') }}"
                data-testid="ob-warning-checkbox"
            />

            <div class="flex gap-3">
                <x-core::neutral-button
                    block="flex"
                    class="min-h-[44px] disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none"
                    :disabled="! $acknowledged"
                    wire:click="confirmWarning"
                    aria-disabled="{{ $acknowledged ? 'false' : 'true' }}"
                    data-testid="ob-warning-confirm"
                >{{ Lang::get('openbanking::messages.warning.confirm') }}</x-core::neutral-button>
                <x-core::secondary-button
                    block="flex"
                    class="min-h-[44px]"
                    wire:click="cancelWarning"
                    data-testid="ob-warning-cancel"
                >{{ Lang::get('openbanking::messages.warning.cancel') }}</x-core::secondary-button>
            </div>
        </div>
    </flux:modal>
@endif
