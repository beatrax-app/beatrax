@use('Modules\Core\Public\Support\Lang')
@use('Carbon\CarbonImmutable')
{{-- `fieldId` lands on the BUTTON, so an existing <label for="…"> keeps
     working — same contract as x-core::date-input. --}}
@props(['fieldId' => null, 'ariaLabel' => null, 'minuteStep' => 5])
@php
    // Carbon, not ICU: the mobile build bundles ICU with English-only data, so
    // an ICU-routed formatter answers in English or throws.
    $anchor = CarbonImmutable::create(2026, 1, 5, 21, 30);
    $pattern = $anchor->getIsoFormats()['LT'] ?? 'HH:mm';

    // The whole reason this component exists. Every European language the
    // product ships writes HH:mm; English writes h:mm A. The native control
    // picks that from the SYSTEM locale instead, which is how a 12-hour AM/PM
    // picker ended up inside a Dutch UI.
    $twelveHour = str_contains($pattern, 'A') || str_contains($pattern, 'a');

    $meridiem = [
        CarbonImmutable::create(2026, 1, 5, 9, 0)->isoFormat('A'),
        CarbonImmutable::create(2026, 1, 5, 21, 0)->isoFormat('A'),
    ];

    $fieldLabel = $ariaLabel ?? Lang::get('core::components.time.open');
    $emptyLabel = Lang::get('core::components.time.empty');

    $bindings = $attributes->whereStartsWith('wire:');
    $passthrough = $attributes->whereDoesntStartWith('wire:')->except('class');
@endphp
<div
    x-data="beatraxTimePicker({
        twelveHour: @js($twelveHour),
        meridiem: @js($meridiem),
        minuteStep: @js((int) $minuteStep),
    })"
    x-modelable="value"
    {{ $bindings }}
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
    {{ $attributes->only('class')->class(['bx-date']) }}
>
    {{-- The chosen time is part of the field's NAME — same reasoning, and the
         same trap, as x-core::date-input. --}}
    <button
        type="button"
        @if ($fieldId !== null) id="{{ $fieldId }}" aria-labelledby="{{ $fieldId }}-name {{ $fieldId }}-value" @endif
        x-on:click="open = ! open"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="dialog"
        {{ $passthrough }}
        class="flex w-full items-center justify-between rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
    >
        <span @if ($fieldId !== null) id="{{ $fieldId }}-name" @endif class="sr-only">{{ $fieldLabel }}</span>
        <span @if ($fieldId !== null) id="{{ $fieldId }}-value" @endif class="sr-only" x-text="display || @js($emptyLabel)">{{ $emptyLabel }}</span>
        <span aria-hidden="true" x-text="display || '—'">—</span>
        <span aria-hidden="true" class="text-slate-400">◷</span>
    </button>

    <div x-show="open" x-cloak role="dialog" aria-label="{{ Lang::get('core::components.time.open') }}" class="bx-date-pop">
        <div class="flex gap-2">
            {{-- Two scrolling columns rather than a grid: hours and minutes are
                 independent, and a 24x12 grid is unreadable on a phone.

                 Each option below writes aria-selected twice: the literal is
                 the row's state before Alpine has evaluated anything, and the
                 x-bind replaces it with the real answer the moment the row is
                 cloned. Only the literal is visible to a reader of this file —
                 static analysis included — and an option whose selected state
                 is announced only after hydration is not announced at all if
                 hydration never happens. --}}
            <div class="flex-1">
                <p class="mb-1 text-[11px] uppercase tracking-wide text-slate-400">{{ Lang::get('core::components.time.hour') }}</p>
                <div class="max-h-40 overflow-y-auto" role="listbox" aria-label="{{ Lang::get('core::components.time.hour') }}">
                    <template x-for="h in hours" :key="h.label">
                        <button
                            type="button"
                            x-on:click="chooseHour(h.hour24)"
                            x-text="h.label"
                            aria-selected="false"
                            x-bind:aria-selected="isHour(h.hour24) ? 'true' : 'false'"
                            role="option"
                            class="block w-full rounded px-2 py-1 text-left text-sm tabular-nums"
                            x-bind:class="isHour(h.hour24)
                                ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
                                : 'text-slate-900 dark:text-slate-100'"
                        ></button>
                    </template>
                </div>
            </div>

            <div class="flex-1">
                <p class="mb-1 text-[11px] uppercase tracking-wide text-slate-400">{{ Lang::get('core::components.time.minute') }}</p>
                <div class="max-h-40 overflow-y-auto" role="listbox" aria-label="{{ Lang::get('core::components.time.minute') }}">
                    <template x-for="m in minutes" :key="m">
                        <button
                            type="button"
                            x-on:click="chooseMinute(m)"
                            x-text="m"
                            aria-selected="false"
                            x-bind:aria-selected="isMinute(m) ? 'true' : 'false'"
                            role="option"
                            class="block w-full rounded px-2 py-1 text-left text-sm tabular-nums"
                            x-bind:class="isMinute(m)
                                ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
                                : 'text-slate-900 dark:text-slate-100'"
                        ></button>
                    </template>
                </div>
            </div>
        </div>

        {{-- Only where the locale actually reads 12-hour. Rendering an AM/PM
             toggle in a 24-hour language would import the very confusion this
             component exists to remove. --}}
        @if ($twelveHour)
            <div class="mt-2 flex gap-1">
                <button type="button" x-on:click="setMeridiem(false)" x-text="@js($meridiem[0])"
                    x-bind:class="! isPm ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 dark:text-slate-300'"
                    class="flex-1 rounded px-2 py-1 text-xs"></button>
                <button type="button" x-on:click="setMeridiem(true)" x-text="@js($meridiem[1])"
                    x-bind:class="isPm ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 dark:text-slate-300'"
                    class="flex-1 rounded px-2 py-1 text-xs"></button>
            </div>
        @endif

        <div class="mt-2 flex justify-end">
            <button type="button" x-on:click="clear()" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-900">
                {{ Lang::get('core::components.date.clear') }}
            </button>
        </div>
    </div>
</div>
