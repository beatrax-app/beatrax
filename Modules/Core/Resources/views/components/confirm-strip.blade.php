@props([
    'question',              // Required. The sentence the two buttons answer.
    'cancelLabel',           // Required. The way out.
    'confirmLabel',          // Required. The way through with it.
    'cancel',                // Required. Livewire call that backs out, e.g. "cancelDelete".
    'confirm',               // Required. Livewire call that goes through, e.g. "delete(12)".
    'confirmAria' => null,   // Names the confirm button where a list of rows makes its label ambiguous.
    'tag' => 'div',          // 'li' inside a <ul>, 'td' inside a table row, 'div' anywhere else.
])

{{--
    The inline "are you sure?" strip a destructive row action swaps itself for:
    the question on the left, the way out and the way through on the right.

    Ten of these existed across six modules and they agreed on nothing. The
    wrapper was a <li>, a <div> or a <td>; the confirm affordance was a solid
    rose button in two places, a plain text link in two more, an underlined
    link in a fifth and an inline style= span in the reports table; some were
    text-xs and some text-sm. Half of them put the destructive verb first, so
    the button nearest the cursor was the one that could not be undone.

    Hence the two rules this holds: cancel first and confirm last, and the
    confirm is a rose *text* button rather than a filled block — a solid
    destructive slab is too loud for something that lives inside a row.

    `tag` keeps the wrapper legal wherever the strip lands, since a strip
    inside a <ul> may not be a <div> and one that stands in for a table cell
    must be a <td>.

    Every string is a prop: this component owns no copy, so each site keeps
    the words it already had.
--}}
<{{ $tag }} {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-md bg-slate-50 px-3 py-2 dark:bg-slate-900']) }}>
    <p class="flex-1 text-sm text-slate-700 dark:text-slate-300">{{ $question }}</p>
    <button
        type="button"
        wire:click="{{ $cancel }}"
        class="text-sm text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
    >{{ $cancelLabel }}</button>
    <button
        type="button"
        wire:click="{{ $confirm }}"
        @if ($confirmAria !== null) aria-label="{{ $confirmAria }}" @endif
        class="text-sm font-medium text-rose-600 hover:text-rose-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 dark:text-rose-400 dark:hover:text-rose-200"
    >{{ $confirmLabel }}</button>
</{{ $tag }}>
