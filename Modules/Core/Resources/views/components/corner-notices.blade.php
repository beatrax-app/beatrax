@use('Modules\Core\Public\Support\CornerNotices')
{{--
    The one surface anything pinned to the bottom-right corner is drawn on.
    Four boxes used to pin themselves there independently — the toast stack, the
    receipt-conflict prompt, and the dashboard's two standing notices — each
    counting its own gap from the same screen edge. Nothing stacked them, so the
    shortest drew over the tallest: the conflict prompt lost its title and its
    question and kept "future conflicts?", "Use receipt" and "Keep statement"
    showing. That press writes a preference every later conflict is resolved by.

    A flex column with a gap is the arrangement in which two occupants cannot be
    drawn at one address, so the question and the buttons that answer it move
    together or not at all. Occupants that are rendered elsewhere in the page
    reach it with @teleport(CornerNotices::TELEPORT_TARGET); this file is the
    only place in the tree allowed to pin to this corner, which
    NothingButTheRegionPinsItselfToTheCornerArchTest holds.

    The order utilities are the stack, bottom-up:
      order-1  a control that must be answered, nearest the corner and pinned
               there, so nothing arriving above can move it under a cursor
      order-2  a standing notice
      order-3  the transient toast stack, on top and last, so its own empty box
               spends its gap above everything rather than lifting the stack
--}}
<div
    id="{{ CornerNotices::REGION_ID }}"
    data-testid="corner-notices"
    {{-- pointer-events-none on the region and auto on each occupant: the gaps
         between the cards belong to the page underneath. --}}
    class="safe-lift pointer-events-none fixed bottom-4 right-4 z-[10000] flex w-[min(380px,calc(100vw-2rem))] flex-col-reverse gap-3"
>
    {{ $slot }}
    <x-core::toast-host />
</div>
