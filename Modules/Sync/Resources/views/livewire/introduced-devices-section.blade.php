@use('Modules\Core\Public\Support\Lang')
<div data-testid="introduced-devices-section">
    @if ($introductions !== [])
        <div class="space-y-4">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('sync::devices.introduced_heading') }}</h3>

            <x-core::alert tone="info" data-testid="introduced-trust-notice">
                {{ Lang::get('sync::devices.introduced_trust') }}
            </x-core::alert>

            <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                @foreach ($introductions as $introduction)
                    <li class="py-4" wire:key="introduction-{{ $introduction['id'] }}">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm text-slate-900 dark:text-slate-100">{{ $introduction['name'] }}</span>

                                    @if ($introduction['confirmed'])
                                        <x-core::status-pill
                                            tone="positive"
                                            data-testid="introduction-confirmed-{{ $introduction['id'] }}"
                                        >{{ Lang::get('sync::devices.introduced_confirmed') }}</x-core::status-pill>
                                    @else
                                        <x-core::status-pill tone="warning">{{ Lang::get('sync::devices.introduced_unconfirmed') }}</x-core::status-pill>
                                    @endif
                                </div>

                                <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="introduced-by-{{ $introduction['id'] }}">
                                    {{ Lang::get('sync::devices.introduced_by', ['name' => $introduction['introduced_by']]) }}
                                </p>

                                {{-- Derived here from the key that arrived and this device's own,
                                     so it is a fingerprint the reader can compare rather than a
                                     claim the sender made about itself. --}}
                                @if ($introduction['safety_number_words'] !== '')
                                    @php
                                        $words = preg_split('/\s+/', trim((string) $introduction['safety_number_words'])) ?: [];
                                    @endphp
                                    <div
                                        class="flex flex-wrap gap-2"
                                        role="group"
                                        aria-label="{{ Lang::get('sync::devices.introduced_fingerprint') }} {{ strtoupper(implode(' ', $words)) }}"
                                    >
                                        @foreach ($words as $word)
                                            <span class="rounded bg-slate-100 px-2 py-1 font-mono text-sm uppercase tracking-wide text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $word }}</span>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($introduction['withheld'] > 0)
                                    <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="introduced-withheld-{{ $introduction['id'] }}">
                                        {{ Lang::choice('sync::devices.introduced_withheld', $introduction['withheld']) }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex flex-shrink-0 flex-col items-end gap-2">
                                @if (! $introduction['confirmed'])
                                    <x-core::neutral-button
                                        size="sm"
                                        class="min-h-[44px]"
                                        wire:click="confirmIntroduction('{{ $introduction['id'] }}')"
                                        data-testid="confirm-introduction-{{ $introduction['id'] }}"
                                    >
                                        {{ Lang::get('sync::devices.introduced_confirm') }}
                                    </x-core::neutral-button>
                                @endif

                                <button
                                    type="button"
                                    wire:click="dismissIntroduction('{{ $introduction['id'] }}')"
                                    aria-label="{{ Lang::get('sync::devices.introduced_dismiss_aria', ['name' => $introduction['name']]) }}"
                                    class="min-h-[44px] py-3 text-sm font-medium text-rose-600
                                           hover:text-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 rounded
                                           dark:text-rose-400 dark:hover:text-rose-300"
                                    data-testid="dismiss-introduction-{{ $introduction['id'] }}"
                                >
                                    {{ Lang::get('sync::devices.introduced_dismiss') }}
                                </button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
