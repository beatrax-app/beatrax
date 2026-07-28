{{-- Correction-divergence toast.

     Globally mounted Filament-notifications-style toast surfaced
     after the user reclassifies a transaction whose initial
     suggestion came from a rule. Two actions inline (Update rule /
     Keep current rule); 8s auto-dismiss via Alpine x-init
     setTimeout; manually dismissable.

     Toast chrome matches the shared failed-job toast pattern: fixed
     bottom-right, max-w-sm rounded-lg border bg-white shadow-lg
     p-md. --}}

<div>
    @if ($visible)
        <div
            aria-atomic="true"
            aria-live="polite"
            x-data="{ visible: true }"
            x-init="setTimeout(() => { if (visible) { $wire.dismiss(); } }, 8000)"
            x-show="visible"
            x-transition.opacity
            class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border border-slate-200 bg-white shadow-lg p-4 dark:bg-slate-950 dark:border-slate-700"
        >
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                Update the rule?
            </div>
            @if ($ruleSummary !== '')
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    <span class="font-mono">{{ $ruleSummary }}</span>
                    @if ($oldCategoryPath !== '')
                        → {{ $oldCategoryPath }}
                    @endif
                </p>
            @endif
            <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                You picked a different category. Update the rule to match, or keep the existing rule for future imports.
            </p>
            <div class="mt-4 flex items-center gap-2">
                <button
                    type="button"
                    wire:click="update"
                    x-on:click="visible = false"
                    class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                >Update rule</button>
                <button
                    type="button"
                    wire:click="dismiss"
                    x-on:click="visible = false"
                    class="inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:bg-slate-800"
                >Keep current rule</button>
            </div>
        </div>
    @elseif ($flashMessage !== '')
        <div
            aria-atomic="true"
            aria-live="polite"
            wire:transition.duration.3000ms
            class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border border-emerald-200 bg-emerald-50 shadow-lg p-4 text-sm text-emerald-700 dark:bg-emerald-950 dark:border-emerald-800 dark:text-emerald-200"
        >
            {{ $flashMessage }}
        </div>
    @endif
</div>
