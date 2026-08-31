@props(['money' => null])

{{--
    The second amount an FX row carries: the reader picked "original amount",
    so the headline is what the merchant charged and this line is what actually
    left the account. Both renderings of the list need it — it existed only in
    the desktop table, so on a phone a $5.99 charge never said it settled at
    €5.51 and the mode's whole point was missing at that width.

    Null when the row is not FX (native and settled agree), which is most of
    them, so the caller passes the value rather than testing it twice.
--}}
@if ($money !== null)
    <span {{ $attributes->merge(['class' => 'mt-1 block text-xs text-slate-500 dark:text-slate-400']) }} data-secondary-amount>{{ $money->format() }}</span>
@endif
