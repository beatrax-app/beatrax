@use('Modules\Core\Public\Support\Lang')
{{--
    Per-series drift threshold popover. Mounted inline by both the
    /drift grouped-by-series header and the
    /recurring/series/{id} drill-in page. Alpine's `x-data="{ open:
    false }"` drives the popover; clicking outside closes it.

    Saving invokes the Livewire component's `save($newValue)` method
    which delegates to the Recurring-side SetDriftThresholdForSeries
    Public Action — DriftAlerts retains zero direct writes to
    recurring_series.

    Variables in scope:
      - $currentValue : ?int  — current per-series override; null = use global default
      - $options : list<int>  — [1, 2, 5, 10, 25, 50]
--}}

<div x-data="{ open: false }" class="relative inline-block">
    <button
        type="button"
        x-on:click="open = ! open"
        aria-haspopup="listbox"
        aria-label="{{ Lang::get('drift-alerts::threshold.series_aria') }}"
        class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-900"
    >
        <span class="text-slate-500 dark:text-slate-400">{{ Lang::get('drift-alerts::threshold.label') }}</span>
        <span style="font-variant-numeric: tabular-nums;">
            @if ($currentValue === null)
                {{ Lang::get('drift-alerts::threshold.global') }}
            @else
                ±{{ $currentValue }}%
            @endif
        </span>
    </button>
    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        role="listbox"
        aria-label="{{ Lang::get('drift-alerts::threshold.listbox_aria') }}"
        class="absolute right-0 z-10 mt-1 w-56 rounded-md border border-slate-200 bg-white p-2 text-xs shadow-lg dark:bg-slate-950 dark:border-slate-700"
    >
        @foreach ($options as $opt)
            <button
                type="button"
                wire:click="save({{ $opt }})"
                x-on:click="open = false"
                @class([
                    'block w-full rounded-md px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900',
                    'font-medium text-slate-900 dark:text-slate-100' => $currentValue === $opt,
                    'text-slate-500 dark:text-slate-400' => $currentValue !== $opt,
                ])
            >±{{ $opt }}%</button>
        @endforeach
        <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>
        <button
            type="button"
            wire:click="save('global')"
            x-on:click="open = false"
            @class([
                'block w-full rounded-md px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900',
                'font-medium text-slate-900 dark:text-slate-100' => $currentValue === null,
                'text-slate-500 dark:text-slate-400' => $currentValue !== null,
            ])
        >{{ Lang::get('drift-alerts::threshold.use_global') }}</button>
    </div>
</div>
