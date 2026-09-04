@use('Modules\Core\Public\Support\Lang')
@use('Modules\Migration\Internal\Enums\ConflictResolution')
@php
    $discarded = $summary === null;

    $stats = $discarded ? [] : [
        ['key' => 'category', 'label' => Lang::get('migration::preview.stats.category'), 'value' => $summary->categoriesCount],
        ['key' => 'account', 'label' => Lang::get('migration::preview.stats.account'), 'value' => $summary->accountsCount],
        ['key' => 'payee', 'label' => Lang::get('migration::preview.stats.payee'), 'value' => $summary->counterpartiesCount],
        ['key' => 'transaction', 'label' => Lang::get('migration::preview.stats.transaction'), 'value' => $summary->transactionsCount],
        ['key' => 'budget', 'label' => Lang::get('migration::preview.stats.budget'), 'value' => $summary->budgetMonthsCount],
    ];

    $stagedNothing = ! $discarded && $summary->stagedNothing();
    $everythingClean = ! $discarded && ! $stagedNothing && $summary->unmappedCount() === 0;
@endphp

<div class="space-y-8 pb-24">
    <header class="space-y-1">
        <x-core::page-heading>{{ Lang::get('migration::preview.heading') }}</x-core::page-heading>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('migration::preview.subtitle') }}</p>
    </header>

    {{-- A discarded run kept its row and lost its staging, so the counts, the
         decisions and both footer actions have nothing left to act on. --}}
    @if ($discarded)
        <x-core::alert tone="warning" role="alert">
            <p>{{ Lang::get('migration::preview.discarded') }}</p>
            <p class="mt-2">
                <a href="{{ route('migrations.new') }}" class="tap-link font-medium underline underline-offset-2">
                    {{ Lang::get('migration::preview.discarded_link') }}
                </a>
            </p>
        </x-core::alert>
    @else
    {{-- 5-up mapped-counts stat grid — wraps to 2-up at phone width. --}}
    <section class="grid grid-cols-2 gap-4 sm:grid-cols-5" style="font-feature-settings: 'tnum';">
        @foreach ($stats as $stat)
            <div class="rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700">
                <p class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ $stat['value'] }}</p>
                <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $stat['label'] }}</p>
            </div>
        @endforeach
    </section>

    {{-- Grouped-section unmapped/conflict list, or one of the two empty states:
         nothing staged at all, or everything staged and all of it mapped. --}}
    @if ($stagedNothing)
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('migration::preview.nothing_staged') }}</p>
    @elseif ($everythingClean)
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('migration::preview.all_clean') }}</p>
    @else
        <section class="space-y-3">
            @if ($summary->unmapped['conflict']['count'] > 0)
                <details open class="rounded-md border border-slate-200 dark:border-slate-700" data-testid="conflicts-group">
                    <summary class="cursor-pointer px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        {{ Lang::get('migration::preview.groups.conflict') }} ({{ $summary->unmapped['conflict']['count'] }})
                        <span class="ml-1 inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950">{{ $summary->unmapped['conflict']['count'] }}</span>
                    </summary>
                    <div class="space-y-4 border-t border-slate-200 px-4 py-4 dark:border-slate-700">
                        @foreach ($summary->unmapped['conflict']['items'] as $item)
                            <div class="space-y-2 rounded-md bg-slate-50 p-3 dark:bg-slate-900" data-testid="conflict-row-{{ $item['id'] }}">
                                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $item['label'] }}</p>
                                <p class="whitespace-pre-line font-mono text-xs text-slate-500 dark:text-slate-400">{{ $item['reason'] }}</p>
                                <div class="inline-flex overflow-hidden rounded-md border border-slate-200 text-xs font-medium dark:border-slate-700" role="group" aria-label="{{ Lang::get('migration::preview.keep_or_take_aria', ['label' => $item['label']]) }}">
                                    <button
                                        type="button"
                                        wire:click="resolveConflict('{{ $item['id'] }}', '{{ ConflictResolution::KeepLocal->value }}')"
                                        class="px-3 py-1.5 {{ $item['resolution'] === ConflictResolution::KeepLocal->value ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-white text-slate-700 dark:bg-slate-950 dark:text-slate-300' }}"
                                    >
                                        {{ Lang::get('migration::preview.keep_local') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="resolveConflict('{{ $item['id'] }}', '{{ ConflictResolution::TakeSource->value }}')"
                                        class="px-3 py-1.5 {{ $item['resolution'] === ConflictResolution::TakeSource->value ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-white text-slate-700 dark:bg-slate-950 dark:text-slate-300' }}"
                                    >
                                        {{ Lang::get('migration::preview.take_source') }}
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif

            @if ($summary->unmapped['extra']['count'] > 0)
                <details class="rounded-md border border-slate-200 dark:border-slate-700">
                    <summary class="cursor-pointer px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        {{ Lang::get('migration::preview.groups.extra') }} ({{ $summary->unmapped['extra']['count'] }})
                        <span class="ml-1 inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950">{{ $summary->unmapped['extra']['count'] }}</span>
                    </summary>
                    <ul class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-700">
                        @foreach ($summary->unmapped['extra']['items'] as $item)
                            <li class="text-slate-900 dark:text-slate-100">{{ $item['label'] }} <span class="text-slate-500 dark:text-slate-400">— {{ $item['reason'] }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>
    @endif

    {{-- Footer action row — pinned bottom, 1px top border, 24px top padding. --}}
    <footer class="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white px-6 py-4 dark:bg-slate-950 dark:border-slate-700">
        <div class="mx-auto max-w-5xl space-y-2">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('migration::preview.footer_note') }}</p>
            <div class="flex items-center justify-between gap-3">
                <x-core::secondary-button
                    wire:click="discard"
                    wire:confirm="{{ Lang::get('migration::preview.discard_confirm') }}"
                >
                    {{ Lang::get('migration::preview.discard_button') }}
                </x-core::secondary-button>
                <button
                    type="button"
                    wire:click="confirm"
                    class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-800 dark:bg-emerald-700"
                >
                    {{ Lang::get('migration::preview.confirm_button') }}
                </button>
            </div>
        </div>
    </footer>
    @endif
</div>
