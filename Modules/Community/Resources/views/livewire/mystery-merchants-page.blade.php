@use('Modules\Core\Public\Support\Lang')
{{-- Mystery merchants browse destination.

     Lists every description in the user's transaction history the
     MerchantNameResolver cannot identify, plus a stats strip
     summarising the corpus size + the auto-named-rate KPI. Each card
     opens the global suggest-mapping modal via the
     `suggest-mapping:open` Livewire event. The modal itself is
     mounted in the wrapping mystery-merchants.blade.php view so this
     component does not double-render it. --}}

<div class="space-y-6" data-testid="mystery-merchants-page">
    <header>
        <x-core::page-heading>{{ Lang::get('community::mystery.heading') }}</x-core::page-heading>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('community::mystery.intro') }}
        </p>
    </header>

    <section
        aria-label="{{ Lang::get('community::mystery.stats.aria') }}"
        class="grid grid-cols-2 gap-3 md:grid-cols-4"
    >
        <div class="rounded-lg border border-slate-200 px-4 py-3 dark:border-slate-700">
            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('community::mystery.stats.mystery') }}</p>
            <p class="mt-1 text-xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                {{ $stats['mysteryCount'] }}
            </p>
        </div>
        <div class="rounded-lg border border-slate-200 px-4 py-3 dark:border-slate-700">
            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('community::mystery.stats.shared_list') }}</p>
            <p class="mt-1 text-xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                {{ $stats['mappingsCount'] }}
            </p>
        </div>
        <div class="rounded-lg border border-slate-200 px-4 py-3 dark:border-slate-700">
            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('community::mystery.stats.auto_named') }}</p>
            <p class="mt-1 text-xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                {{ $stats['autoNamedPercent'] === null ? '—' : $stats['autoNamedPercent'].'%' }}
            </p>
        </div>
        <div class="rounded-lg border border-slate-200 px-4 py-3 dark:border-slate-700">
            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('community::mystery.stats.contributions') }}</p>
            <p class="mt-1 text-xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                {{ $stats['contributorCount'] }}
            </p>
        </div>
    </section>

    @if (count($rows) === 0)
        <p class="rounded-lg border border-slate-200 bg-white px-6 py-12 text-center text-sm text-slate-500 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-400">
            {{ Lang::get('community::mystery.empty') }}
        </p>
    @else
        <ul class="space-y-3" role="list" data-testid="mystery-card-list">
            @foreach ($rows as $row)
                <li>
                    <x-community::mystery-card :row="$row" />
                </li>
            @endforeach
        </ul>

        {{-- The tile above counts every distinct mystery; this list stops at 24
             and there is no next page, so the gap is said out loud rather than
             left for the reader to notice. --}}
        @if ($stats['mysteryCount'] > $stats['shownCount'])
            <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="mystery-card-cap-note">
                {{ Lang::get('community::mystery.showing_capped', ['shown' => $stats['shownCount'], 'total' => $stats['mysteryCount']]) }}
            </p>
        @endif
    @endif

    <footer class="border-t border-slate-200 pt-4 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
        <p>
            {{ Lang::get('community::mystery.footer') }}
        </p>
    </footer>
</div>
