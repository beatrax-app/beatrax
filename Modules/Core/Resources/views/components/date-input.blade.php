@use('Modules\Core\Public\Support\Lang')
@use('Carbon\CarbonImmutable')
@use('Modules\Core\Public\Support\Fmt')
{{-- `fieldId` lands on the BUTTON: a <label for="…"> must point at the thing a
     user actually clicks. Callers that had `id` on their old
     `<input type="date">` pass it here so their existing label keeps
     working. --}}
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

    // The short-date pattern this locale writes, e.g. DD-MM-YYYY for Dutch and
    // YYYY.MM.DD. for Hungarian — through Fmt, which corrects the month-first
    // ones so the field and every list agree AND read day-first.
    $pattern = Fmt::datePattern();

    // 0 = Sunday … 6 = Saturday, matching JS Date#getDay so the grid maths
    // needs no translation on the client.
    $firstDow = (int) $weekStart->dayOfWeek;

    $fieldLabel = $ariaLabel ?? Lang::get('core::components.date.open');
    $emptyLabel = Lang::get('core::components.date.empty');

    // The Livewire binding goes on the ROOT, where `x-modelable` can hand it
    // straight to the component's own `value`. Everything else — aria-invalid,
    // aria-describedby, data-* — belongs on the button, which is the element a
    // reader actually focuses and a label actually points at.
    $bindings = $attributes->whereStartsWith('wire:');
    $passthrough = $attributes->whereDoesntStartWith('wire:')->except('class');
@endphp
{{--
    Localised date field.

    Replaces `<input type="date">`, which renders the engine's own picker in
    the SYSTEM locale and ignores the document language entirely — an English
    Sunday-first calendar inside a Dutch app, and dates read back as
    08/31/2026 next to € 1.234,56.

    The value handed to and taken from Livewire stays ISO `YYYY-MM-DD`, so
    wire:model, validation and every server-side rule behave exactly as they
    did with the native control. Only what the reader sees changed.

    `x-modelable` is what carries it. The caller's `wire:model` is applied to
    this root by Livewire as an Alpine `x-model` (that is literally how the
    directive is implemented), and `x-modelable` entangles that with the
    `value` property below — two-way, in both directions, with no hidden input
    to keep in step. The earlier shape put `wire:model` on an `sr-only` input
    that ALSO carried `x-model="value"`, which meant two bindings owning one
    element: Alpine's copy, still empty at that point, won the race and wiped
    the server's value out of the field on every render.
--}}
<div
    x-data="beatraxDatePicker({
        pattern: @js($pattern),
        months: @js($monthNames),
        weekdays: @js($weekdayNames),
        firstDow: @js($firstDow),
    })"
    x-modelable="value"
    {{ $bindings }}
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
    {{ $attributes->only('class')->class(['relative']) }}
>
    {{-- The chosen date is part of the field's NAME, not only its pixels. An
         aria-label outranks both the button's own text and a <label for="…">
         pointing at it, so the static one this used to carry announced
         "Choose a date" over a field reading 31-12-2026. The two sr-only spans
         below are the name instead; `aria-labelledby` is what beats the label,
         and where there is no field id there is no label to beat, so the same
         two spans become the name by the ordinary name-from-content rule. --}}
    <button
        type="button"
        @if ($fieldId !== null) id="{{ $fieldId }}" aria-labelledby="{{ $fieldId }}-name {{ $fieldId }}-value" @endif
        x-on:click="toggle()"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="dialog"
        {{ $passthrough }}
        class="flex w-full flex-wrap items-center justify-between gap-x-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
    >
        <span @if ($fieldId !== null) id="{{ $fieldId }}-name" @endif class="sr-only">{{ $fieldLabel }}</span>
        <span @if ($fieldId !== null) id="{{ $fieldId }}-value" @endif class="sr-only" x-text="display || @js($emptyLabel)">{{ $emptyLabel }}</span>
        <span aria-hidden="true" x-text="display || '—'">—</span>
        <span aria-hidden="true" class="text-slate-400">▦</span>
    </button>

    <div
        x-show="open"
        x-cloak
        role="dialog"
        aria-modal="false"
        {{-- Named after the field, not after the control kind: a toolbar with a
             "from" and a "to" opened two dialogs called "Choose a date", so
             nothing said which of the two was on screen. --}}
        aria-label="{{ $fieldLabel }}"
        {{-- max-w keeps the calendar inside the viewport when the field sits
             near the right edge of a narrow screen; the compact search
             toolbar is the case that finds it. `bx-date-pop` is what turns it
             into a centred dialog below 768px — see app.css for why it cannot
             stay a popover there. --}}
        class="bx-date-pop absolute left-0 z-40 mt-1 w-72 max-w-[calc(100vw-2rem)] rounded-lg border border-slate-200 bg-white p-3 shadow-lg dark:border-slate-700 dark:bg-slate-950"
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
