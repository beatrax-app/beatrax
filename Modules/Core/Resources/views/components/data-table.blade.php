@props([
    'scroll' => true,   // false clips instead of scrolling. Only for a table a narrow screen already restacks.
])

{{--
    The bordered frame, the table, the header row and the striped body every
    list screen draws its rows in. `head` is the <tr> of column headers, the
    default slot is the <tr>s under it, and the optional `foot` slot is the
    totals row only the report builder has.

    Seven of these were written out by hand — twenty-eight elements — and four
    of the five class strings had drifted. The wrapper came out `overflow-x-auto
    rounded-lg border …` four times, `overflow-hidden rounded-lg …` twice and
    `overflow-x-auto rounded-md …` once, so rounded-lg wins and the one rounded
    corner nobody chose goes with it. The table carried `text-sm` five times of
    seven. <thead> and <tbody> never varied at all, which is the clearest sign
    they did not need to be written seven times.

    `scroll` is a prop rather than a merged class because its two values are
    not decoration: overflow-hidden CLIPS the right-hand columns on a phone
    instead of letting them scroll, and only a table that has already restacked
    below 768px can afford it. Both sites that pass false earn it that way, and
    two overflow utilities on one element would leave stylesheet order to
    decide which applied.

    The attribute bag lands on the <table>, because that is what the call sites
    actually vary: `rules-table`, `triage-inbox-table` and `dash-recent-table`
    are the hooks app.css restacks those rows with, and the import preview's
    tabular-figures style rides along the same way.
--}}

<div class="{{ $scroll ? 'overflow-x-auto' : 'overflow-hidden' }} rounded-lg border border-slate-200 dark:border-slate-700">
    <table {{ $attributes->merge(['class' => 'min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700']) }}>
        <thead class="bg-slate-50 dark:bg-slate-900">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white dark:bg-slate-950 dark:divide-slate-700">
            {{ $slot }}
        </tbody>
        @isset($foot)
            <tfoot>
                <tr>{{ $foot }}</tr>
            </tfoot>
        @endisset
    </table>
</div>
