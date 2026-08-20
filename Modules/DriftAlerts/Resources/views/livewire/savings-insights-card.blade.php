@use('Modules\Core\Public\Support\Lang')
<div>
    @if (count($insights) > 0)
        <x-core::card tag="section" aria-label="{{ Lang::get('drift-alerts::savings.aria') }}">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('drift-alerts::savings.heading') }}</h2>
                <a href="{{ route('drift.watch') }}" class="text-xs font-medium text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">{{ Lang::get('drift-alerts::savings.subscriptions_link') }}</a>
            </div>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ Lang::get('drift-alerts::savings.disclaimer') }}
            </p>

            <ul class="mt-4 space-y-2">
                @foreach ($insights as $insight)
                    <li
                        wire:key="insight-{{ $insight->key }}"
                        class="flex items-center justify-between gap-3 rounded-md border border-slate-100 px-3 py-2 dark:border-slate-800"
                    >
                        <p class="min-w-0 flex-1 text-sm text-slate-700 dark:text-slate-300">{{ $insight->message }}</p>
                        <div class="flex shrink-0 items-center gap-1">
                            @php
                                $href = $insight->actionUrl;
                                $safe = str_starts_with($href, 'https://') || str_starts_with($href, 'http://');
                            @endphp
                            @if ($safe)
                                <a
                                    href="{{ $href }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
                                >{{ $insight->actionLabel }} <span aria-hidden="true" style="opacity:.6;">↗</span></a>
                            @endif
                            <x-core::emoji-action
                                :label="Lang::get('drift-alerts::savings.dismiss_aria')"
                                wire:click="dismiss('{{ $insight->key }}')"
                            >✖️</x-core::emoji-action>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-core::card>
    @endif
</div>
