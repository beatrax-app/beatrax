{{-- Transaction detail provenance panel.

     Three render branches keyed on $variant:
       - rule: "Rule that fired" card + Update rule / Remove rule
       - memory: "Auto-categorized from merchant history" card + Override
       - none: render nothing (manual or absent provenance — calm default)

     The quoted rule value renders in monospace so the user reads
     "this is a literal string". --}}

@use('Modules\Core\Public\Support\Lang')
<div>
    @if ($flashMessage !== '')
        {{-- role="complementary" is the <aside> this used to be: the shared
             alert draws a <div>, so the landmark has to be spelled out or a
             screen-reader user loses it from the landmark list. --}}
        <x-core::alert
            tone="neutral"
            class="mb-3"
            role="complementary"
            aria-live="polite" aria-atomic="true"
        >
            {{ $flashMessage }}
        </x-core::alert>
    @endif

    @if ($variant === 'rule')
        <section
            aria-labelledby="provenance-heading-{{ $transactionId }}"
            class="rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700"
        >
            <h3 id="provenance-heading-{{ $transactionId }}" class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                {{ Lang::get('categorization::detail.rule_that_fired') }}
            </h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ $conditionSummary }} → {{ $categoryPath }}
            </p>
            @if ($confirmingRemove)
                <x-core::confirm-strip
                    class="mt-3"
                    :question="Lang::get('categorization::detail.remove_confirm')"
                    :cancel-label="Lang::get('categorization::detail.cancel')"
                    :confirm-label="Lang::get('categorization::detail.remove_yes')"
                    cancel="cancelRemove"
                    confirm="removeRule"
                />
            @else
                <div class="mt-3 flex items-center gap-2">
                    <button
                        type="button"
                        wire:click="updateRule"
                        class="inline-flex items-center rounded-md px-2 py-1 text-sm font-medium text-slate-900 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100 dark:hover:bg-slate-800"
                    >{{ Lang::get('categorization::detail.update_rule') }}</button>
                    <button
                        type="button"
                        wire:click="confirmRemove"
                        aria-label="{{ Lang::get('categorization::detail.remove_rule_aria') }}"
                        class="inline-flex items-center rounded-md px-2 py-1 text-sm font-medium text-rose-600 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:text-rose-500 dark:hover:bg-rose-950"
                    >{{ Lang::get('categorization::detail.remove_rule') }}</button>
                </div>
            @endif
        </section>
    @elseif ($variant === 'memory')
        <section
            aria-labelledby="provenance-heading-{{ $transactionId }}"
            class="rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700"
        >
            <h3 id="provenance-heading-{{ $transactionId }}" class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('categorization::detail.auto_categorized') }}
            </h3>
            <div class="mt-2">
                @if ($overriding)
                    {{-- The same picker the transactions list uses per row, so
                         the override writes through AssignsCategory exactly
                         like every other category change. --}}
                    @livewire(
                        'categorization.inline-category-picker',
                        ['transactionId' => $transactionId, 'categoryId' => $overrideCategoryId],
                        key('provenance-override-' . $transactionId),
                    )
                @else
                    <button
                        type="button"
                        wire:click="overrideMemory"
                        class="inline-flex items-center rounded-md px-2 py-1 text-sm font-medium text-slate-900 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100 dark:hover:bg-slate-800"
                    >{{ Lang::get('categorization::detail.override') }}</button>
                @endif
            </div>
        </section>
    @endif
</div>
