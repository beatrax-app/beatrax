{{-- Transaction detail provenance panel.

     Three render branches keyed on $variant:
       - rule: "Rule that fired" card + Update rule / Remove rule
       - memory: "Auto-categorized from merchant history" card + Override
       - none: render nothing (manual or absent provenance — calm default)

     The quoted rule value renders in monospace so the user reads
     "this is a literal string". --}}

<div>
    @if ($flashMessage !== '')
        <aside
            aria-live="polite" aria-atomic="true"
            class="mb-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300"
        >
            {{ $flashMessage }}
        </aside>
    @endif

    @if ($variant === 'rule')
        <section
            aria-labelledby="provenance-heading-{{ $transactionId }}"
            class="rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700"
        >
            <h3 id="provenance-heading-{{ $transactionId }}" class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                Rule that fired
            </h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ $conditionSummary }} → {{ $categoryPath }}
            </p>
            @if ($confirmingRemove)
                <div class="mt-3 flex items-center gap-2">
                    <span class="text-sm text-slate-500 dark:text-slate-400">Remove?</span>
                    <button
                        type="button"
                        wire:click="removeRule"
                        class="rounded-md bg-rose-600 px-2 py-1 text-xs font-medium text-white hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-rose-500 dark:hover:bg-rose-400"
                    >Yes, remove</button>
                    <button
                        type="button"
                        wire:click="cancelRemove"
                        class="rounded-md px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:bg-slate-800"
                    >Cancel</button>
                </div>
            @else
                <div class="mt-3 flex items-center gap-2">
                    <button
                        type="button"
                        wire:click="updateRule"
                        class="inline-flex items-center rounded-md px-2 py-1 text-sm font-medium text-slate-900 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100 dark:hover:bg-slate-800"
                    >Update rule</button>
                    <button
                        type="button"
                        wire:click="confirmRemove"
                        aria-label="Remove rule (also deletes it from the Rules page)"
                        class="inline-flex items-center rounded-md px-2 py-1 text-sm font-medium text-rose-600 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:text-rose-500 dark:hover:bg-rose-950"
                    >Remove rule</button>
                </div>
            @endif
        </section>
    @elseif ($variant === 'memory')
        <section
            aria-labelledby="provenance-heading-{{ $transactionId }}"
            class="rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700"
        >
            <h3 id="provenance-heading-{{ $transactionId }}" class="text-sm text-slate-500 dark:text-slate-400">
                Auto-categorized from merchant history
            </h3>
            <div class="mt-2">
                <button
                    type="button"
                    wire:click="overrideMemory"
                    class="inline-flex items-center rounded-md px-2 py-1 text-sm font-medium text-slate-900 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100 dark:hover:bg-slate-800"
                >Override</button>
            </div>
        </section>
    @endif
</div>
