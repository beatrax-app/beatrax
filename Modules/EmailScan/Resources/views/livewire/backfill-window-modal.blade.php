{{-- Backfill window picker modal.

     Compact Flux modal anchored to a specific inbox row. The user picks
     a 1-12 month window (default 3); on confirm the BackfillInboxJob is
     dispatched and the modal closes. dismissible="true" because Cancel
     is a no-op (no inbox state mutates).

     Auto-opens once after the OAuth callback redirect via the
     `backfill-window:open` Livewire event. Re-opens via the inline
     [Edit] link on every connected-inbox row.

     The slider is a plain `input type="range"` wired with wire:model.live
     so the inline readout updates on every drag tick — the installed
     Flux build does not ship a flux:input.range primitive yet, so the
     hand-rolled range input fills the gap with the same focus chrome. --}}

@use('Modules\Core\Public\Support\Lang')
<div>
    <flux:modal name="backfill-window-{{ $inboxId ?? 0 }}" class="md:max-w-lg" dismissible="true">
        <div class="space-y-6">
            <flux:heading size="lg">{{ Lang::get('email-scan::backfill.heading') }}</flux:heading>

            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('email-scan::backfill.body') }}
            </p>

            <div>
                <input
                    type="range"
                    min="1"
                    max="12"
                    step="1"
                    wire:model.live="months"
                    class="w-full accent-emerald-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:accent-emerald-500"
                    aria-label="{{ Lang::get('email-scan::backfill.range_aria') }}"
                />
                <div class="flex justify-between text-xs text-slate-500 px-1 mt-2 dark:text-slate-400">
                    <span>1</span>
                    <span>3</span>
                    <span>6</span>
                    <span>9</span>
                    <span>12</span>
                </div>
            </div>

            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                {{ Lang::choice('email-scan::backfill.months', $months, ['count' => $months]) }}
            </div>

            @if ($errorMessage !== '')
                <div class="text-xs text-rose-600 dark:text-rose-500">{{ $errorMessage }}</div>
            @endif

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    wire:click="$dispatch('modal-close', { name: 'backfill-window-{{ $inboxId ?? 0 }}' })"
                    class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                >{{ Lang::get('email-scan::backfill.cancel') }}</button>
                <button
                    type="button"
                    wire:click="submit"
                    class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                >{{ Lang::get('email-scan::backfill.start') }}</button>
            </div>
        </div>
    </flux:modal>
</div>
