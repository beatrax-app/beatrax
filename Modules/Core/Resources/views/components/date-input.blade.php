@use('Modules\Core\Public\Support\Lang')
@use('Carbon\CarbonImmutable')
{{-- `fieldId` lands on the BUTTON, not the bound input: a <label for="…">
     must point at the thing a user actually clicks, and the bound input is
     sr-only. Callers that had `id` on their old <input type="date"> pass it
     here so their existing label keeps working. --}}
@props(['fieldId' => null, 'ariaLabel' => null])
@php
    // Every piece of language and format data comes from Carbon, for the
    // locale the app is currently rendering in. Carbon ships its own
    // translations, which is the point: the mobile build bundles ICU with
    // English-only data, so anything routed through ICU would come back
    // English — or throw, as Fmt::number() does for all 25 non-English
    // languages on that build.
    $anchor = CarbonImmutable::create(2026, 1, 5); // A Monday, so startOfWeek is a no-op shift.
    $weekStart = $anchor->startOfWeek();

    $monthNames = [];
    for ($m = 1; $m <= 12; $m++) {
        $monthNames[] = CarbonImmutable::create(2026, $m, 1)->isoFormat('MMMM');
    }

    $weekdayNames = [];
    for ($d = 0; $d < 7; $d++) {
        $weekdayNames[] = $weekStart->addDays($d)->isoFormat('dd');
    }

    // The short-date pattern this locale actually writes, e.g. DD-MM-YYYY for
    // Dutch, YYYY.MM.DD. for Hungarian, MM/DD/YYYY for English.
    $pattern = $anchor->getIsoFormats()['L'] ?? 'YYYY-MM-DD';

    // 0 = Sunday … 6 = Saturday, matching JS Date#getDay so the grid maths
    // needs no translation on the client.
    $firstDow = (int) $weekStart->dayOfWeek;
@endphp
{{--
    Localised date field.

    Replaces `<input type="date">`, which renders the engine's own picker in
    the SYSTEM locale and ignores the document language entirely — an English
    Sunday-first calendar inside a Dutch app, and dates read back as
    08/31/2026 next to € 1.234,56.

    The submitted value stays ISO `YYYY-MM-DD` on a real input, so wire:model,
    validation and form submission behave exactly as before. Only what the
    reader sees changed.
--}}
<div
    x-data="beatraxDatePicker({
        pattern: @js($pattern),
        months: @js($monthNames),
        weekdays: @js($weekdayNames),
        firstDow: @js($firstDow),
    })"
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
    {{ $attributes->only('class')->class(['relative']) }}
>
    {{-- The bound field. Kept in the DOM as a real input rather than a
         hidden one so wire:model, required and validation all still see it;
         sr-only keeps it reachable for assistive tech. --}}
    <input
        type="text"
        x-ref="field"
        x-model="value"
        class="sr-only"
        tabindex="-1"
        aria-hidden="true"
        {{ $attributes->except('class') }}
    />

    <button
        type="button"
        @if ($fieldId !== null) id="{{ $fieldId }}" @endif
        x-on:click="open = ! open"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="dialog"
        aria-label="{{ $ariaLabel ?? Lang::get('core::components.date.open') }}"
        class="flex w-full items-center justify-between rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
    >
        <span x-text="display || '—'"></span>
        <span aria-hidden="true" class="text-slate-400">▦</span>
    </button>

    <div
        x-show="open"
        x-cloak
        role="dialog"
        aria-modal="false"
        aria-label="{{ Lang::get('core::components.date.open') }}"
        class="absolute z-30 mt-1 w-72 rounded-lg border border-slate-200 bg-white p-3 shadow-lg dark:border-slate-700 dark:bg-slate-950"
    >
        <div class="flex items-center justify-between">
            <button
                type="button"
                x-on:click="shiftMonth(-1)"
                aria-label="{{ Lang::get('core::components.date.prev_month') }}"
                class="rounded px-2 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-900"
            >‹</button>
            <span class="text-sm font-medium text-slate-900 dark:text-slate-100" x-text="monthLabel"></span>
            <button
                type="button"
                x-on:click="shiftMonth(1)"
                aria-label="{{ Lang::get('core::components.date.next_month') }}"
                class="rounded px-2 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-900"
            >›</button>
        </div>

        <div class="mt-2 grid grid-cols-7 gap-0.5 text-center text-[11px] text-slate-400">
            <template x-for="name in weekdayLabels" :key="name">
                <span x-text="name"></span>
            </template>
        </div>

        <div class="mt-1 grid grid-cols-7 gap-0.5">
            <template x-for="cell in days" :key="cell.iso">
                <button
                    type="button"
                    x-on:click="choose(cell.iso)"
                    x-text="cell.day"
                    x-bind:aria-current="isToday(cell.iso) ? 'date' : null"
                    x-bind:aria-pressed="isSelected(cell.iso) ? 'true' : 'false'"
                    class="rounded py-1 text-sm"
                    x-bind:class="{
                        'text-slate-300 dark:text-slate-600': cell.outside,
                        'text-slate-900 dark:text-slate-100': ! cell.outside,
                        'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900': isSelected(cell.iso),
                        'ring-1 ring-slate-300 dark:ring-slate-600': isToday(cell.iso) && ! isSelected(cell.iso),
                    }"
                ></button>
            </template>
        </div>

        <div class="mt-2 flex items-center justify-between">
            <button
                type="button"
                x-on:click="chooseToday()"
                class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-900"
            >{{ Lang::get('core::components.date.today') }}</button>
            <button
                type="button"
                x-on:click="clear()"
                class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-900"
            >{{ Lang::get('core::components.date.clear') }}</button>
        </div>
    </div>
</div>
